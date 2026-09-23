<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Application\PageMembershipRecords;
use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

/*
 * PageMembershipRecords (Work Package 5): the admin list's own pagination, layered on top of
 * ListMembershipRecords's "one record per distinct Person who has ever held a grant" rule
 * without changing it.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('pages by distinct Person, not by grant row', function () {
    $viewer = Access::admin('viewer@example.org');
    foreach (range(1, 5) as $n) {
        $person = Identity::savedPerson('Person '.$n);
        Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
        Membership::savedGrant($person->id, Identity::now(), null);
    }

    $page = app(PageMembershipRecords::class)(Access::actorFor($viewer), 1, 2);

    expect($page->records)->toHaveCount(2)
        ->and($page->total)->toBe(5)
        ->and($page->lastPage())->toBe(3)
        ->and($page->records[0]->grants)->toHaveCount(2);
});

it('does not omit a Person whose only grants are revoked or expired', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));

    $page = app(PageMembershipRecords::class)(Access::actorFor($viewer), 1, 25);

    $ids = array_map(fn (MembershipRecord $r): string => $r->personId->value, $page->records);
    expect($ids)->toContain($person->id->value);
});

it('clamps page and per_page to sane bounds', function () {
    $viewer = Access::admin('viewer@example.org');
    Membership::savedGrant(Identity::savedPerson()->id);

    $page = app(PageMembershipRecords::class)(Access::actorFor($viewer), 0, 1000);

    expect($page->page)->toBe(1)
        ->and($page->perPage)->toBe(PageMembershipRecords::MAX_PER_PAGE);
});

it('denies an actor without membership.records.view', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);

    expect(fn () => app(PageMembershipRecords::class)(Access::actorFor($guardian), 1, 25))
        ->toThrow(AccessDenied::class);
});
