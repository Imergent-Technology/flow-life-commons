<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\InvalidRelationshipField;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Modules\Relationships\Domain\StatusChange;
use App\Modules\Relationships\Domain\UnknownRelationshipField;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records a relationship for an existing Person (ADR 0038, F9, P3). One capability: the type's manage
 * capability, from the definition. Creating a new Person is WP3. A default-role decision is refused
 * until a type has one (WP2B).
 */
final readonly class EstablishRelationship
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private FindPeople $people,
        private RelationshipPresentation $presentation,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  array<mixed, mixed>  $fields
     *
     * @throws AccessDenied
     * @throws ConfirmationRequired
     * @throws DefaultRoleNotApplicable
     * @throws TransitionNotAllowed
     * @throws UnknownRelationshipField
     * @throws InvalidRelationshipField
     * @throws UnknownRelationshipPerson
     * @throws RelationshipExists
     */
    public function __invoke(
        Actor $actor,
        RelationshipType $type,
        PersonId $person,
        bool $confirmExistingPerson,
        string $status,
        array $fields,
        bool $defaultRoleSent,
    ): RelationshipManagementView {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);

        if (! $confirmExistingPerson) {
            throw new ConfirmationRequired;
        }
        if ($defaultRoleSent) {
            throw new DefaultRoleNotApplicable;
        }
        if (! $definition->isInitial($status)) {
            throw new TransitionNotAllowed;
        }
        if (($this->people)([$person]) === []) {
            throw new UnknownRelationshipPerson;
        }

        $now = RelationshipTime::now();
        $parsed = FieldValues::parse($definition, $fields, $now);
        $stored = [];
        foreach ($parsed as $key => $value) {
            if ($value === null) {
                throw new InvalidRelationshipField($key);
            }
            $stored[$key] = $value;
        }
        $missing = FieldValues::missingRequired($definition, $stored);
        if ($missing !== null) {
            throw new InvalidRelationshipField($missing);
        }

        try {
            // A missing-row lock under repeatable read covers the gap beside that key, so recognizing
            // a Guardian would block recording a Volunteer for the same Person. Read committed locks
            // only a row that exists; the unique index still serializes two intakes of one type.
            if ($this->database->transactionLevel() === 0 && $this->driver() !== 'pgsql') {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            return $this->database->transaction(function () use ($actor, $type, $person, $status, $stored, $now, $definition): RelationshipManagementView {
                if ($this->driver() === 'pgsql' && $this->database->transactionLevel() === 1) {
                    $this->database->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                if ($this->relationships->lock($person, $type->key) !== null) {
                    throw new RelationshipExists;
                }
                $record = new RelationshipRecord(
                    RelationshipId::generate(), $person, $type->key, $status, 1,
                    $now, $actor->personId, $now, $actor->personId, $now, $actor->personId,
                    $stored, [new StatusChange(null, $status, $now, $actor->personId)],
                );
                $this->relationships->add($record);

                return $this->presentation->management($definition, $record);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new RelationshipExists;
        }
    }

    private function driver(): string
    {
        $connection = config('database.default');
        if (! is_string($connection)) {
            return '';
        }
        $driver = config("database.connections.{$connection}.driver");

        return is_string($driver) ? $driver : '';
    }
}
