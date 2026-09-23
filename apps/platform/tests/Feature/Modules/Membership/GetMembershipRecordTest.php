<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Membership\Application\GetMembershipRecord;
use App\Modules\Membership\Application\UnknownPerson;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('reports an active Person with their current access-through', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson('Mia Member');
    Membership::savedGrant($person->id, Identity::now()->modify('-1 month'), Identity::now()->modify('+11 months'));

    $record = app(GetMembershipRecord::class)(Access::actorFor($viewer), $person->id);

    expect($record->personId)->toEqual($person->id)
        ->and($record->active)->toBeTrue()
        ->and($record->currentAccessEndsAt)->toEqual(Identity::now()->modify('+11 months'))
        ->and($record->openEnded)->toBeFalse()
        ->and($record->grants)->toHaveCount(1);
});

it('reports an inactive Person whose only grant has expired, with their full history', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));

    $record = app(GetMembershipRecord::class)(Access::actorFor($viewer), $person->id);

    expect($record->active)->toBeFalse()
        ->and($record->currentAccessEndsAt)->toBeNull()
        ->and($record->grants)->toHaveCount(1);
});

it('reports a Person with no grants as inactive with empty history', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();

    $record = app(GetMembershipRecord::class)(Access::actorFor($viewer), $person->id);

    expect($record->active)->toBeFalse()->and($record->grants)->toBe([]);
});

it('includes revoked grants in the history', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);
    app(MembershipGrantRepository::class)->revoke($grant->id, $viewer->id, Identity::now()->modify('+1 hour'));

    $record = app(GetMembershipRecord::class)(Access::actorFor($viewer), $person->id);

    expect($record->grants)->toHaveCount(1)
        ->and($record->grants[0]->isRevoked())->toBeTrue()
        ->and($record->active)->toBeFalse();
});

it('accepts an explicit instant, distinct from now', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now(), Identity::now()->modify('+1 month'));

    $past = app(GetMembershipRecord::class)(Access::actorFor($viewer), $person->id, Identity::now()->modify('-1 day'));
    $future = app(GetMembershipRecord::class)(Access::actorFor($viewer), $person->id, Identity::now()->modify('+2 months'));

    expect($past->active)->toBeFalse()->and($future->active)->toBeFalse();
});

it('denies an actor without membership.records.view', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);
    $person = Identity::savedPerson();

    expect(fn () => app(GetMembershipRecord::class)(Access::actorFor($guardian), $person->id))
        ->toThrow(AccessDenied::class);
});

it('refuses an unknown Person', function () {
    $viewer = Access::admin('viewer@example.org');

    expect(fn () => app(GetMembershipRecord::class)(Access::actorFor($viewer), PersonId::generate()))
        ->toThrow(UnknownPerson::class);
});
