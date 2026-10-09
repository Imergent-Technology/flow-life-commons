<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/** The manage-only read (ADR 0038, A4, F13). It does not grant the view capability's history. */
final readonly class ReadRelationshipManagement
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound
     */
    public function __invoke(Actor $actor, RelationshipType $type, PersonId $person): RelationshipManagementView
    {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);
        $record = $this->relationships->find($person, $type->key) ?? throw new RelationshipNotFound;

        return $this->presentation->management($definition, $record);
    }
}
