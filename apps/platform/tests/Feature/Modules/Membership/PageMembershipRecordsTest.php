<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Application\PageMembershipRecords;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

/*
 * PageMembershipRecords (Work Package 5): the admin list. One record per distinct Person who has EVER held a grant,
 * whether or not any grant is currently active, a bounded page at a time. It is the only list use case: an earlier
 * unpaged `ListMembershipRecords` loaded the whole table and had no production caller, so it was removed.
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

it('does not omit a Person whose only grant is now revoked: history is not destroyed', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);
    app(MembershipGrantRepository::class)->revoke($grant->id, $viewer->id, Identity::now()->modify('+1 hour'));

    $page = app(PageMembershipRecords::class)(Access::actorFor($viewer), 1, 25);
    $match = array_values(array_filter($page->records, fn (MembershipRecord $r): bool => $r->personId->equals($person->id)));

    expect($match)->toHaveCount(1)
        ->and($match[0]->active)->toBeFalse()
        ->and($match[0]->grants)->toHaveCount(1);
});

it('does not list a Person who has never held a grant', function () {
    $viewer = Access::admin('viewer@example.org');
    $untouched = Identity::savedPerson('No Grants');

    $page = app(PageMembershipRecords::class)(Access::actorFor($viewer), 1, 25);

    $ids = array_map(fn (MembershipRecord $r): string => $r->personId->value, $page->records);
    expect($ids)->not->toContain($untouched->id->value);
});

it('reports each Person\'s grant history in full, not just the active grant', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    Membership::savedGrant($person->id, Identity::now(), null);

    $page = app(PageMembershipRecords::class)(Access::actorFor($viewer), 1, 25);
    $match = array_values(array_filter($page->records, fn (MembershipRecord $r): bool => $r->personId->equals($person->id)));

    expect($match)->toHaveCount(1)
        ->and($match[0]->grants)->toHaveCount(2)
        ->and($match[0]->active)->toBeTrue()
        ->and($match[0]->openEnded)->toBeTrue();
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
