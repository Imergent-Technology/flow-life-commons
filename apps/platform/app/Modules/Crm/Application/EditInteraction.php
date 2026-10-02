<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\InteractionId;
use App\Modules\Crm\Domain\InteractionKind;
use App\Modules\Crm\Domain\InteractionRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Corrects a note or interaction in place. Needs `crm.people.manage`: anyone who may manage People may correct any note
 * (Phase 1 has no per-author ownership), and the row records who last changed it and when. There is no edit history.
 * A field not in `$changes` is left alone; the author and the Person never change.
 */
final readonly class EditInteraction
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private InteractionRepository $interactions,
        private InteractionViews $views,
    ) {}

    /**
     * @param  array{kind?: InteractionKind, body?: string, occurred_at?: DateTimeImmutable}  $changes
     *
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws InteractionNotFound the interaction does not exist, or belongs to another Person
     * @throws InvalidContactInput
     */
    public function __invoke(Actor $actor, PersonId $personId, InteractionId $id, array $changes): InteractionView
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        $interaction = $this->interactions->find($id);
        if ($interaction === null || ! $interaction->personId->equals($personId)) {
            throw new InteractionNotFound;
        }

        $this->interactions->save($interaction->with($changes, $actor->personId, DateTimeImmutable::createFromInterface(now())));

        // Re-read rather than trusting an affected-row count (MariaDB reports unchanged rows as 0): a note removed
        // between the two steps is reported as gone, never returned as though it still existed.
        $current = $this->interactions->find($id) ?? throw new InteractionNotFound;

        return $this->views->of([$current])[0];
    }
}
