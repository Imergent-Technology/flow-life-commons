<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Identity\Application\PersonExists;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipState;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * One Person's membership state and complete grant history, for the operator's member-detail view
 * (`GET /admin/members/{person}`). Read-only: no lock, no audit event, no state change.
 */
final readonly class GetMembershipRecord
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private MembershipGrantRepository $grants,
    ) {}

    /**
     * @throws AccessDenied the Actor may not view membership records
     * @throws UnknownPerson there is no such Person
     */
    public function __invoke(Actor $actor, PersonId $person, ?DateTimeImmutable $at = null): MembershipRecord
    {
        ($this->authorize)($actor, Capability::ViewMembershipRecords);

        if (! ($this->personExists)($person)) {
            throw new UnknownPerson;
        }

        $grants = $this->grants->forPerson($person);
        $state = MembershipState::at($grants, $at ?? DateTimeImmutable::createFromInterface(now()));

        return new MembershipRecord($person, $state->active, $state->currentAccessEndsAt, $state->openEnded, $grants);
    }
}
