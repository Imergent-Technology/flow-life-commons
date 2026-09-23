<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Membership\Application\RegisterPersonWithMembershipAccess;
use App\Modules\Membership\Domain\InvalidMembershipTerm;
use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('registers a Person and grants membership access in one committed transaction', function () {
    $admin = Access::admin('admin@example.org'); // the actor itself owns one account
    $peopleBefore = DB::table('people')->count();
    $accountsBefore = DB::table('accounts')->count();

    $result = app(RegisterPersonWithMembershipAccess::class)(
        Access::actorFor($admin), 'Mia Member', Identity::now(), null, MembershipGrantSource::Operator,
    );

    expect(DB::table('people')->count())->toBe($peopleBefore + 1)
        ->and(DB::table('accounts')->count())->toBe($accountsBefore)
        ->and(DB::table('membership_grants')->count())->toBe(1)
        ->and($result->displayName)->toBe('Mia Member')
        ->and($result->grant->personId)->toEqual($result->personId)
        ->and($result->grant->grantedByAccountId)->toEqual($admin->id)
        ->and(app(PersonRepository::class)->find($result->personId))->not->toBeNull();
});

it('creates no Account, no invitation, no role assignment and no security event', function () {
    $admin = Access::admin('admin@example.org'); // the actor itself owns one account and one role assignment
    $before = [
        'accounts' => DB::table('accounts')->count(),
        'account_invitations' => DB::table('account_invitations')->count(),
        'role_assignments' => DB::table('role_assignments')->count(),
        'security_events' => DB::table('security_events')->count(),
    ];

    app(RegisterPersonWithMembershipAccess::class)(Access::actorFor($admin), 'Mia Member', Identity::now(), null, MembershipGrantSource::Operator);

    expect(DB::table('accounts')->count())->toBe($before['accounts'])
        ->and(DB::table('account_invitations')->count())->toBe($before['account_invitations'])
        ->and(DB::table('role_assignments')->count())->toBe($before['role_assignments'])
        ->and(DB::table('security_events')->count())->toBe($before['security_events']);
});

it('checks authorization BEFORE creating the Person: an unauthorized caller leaves no trace', function () {
    $nobody = Identity::savedActiveAccount('nobody@example.org');
    $peopleBefore = DB::table('people')->count();

    expect(fn () => app(RegisterPersonWithMembershipAccess::class)(Access::actorFor($nobody), 'New Member', Identity::now(), null, MembershipGrantSource::Operator))
        ->toThrow(AccessDenied::class);

    expect(DB::table('people')->count())->toBe($peopleBefore)
        ->and(DB::table('membership_grants')->count())->toBe(0);
});

it('rolls back the new Person when the grant term is invalid: no orphan Person survives', function () {
    $admin = Access::admin('admin@example.org');
    $peopleBefore = DB::table('people')->count();

    // startsAt === endsAt: a zero-length term the grant step refuses, mid-transaction, after
    // RegisterPerson has already inserted the Person.
    expect(fn () => app(RegisterPersonWithMembershipAccess::class)(
        Access::actorFor($admin), 'Mia Member', Identity::now(), Identity::now(), MembershipGrantSource::Operator,
    ))->toThrow(InvalidMembershipTerm::class);

    expect(DB::table('people')->count())->toBe($peopleBefore)
        ->and(DB::table('membership_grants')->count())->toBe(0);
});

it('rolls back the new Person when persisting the grant fails for any reason', function () {
    $admin = Access::admin('admin@example.org');
    $peopleBefore = DB::table('people')->count();

    app()->bind(MembershipGrantRepository::class, fn () => new class implements MembershipGrantRepository
    {
        public function add(MembershipGrant $grant): void
        {
            throw new RuntimeException('simulated grant persistence failure');
        }

        public function find(MembershipGrantId $id): ?MembershipGrant
        {
            return null;
        }

        public function forPerson(PersonId $personId): array
        {
            return [];
        }

        public function revoke(MembershipGrantId $id, AccountId $revokedBy, DateTimeImmutable $now): bool
        {
            return false;
        }

        public function personIdsPage(int $page, int $perPage): array
        {
            return ['personIds' => [], 'total' => 0];
        }

        public function forPeople(array $personIds): array
        {
            return [];
        }
    });

    expect(fn () => app(RegisterPersonWithMembershipAccess::class)(Access::actorFor($admin), 'Mia Member', Identity::now(), null, MembershipGrantSource::Operator))
        ->toThrow(RuntimeException::class, 'simulated grant persistence failure');

    expect(DB::table('people')->count())->toBe($peopleBefore);
});
