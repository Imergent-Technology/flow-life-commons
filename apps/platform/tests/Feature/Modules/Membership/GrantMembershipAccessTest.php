<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Membership\Application\GrantMembershipAccess;
use App\Modules\Membership\Application\UnknownPerson;
use App\Modules\Membership\Domain\InvalidMembershipTerm;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('lets an actor who holds membership.records.manage grant access to an existing Person', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson('Mia Member');
    $starts = Identity::now();
    $ends = $starts->modify('+1 year');

    $grant = app(GrantMembershipAccess::class)(Access::actorFor($admin), $person->id, $starts, $ends, MembershipGrantSource::Operator);

    expect($grant->personId)->toEqual($person->id)
        ->and($grant->startsAt)->toEqual($starts)
        ->and($grant->endsAt)->toEqual($ends)
        ->and($grant->source)->toBe(MembershipGrantSource::Operator)
        ->and($grant->isRevoked())->toBeFalse()
        ->and(DB::table('membership_grants')->count())->toBe(1);
});

it('derives granted_by_account_id from the Actor, never from an independent input', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();

    $grant = app(GrantMembershipAccess::class)(Access::actorFor($admin), $person->id, Identity::now(), null, MembershipGrantSource::Operator);

    expect($grant->grantedByAccountId)->toEqual($admin->id);
});

it('accepts an open-ended grant', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();

    $grant = app(GrantMembershipAccess::class)(Access::actorFor($admin), $person->id, Identity::now(), null, MembershipGrantSource::Operator);

    expect($grant->endsAt)->toBeNull();
});

it('records provenance for a Luma reconciliation grant', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();

    $grant = app(GrantMembershipAccess::class)(
        Access::actorFor($admin), $person->id, Identity::now()->modify('-1 year'), Identity::now()->modify('+1 month'),
        MembershipGrantSource::LumaLegacy, 'luma-4821',
    );

    expect($grant->source)->toBe(MembershipGrantSource::LumaLegacy)
        ->and($grant->sourceReference)->toBe('luma-4821');
});

it('requires no Account for the Person receiving access', function () {
    $admin = Access::admin('admin@example.org'); // the ACTOR has an account; the recipient must not need one
    $person = Identity::savedPerson(); // no Account

    app(GrantMembershipAccess::class)(Access::actorFor($admin), $person->id, Identity::now(), null, MembershipGrantSource::Operator);

    expect(app(AccountRepository::class)->findByPersonId($person->id))->toBeNull()
        ->and(DB::table('membership_grants')->count())->toBe(1);
});

it('denies an actor without membership.records.manage', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);
    $person = Identity::savedPerson();

    expect(fn () => app(GrantMembershipAccess::class)(Access::actorFor($guardian), $person->id, Identity::now(), null, MembershipGrantSource::Operator))
        ->toThrow(AccessDenied::class);

    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('denies an actor who holds no capability at all', function () {
    $nobody = Identity::savedActiveAccount('nobody@example.org');
    $person = Identity::savedPerson();

    expect(fn () => app(GrantMembershipAccess::class)(Access::actorFor($nobody), $person->id, Identity::now(), null, MembershipGrantSource::Operator))
        ->toThrow(AccessDenied::class);
});

it('refuses to grant access to a Person that does not exist, and creates nothing', function () {
    $admin = Access::admin('admin@example.org');

    expect(fn () => app(GrantMembershipAccess::class)(Access::actorFor($admin), PersonId::generate(), Identity::now(), null, MembershipGrantSource::Operator))
        ->toThrow(UnknownPerson::class);

    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('checks authorization before checking whether the Person exists', function () {
    $nobody = Identity::savedActiveAccount('nobody@example.org');

    // An unknown PersonId AND no capability: AccessDenied must win, proving the order.
    expect(fn () => app(GrantMembershipAccess::class)(Access::actorFor($nobody), PersonId::generate(), Identity::now(), null, MembershipGrantSource::Operator))
        ->toThrow(AccessDenied::class);
});

it('refuses a backwards or zero-length term', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();

    expect(fn () => app(GrantMembershipAccess::class)(Access::actorFor($admin), $person->id, Identity::now(), Identity::now(), MembershipGrantSource::Operator))
        ->toThrow(InvalidMembershipTerm::class);

    expect(DB::table('membership_grants')->count())->toBe(0);
});

it('creates no Account, no invitation, no role assignment and no security event', function () {
    $admin = Access::admin('admin@example.org'); // the actor itself owns one account and one role assignment
    $person = Identity::savedPerson();
    $before = [
        'accounts' => DB::table('accounts')->count(),
        'account_invitations' => DB::table('account_invitations')->count(),
        'role_assignments' => DB::table('role_assignments')->count(),
        'security_events' => DB::table('security_events')->count(),
    ];

    app(GrantMembershipAccess::class)(Access::actorFor($admin), $person->id, Identity::now(), null, MembershipGrantSource::Operator);

    expect(DB::table('accounts')->count())->toBe($before['accounts'])
        ->and(DB::table('account_invitations')->count())->toBe($before['account_invitations'])
        ->and(DB::table('role_assignments')->count())->toBe($before['role_assignments'])
        ->and(DB::table('security_events')->count())->toBe($before['security_events']);
});

it('lets two grants exist for the same Person', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();
    $actor = Access::actorFor($admin);

    app(GrantMembershipAccess::class)($actor, $person->id, Identity::now(), Identity::now()->modify('+1 month'), MembershipGrantSource::Operator);
    app(GrantMembershipAccess::class)($actor, $person->id, Identity::now()->modify('+2 months'), null, MembershipGrantSource::Operator);

    expect(app(MembershipGrantRepository::class)->forPerson($person->id))->toHaveCount(2);
});
