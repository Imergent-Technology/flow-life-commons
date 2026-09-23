<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Membership\Application\GrantAlreadyRevoked;
use App\Modules\Membership\Application\GrantNotFound;
use App\Modules\Membership\Application\RevokeMembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

/** The frozen "now" `beforeEach` sets — distinct from Identity::now(), which fixtures use as a creation instant. */
function frozenNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-23 12:00:00', new DateTimeZone('UTC'));
}

beforeEach(function () {
    Carbon::setTestNow(frozenNow());
});

it('revokes a grant, storing both revoked_at and revoked_by_account_id', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id, Identity::now(), null);

    app(RevokeMembershipGrant::class)(Access::actorFor($admin), $grant->id);

    $found = app(MembershipGrantRepository::class)->find($grant->id);
    expect($found?->isRevoked())->toBeTrue()
        ->and($found?->revokedAt)->toEqual(frozenNow())
        ->and($found?->revokedByAccountId)->toEqual($admin->id);
});

it('leaves every other field of the grant untouched', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id, Identity::now(), Identity::now()->modify('+1 year'));

    app(RevokeMembershipGrant::class)(Access::actorFor($admin), $grant->id);

    $found = app(MembershipGrantRepository::class)->find($grant->id);
    expect($found?->startsAt)->toEqual($grant->startsAt)
        ->and($found?->endsAt)->toEqual($grant->endsAt)
        ->and($found?->source)->toBe($grant->source)
        ->and($found?->personId)->toEqual($grant->personId);
});

it('does not delete the row: the grant still exists, revoked', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    app(RevokeMembershipGrant::class)(Access::actorFor($admin), $grant->id);

    expect(DB::table('membership_grants')->count())->toBe(1);
});

it('refuses a second revoke of the same grant', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    app(RevokeMembershipGrant::class)(Access::actorFor($admin), $grant->id);

    expect(fn () => app(RevokeMembershipGrant::class)(Access::actorFor($admin), $grant->id))
        ->toThrow(GrantAlreadyRevoked::class);
});

it('does not change the first revocation when a second is attempted', function () {
    $first = Access::admin('first@example.org');
    $second = Access::admin('second@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    app(RevokeMembershipGrant::class)(Access::actorFor($first), $grant->id);
    Carbon::setTestNow(frozenNow()->modify('+1 hour'));
    try {
        app(RevokeMembershipGrant::class)(Access::actorFor($second), $grant->id);
    } catch (GrantAlreadyRevoked) {
        // expected
    }

    $found = app(MembershipGrantRepository::class)->find($grant->id);
    expect($found?->revokedByAccountId)->toEqual($first->id)
        ->and($found?->revokedAt)->toEqual(frozenNow());
});

it('distinguishes not-found from already-revoked', function () {
    $admin = Access::admin('admin@example.org');

    expect(fn () => app(RevokeMembershipGrant::class)(Access::actorFor($admin), MembershipGrantId::generate()))
        ->toThrow(GrantNotFound::class);
});

it('denies an actor without membership.records.manage', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    expect(fn () => app(RevokeMembershipGrant::class)(Access::actorFor($guardian), $grant->id))
        ->toThrow(AccessDenied::class);

    expect(app(MembershipGrantRepository::class)->find($grant->id)?->isRevoked())->toBeFalse();
});

it('creates no security event for a revocation', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id);

    app(RevokeMembershipGrant::class)(Access::actorFor($admin), $grant->id);

    expect(DB::table('security_events')->count())->toBe(0);
});
