<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\InvalidRelationshipField;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Modules\Relationships\Domain\UnknownRelationshipField;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;

/**
 * Edits a relationship's own metadata (ADR 0038, F7, F10). Partial: a missing key is left alone
 * and null clears it. The field always belongs to the stored type, which is the route's type.
 * An edit that changes nothing does not move the revision.
 */
final readonly class UpdateRelationshipFields
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound
     * @throws StaleRelationshipRevision
     * @throws UnknownRelationshipField
     * @throws InvalidRelationshipField
     * @throws InvalidRelationshipQuery
     */
    public function __invoke(
        Actor $actor,
        RelationshipType $type,
        PersonId $person,
        RelationshipId $id,
        int $revision,
        mixed $fields,
    ): RelationshipManagementView {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);

        return $this->database->transaction(function () use ($actor, $type, $person, $id, $revision, $fields, $definition): RelationshipManagementView {
            $current = $this->relationships->lock($person, $type->key) ?? throw new RelationshipNotFound;
            $view = $this->presentation->management($definition, $current);
            if (! $current->id->equals($id) || $current->revision !== $revision) {
                throw new StaleRelationshipRevision($view);
            }
            if (! is_array($fields)) {
                throw new InvalidRelationshipQuery('fields', 'fields must be an object.');
            }

            $parsed = FieldValues::parse($definition, $fields, RelationshipTime::now());
            $next = $current->fields;
            $written = [];
            $cleared = [];
            foreach ($parsed as $key => $value) {
                if ($value === null) {
                    if ($definition->field($key)?->required === true) {
                        throw new InvalidRelationshipField($key);
                    }
                    if (array_key_exists($key, $next)) {
                        unset($next[$key]);
                        $cleared[] = $key;
                    }

                    continue;
                }
                if (($next[$key] ?? null) !== $value) {
                    $written[$key] = $value;
                }
                $next[$key] = $value;
            }
            $missing = FieldValues::missingRequired($definition, $next);
            if ($missing !== null) {
                throw new InvalidRelationshipField($missing);
            }
            ksort($next);
            if ($written === [] && $cleared === []) {
                return $view;
            }

            $now = RelationshipTime::now();
            $updated = $current->withFields($next, $actor->personId, $now);
            if (! $this->relationships->saveFields($updated, $revision, $written, $cleared, $actor->personId, $now)) {
                throw new StaleRelationshipRevision($view);
            }

            return $this->presentation->management($definition, $updated);
        }, 3);
    }
}
