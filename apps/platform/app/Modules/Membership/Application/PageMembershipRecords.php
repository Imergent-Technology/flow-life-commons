<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipState;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * A modest, bounded page of the admin membership list: one record per distinct Person who has EVER
 * held a grant, whether or not any grant is currently active, so a Person whose only grants are now
 * expired or revoked is not omitted. Pagination narrows how many Persons come back, never which ones
 * exist. There is no "member since", and no search filter yet (Package 5 deferred it rather than reach
 * into Identity's `people` table for a `display_name` fragment match).
 */
final readonly class PageMembershipRecords
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private AuthorizeAction $authorize,
        private MembershipGrantRepository $grants,
    ) {}

    /**
     * @throws AccessDenied the Actor may not view membership records
     */
    public function __invoke(Actor $actor, int $page, int $perPage, ?DateTimeImmutable $at = null): MembershipRecordsPage
    {
        ($this->authorize)($actor, Capability::ViewMembershipRecords);

        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $instant = $at ?? DateTimeImmutable::createFromInterface(now());

        ['personIds' => $personIds, 'total' => $total] = $this->grants->personIdsPage($page, $perPage);
        $byPerson = $this->grants->forPeople($personIds);

        $records = [];
        foreach ($personIds as $personId) {
            $personGrants = $byPerson[$personId->value] ?? [];
            $state = MembershipState::at($personGrants, $instant);
            $records[] = new MembershipRecord($personId, $state->active, $state->currentAccessEndsAt, $state->openEnded, $personGrants);
        }

        return new MembershipRecordsPage($records, $page, $perPage, $total);
    }
}
