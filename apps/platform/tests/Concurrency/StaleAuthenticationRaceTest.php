<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AccountSecurityGeneration;
use App\Modules\Identity\Application\AuthenticateAccount;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CompleteSecondFactor;
use App\Modules\Identity\Application\CredentialMarker;
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Http\ConsoleSession;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Race;
use Tests\Support\Recovery;
use Tests\Support\Totp;

/*
 * THE window ADR 0024 recorded as a residual and ADR 0025 closes, under REAL concurrency (method:
 * Tests\Support\Race), on MariaDB and PostgreSQL.
 *
 * The shape of every scenario here is the ordering that row deletion cannot handle:
 *
 *   1. an authentication proof runs in this process and PAUSES inside its open transaction;
 *   2. a second PHP process runs a security-invalidating operation and BLOCKS on the Account lock;
 *   3. the proof commits; the worker then commits, finding NO session row to delete, because the
 *      transport has not written one yet;
 *   4. only THEN does this process do what the transport does: establish the session.
 *
 * Before ADR 0025 step 4 produced a working session established on security state the worker had just
 * destroyed. It must now produce none, and the invariant is what proves it rather than the timing.
 *
 * Each scenario asserts BOTH halves — the worker really blocked on the lock, and the session really was
 * refused — because either alone can pass for the wrong reason, and each has a control showing the same
 * code path succeeds when nothing supersedes it.
 *
 * Runs on both engines: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

/**
 * A committed Console Account with an authenticator, and its half-finished sign-in.
 *
 * @return array{Account, PendingLogin, array{secret: string, codes: list<string>}}
 */
function enrolledConsoleAccount(string $email = 'target@example.org'): array
{
    $account = Identity::savedActiveAccount($email);
    Access::grant($account, Role::Guardian);
    $factor = Mfa::enroll($account);
    $current = app(AccountRepository::class)->find($account->id);
    assert($current !== null);

    return [$account, new PendingLogin($account->id, app(CredentialMarker::class)->for($current), SecondFactorNeed::Challenge), $factor];
}

/**
 * What the transport does after a use case has returned: establish the browser session, with the
 * security generation that use case read under the Account's lock.
 */
function establishAsTransport(Account $account, ?int $securityGeneration, bool $secondFactor = true): bool
{
    expect($securityGeneration)->toBeInt('the use case did not report the generation it proved against');
    assert(is_int($securityGeneration));

    $store = app('session')->driver();
    assert($store instanceof Store);
    $request = Request::create('/api/v1/me');
    $request->setLaravelSession($store);
    $request->session()->start();

    $established = app(ConsoleSession::class)->establish($request, $account->id, $securityGeneration, secondFactor: $secondFactor);
    $request->session()->save(); // the transport persists the session whatever happened

    return $established;
}

/** Session rows attributed to an Account. An anonymous row is not a sign-in. */
function signedInRows(Account $account): int
{
    return DB::table('sessions')->where('user_id', $account->id->value)->count();
}

it('establishes NO session for a TOTP challenge that an administrative reset overtook', function () {
    [$account, $pending, $factor] = enrolledConsoleAccount();

    $outcome = null;
    $race = Race::against(
        function (Closure $pause) use ($pending, $factor, &$outcome): void {
            $outcome = app(CompleteSecondFactor::class)($pending, SecondFactorProof::totp(Totp::code($factor['secret'])), new ClientContext('127.0.0.1', 'first'));
        },
        'authentication.succeeded', 'mfa_reset', ['account' => $account->id->value],
    );

    // The challenge won the lock and committed; the reset waited, then committed on what it found.
    expect($race['blocked'])->toBeTrue('the reset did not wait for the challenge to commit')
        ->and($race['exit'])->toBe(0)
        ->and($outcome?->succeeded())->toBeTrue()
        ->and(signedInRows($account))->toBe(0, 'the reset found no session row to delete, as expected');

    // And now the transport, a moment too late. Before ADR 0025 this produced a live session.
    $proved = $outcome?->securityGeneration;
    expect(establishAsTransport($account, $proved))->toBeFalse()
        ->and(signedInRows($account))->toBe(0)
        ->and(app(AccountSecurityGeneration::class)->current($account->id))->toBe(($proved ?? 0) + 1);
});

it('establishes NO session for a RECOVERY-CODE sign-in that an administrative reset overtook', function () {
    [$account, $pending, $factor] = enrolledConsoleAccount();

    $outcome = null;
    $race = Race::against(
        function (Closure $pause) use ($pending, $factor, &$outcome): void {
            $outcome = app(CompleteSecondFactor::class)($pending, SecondFactorProof::recoveryCode($factor['codes'][0]), new ClientContext('127.0.0.1', 'first'));
        },
        'authentication.succeeded', 'mfa_reset', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the reset did not wait for the recovery-code sign-in to commit')
        ->and($race['exit'])->toBe(0)
        ->and($outcome?->succeeded())->toBeTrue()
        ->and(establishAsTransport($account, $outcome?->securityGeneration))->toBeFalse()
        ->and(signedInRows($account))->toBe(0);
});

it('establishes NO session for a challenge that a DISABLE overtook', function () {
    [$account, $pending, $factor] = enrolledConsoleAccount();

    $outcome = null;
    $race = Race::against(
        function (Closure $pause) use ($pending, $factor, &$outcome): void {
            $outcome = app(CompleteSecondFactor::class)($pending, SecondFactorProof::totp(Totp::code($factor['secret'])), new ClientContext('127.0.0.1', 'first'));
        },
        'authentication.succeeded', 'disable', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the disable did not wait for the challenge to commit')
        ->and($race['exit'])->toBe(0)
        ->and(establishAsTransport($account, $outcome?->securityGeneration))->toBeFalse()
        ->and(signedInRows($account))->toBe(0);
});

it('establishes NO session for a PASSWORD-ONLY sign-in that a password reset overtook', function () {
    // The one-step path: no second factor is due, so AuthenticateAccount itself is the proof that commits.
    $account = Identity::savedActiveAccount('plain@example.org');
    $token = Recovery::tokenFor($account);

    $result = null;
    $race = Race::against(
        function (Closure $pause) use ($account, &$result): void {
            $result = app(AuthenticateAccount::class)(
                $account->email, Identity::PASSWORD, new ClientContext('127.0.0.1', 'first'),
            );
        },
        'authentication.succeeded', 'reset',
        ['email' => $account->email->value, 'token' => $token, 'password' => 'a wholly different long passphrase'],
    );

    expect($race['blocked'])->toBeTrue('the reset did not wait for the sign-in to commit')
        ->and($race['exit'])->toBe(0)
        ->and($result?->securityGeneration)->toBeInt()
        ->and(establishAsTransport($account, $result?->securityGeneration, secondFactor: false))->toBeFalse()
        ->and(signedInRows($account))->toBe(0);
});

it('DOES establish the session when nothing supersedes the proof', function () {
    // The control for every scenario above: the same code path, with a competing operation that
    // invalidates no authentication (a role grant). A refuse-everything implementation fails here.
    [$account, $pending, $factor] = enrolledConsoleAccount();

    $outcome = app(CompleteSecondFactor::class)($pending, SecondFactorProof::totp(Totp::code($factor['secret'])), new ClientContext('127.0.0.1', 'first'));

    expect($outcome->succeeded())->toBeTrue()
        ->and(establishAsTransport($account, $outcome->securityGeneration))->toBeTrue()
        ->and(signedInRows($account))->toBe(1);
});

it('refuses the challenge outright in the REVERSE order, and the reset still stands', function () {
    // Reset first, challenge waiting on the lock: it decides on what the reset committed and never gets
    // as far as a proof. This is the ordering the factor-state check already covered; it is pinned here
    // too so the pair of orderings is one test file.
    [$account, $pending, $factor] = enrolledConsoleAccount();

    $race = Race::against(
        fn (Closure $pause) => app(ResetMultiFactor::class)->fromServer($account->id),
        'mfa.reset_from_server', 'mfa_complete',
        ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'need' => 'challenge', 'code' => Totp::code($factor['secret'])],
    );

    expect($race['blocked'])->toBeTrue('the challenge did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(Identity::events('authentication.succeeded'))->toBe([])
        ->and(signedInRows($account))->toBe(0);
});
