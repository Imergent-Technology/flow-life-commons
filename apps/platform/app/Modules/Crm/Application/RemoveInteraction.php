<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\InteractionId;
use App\Modules\Crm\Domain\InteractionRepository;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/**
 * Removes a note or interaction outright. Needs `crm.people.manage`. There is no soft delete and no history (the same
 * convention as removing a contact method), and nothing is written to the security audit trail: CRM business content is
 * not security events (ADR 0034).
 */
final readonly class RemoveInteraction
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private InteractionRepository $interactions,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws InteractionNotFound the interaction does not exist, or belongs to another Person
     */
    public function __invoke(Actor $actor, PersonId $personId, InteractionId $id): void
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        if (! $this->interactions->remove($personId, $id)) {
            throw new InteractionNotFound;
        }
    }
}
