<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipState;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * Every Person who holds at least one membership grant, for the future admin surface.
 *
 * **List semantics.** One MembershipRecord per distinct Person who has EVER held a grant,
 * ordered by PersonId, regardless of whether any of their grants are currently active: a
 * Person whose only grants are now expired or revoked is not omitted, because this groups the
 * rows themselves rather than filtering by a derived "currently active" projection of them.
 * There is deliberately no "member since" concept, no pagination and no search filter here —
 * this is the minimum backend query the future admin surface needs; paging and filtering are
 * that surface's own concern (Package 5), layered on top of this without changing it.
 */
final readonly class ListMembershipRecords
{
    public function __construct(
        private AuthorizeAction $authorize,
        private MembershipGrantRepository $grants,
    ) {}

    /**
     * @return list<MembershipRecord>
     *
     * @throws AccessDenied the Actor may not view membership records
     */
    public function __invoke(Actor $actor, ?DateTimeImmutable $at = null): array
    {
        ($this->authorize)($actor, Capability::ViewMembershipRecords);

        $instant = $at ?? DateTimeImmutable::createFromInterface(now());

        /** @var array<string, list<MembershipGrant>> $byPerson */
        $byPerson = [];
        foreach ($this->grants->all() as $grant) {
            $byPerson[$grant->personId->value][] = $grant;
        }

        $records = [];
        foreach ($byPerson as $personGrants) {
            $state = MembershipState::at($personGrants, $instant);
            $records[] = new MembershipRecord($personGrants[0]->personId, $state->active, $state->currentAccessEndsAt, $state->openEnded, $personGrants);
        }

        return $records;
    }
}
