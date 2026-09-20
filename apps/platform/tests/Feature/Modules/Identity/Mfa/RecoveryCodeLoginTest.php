<?php

declare(strict_types=1);

use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\RecoveryCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Mfa::registerProbeRoutes();
});

/** @return array{Console, Account, array{secret: string, codes: list<string>}} */
function pendingWithCodes(): array
{
    $account = Mfa::guardian();
    $factor = Mfa::enroll($account);
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);

    return [$console, $account, $factor];
}

it('completes the sign-in with a valid recovery code, in place of the authenticator', function () {
    [$console, $account, $factor] = pendingWithCodes();

    $console->challengeWithRecoveryCode($factor['codes'][0])->assertOk()
        ->assertJsonPath('mfa.recovery_codes_remaining', 9);

    $console->me()->assertOk()->assertJsonPath('mfa.recovery_codes_remaining', 9);
    $console->get('/api/v1/zz/actor')->assertOk()->assertJson(['via' => 'session_second_factor']);
    expect(Mfa::remainingCodes($account))->toBe(9);
});

it('spends a code exactly once: using it again fails, and the answer is the same as for any wrong code', function () {
    [$console, , $factor] = pendingWithCodes();
    $console->challengeWithRecoveryCode($factor['codes'][3])->assertOk();

    $again = new Console;
    $again->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
    $reused = $again->challengeWithRecoveryCode($factor['codes'][3])->assertUnprocessable();
    $invented = $again->challengeWithRecoveryCode('ZZZZ-ZZZZ-ZZZZ-ZZZZ')->assertUnprocessable();

    expect($reused->getContent())->toBe($invented->getContent())
        ->and($reused->json('errors'))->toBe(['recovery_code' => ['The code is not valid.']]);
    $again->me()->assertUnauthorized();
});

it('leaves the remaining codes untouched, and each still works once', function () {
    [$console, $account, $factor] = pendingWithCodes();
    $console->challengeWithRecoveryCode($factor['codes'][0])->assertOk();

    foreach ([1, 2, 9] as $index) {
        $next = new Console;
        $next->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
        $next->challengeWithRecoveryCode($factor['codes'][$index])->assertOk();
    }

    expect(Mfa::remainingCodes($account))->toBe(6)
        ->and(DB::table('account_recovery_codes')->whereNotNull('used_at')->count())->toBe(4);
});

it('reads a code the way it was printed: case, hyphens and spaces do not matter', function () {
    [$console, , $factor] = pendingWithCodes();
    $plain = strtolower(str_replace('-', ' ', $factor['codes'][5]));

    $console->challengeWithRecoveryCode($plain)->assertOk();
});

it('refuses a malformed or invented code without spending anything', function (string $code) {
    [$console, $account] = pendingWithCodes();

    $console->challengeWithRecoveryCode($code)->assertUnprocessable()->assertJsonPath('errors.recovery_code.0', 'The code is not valid.');

    expect(Mfa::remainingCodes($account))->toBe(10);
    $console->me()->assertUnauthorized();
})->with(['short' => 'ABCD', 'not in the alphabet' => 'UUUU-UUUU-UUUU-UUUU', 'wrong but well formed' => 'ABCD-EFGH-JKMN-PQRS', 'long' => str_repeat('A', 60)]);

it('does not take a recovery code from another Account', function () {
    [$console] = pendingWithCodes();
    $other = Mfa::guardian('other@example.org');
    $othersCodes = Mfa::enroll($other, 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U')['codes'];

    $console->challengeWithRecoveryCode($othersCodes[0])->assertUnprocessable();
    expect(Mfa::remainingCodes($other))->toBe(10);
});

it('records a recovery-code sign-in with how many remain, never which code', function () {
    [$console, $account, $factor] = pendingWithCodes();
    $console->challengeWithRecoveryCode($factor['codes'][0])->assertOk();

    $used = Identity::events('mfa.recovery_code_used');
    expect($used)->toHaveCount(1)
        ->and($used[0]->subject_account_id)->toBe($account->id->value)
        ->and(Identity::context($used[0]))->toBe(['remaining' => 9, 'during' => 'sign_in'])
        ->and(Identity::context(Identity::events('authentication.succeeded')[0]))->toBe(['method' => 'password', 'second_factor' => 'recovery_code']);

    $audit = Mfa::auditText();
    foreach ($factor['codes'] as $code) {
        Mfa::assertAbsent($audit, $code, str_replace('-', '', $code), RecoveryCode::fromPresented($code)->digest($account->id));
    }
});

it('does not spend a code when the sign-in cannot finish (a disabled Account)', function () {
    [$console, $account, $factor] = pendingWithCodes();
    app(DisableAccount::class)($account->id);

    $console->challengeWithRecoveryCode($factor['codes'][0])->assertUnauthorized();

    expect(Mfa::remainingCodes($account))->toBe(10);
});

it('rate limits recovery codes exactly as it does authenticator codes', function () {
    config(['identity.credential_throttle.mfa_challenge.per_identifier' => 2]);
    [$console, , $factor] = pendingWithCodes();
    $console->challengeWithRecoveryCode('ABCD-EFGH-JKMN-PQRS')->assertUnprocessable();
    $console->challengeWithRecoveryCode('ABCD-EFGH-JKMN-PQRT')->assertUnprocessable();

    $console->challengeWithRecoveryCode($factor['codes'][0])->assertStatus(429);
});

it('lets a person who has lost the authenticator sign in with a recovery code and see how many are left', function () {
    [$console, , $factor] = pendingWithCodes();

    // No authenticator code is ever offered.
    $console->challengeWithRecoveryCode($factor['codes'][0])->assertOk();
    $console->me()->assertOk()->assertJsonPath('mfa.enrolled', true)->assertJsonPath('mfa.recovery_codes_remaining', 9);
    expect(Totp::code($factor['secret']))->toBeString();   // (the authenticator itself was never used)
});
