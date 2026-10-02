<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\Interaction;
use App\Modules\Crm\Domain\InteractionId;
use App\Modules\Crm\Domain\InteractionRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Records a note or interaction about a Person, authored by the caller. Needs `crm.people.manage`, and nothing more.
 * It is CRM business data and writes nothing to the security audit trail (ADR 0034).
 */
final readonly class RecordInteraction
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private InteractionRepository $interactions,
        private InteractionViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws InvalidContactInput
     */
    public function __invoke(Actor $actor, PersonId $personId, NewInteraction $new): InteractionView
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        $interaction = Interaction::record(
            InteractionId::generate(), $personId, $new->kind, $new->body, $new->occurredAt, $actor->personId, DateTimeImmutable::createFromInterface(now()),
        );
        $this->interactions->add($interaction);

        return $this->views->of([$interaction])[0];
    }
}
