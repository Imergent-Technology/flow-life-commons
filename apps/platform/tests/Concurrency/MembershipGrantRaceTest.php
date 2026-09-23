<?php

declare(strict_types=1);

use App\Modules\Membership\Application\GrantAlreadyRevoked;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Race;

/*
 * One-way membership grant revocation under REAL concurrency (ADR 0028, ADR 0025's
 * conditional-update precedent), across two PHP processes and two database connections.
 *
 * Method: see Tests\Support\Race. RevokeMembershipGrant records no security event and takes
 * no row lock of its own, so there is no audit write to pause on: the test process opens its
 * own transaction, calls the conditional UPDATE directly, and pauses itself (the same pattern
 * MfaRaceTest uses to prove the recovery-code conditional update alone is enough).
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

it('revokes a grant atomically even with no lock of its own: the conditional update alone is enough', function () {
    $operator = Access::admin('operator@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id, Identity::now(), null);

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($grant, $operator, $pause): void {
            expect(app(MembershipGrantRepository::class)->revoke($grant->id, $operator->id, new DateTimeImmutable('now')))->toBeTrue();
            $pause();
        }),
        null, 'revoke_grant', ['actor_account' => $operator->id->value, 'actor_person' => $operator->personId->value, 'grant' => $grant->id->value],
    );

    expect($race['blocked'])->toBeTrue('a second revoke of the same grant did not wait on the row')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(GrantAlreadyRevoked::class)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(1);
});

it('does not make revoking DIFFERENT grants wait on each other', function () {
    // The control for the test above: it is the ROW that serialises, not the whole table.
    $operator = Access::admin('operator@example.org');
    $first = Membership::savedGrant(Identity::savedPerson()->id, Identity::now(), null);
    $second = Membership::savedGrant(Identity::savedPerson()->id, Identity::now(), null);

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($first, $operator, $pause): void {
            app(MembershipGrantRepository::class)->revoke($first->id, $operator->id, new DateTimeImmutable('now'));
            $pause();
        }),
        null, 'revoke_grant', ['actor_account' => $operator->id->value, 'actor_person' => $operator->personId->value, 'grant' => $second->id->value],
    );

    expect($race['blocked'])->toBeFalse()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(2);
});
