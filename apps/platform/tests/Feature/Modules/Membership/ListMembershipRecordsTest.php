<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Membership\Application\ListMembershipRecords;
use App\Modules\Membership\Application\MembershipRecord;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('lists every Person who holds at least one grant', function () {
    $viewer = Access::admin('viewer@example.org');
    $active = Identity::savedPerson('Active Member');
    $lapsed = Identity::savedPerson('Lapsed Member');
    Membership::savedGrant($active->id, Identity::now(), null);
    Membership::savedGrant($lapsed->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));

    $records = app(ListMembershipRecords::class)(Access::actorFor($viewer));

    $ids = array_map(fn (MembershipRecord $r): string => $r->personId->value, $records);
    expect($ids)->toContain($active->id->value)->toContain($lapsed->id->value);
});

it('does not omit a Person whose only grant is now revoked: history is not destroyed', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);
    app(MembershipGrantRepository::class)->revoke($grant->id, $viewer->id, Identity::now()->modify('+1 hour'));

    $records = app(ListMembershipRecords::class)(Access::actorFor($viewer));

    $ids = array_map(fn (MembershipRecord $r): string => $r->personId->value, $records);
    expect($ids)->toContain($person->id->value);

    $match = array_values(array_filter($records, fn (MembershipRecord $r): bool => $r->personId->equals($person->id)));
    expect($match)->toHaveCount(1)
        ->and($match[0]->active)->toBeFalse()
        ->and($match[0]->grants)->toHaveCount(1);
});

it('does not list a Person who has never held a grant', function () {
    $viewer = Access::admin('viewer@example.org');
    $untouched = Identity::savedPerson('No Grants');

    $records = app(ListMembershipRecords::class)(Access::actorFor($viewer));

    $ids = array_map(fn (MembershipRecord $r): string => $r->personId->value, $records);
    expect($ids)->not->toContain($untouched->id->value);
});

it('reports each grant history in full, not just the active grant', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    Membership::savedGrant($person->id, Identity::now(), null);

    $records = app(ListMembershipRecords::class)(Access::actorFor($viewer));
    $match = array_values(array_filter($records, fn (MembershipRecord $r): bool => $r->personId->equals($person->id)));

    expect($match)->toHaveCount(1)
        ->and($match[0]->grants)->toHaveCount(2)
        ->and($match[0]->active)->toBeTrue()
        ->and($match[0]->openEnded)->toBeTrue();
});

it('denies an actor without membership.records.view', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);

    expect(fn () => app(ListMembershipRecords::class)(Access::actorFor($guardian)))->toThrow(AccessDenied::class);
});
