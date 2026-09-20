<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

use function Pest\Laravel\artisan;

/*
 * `identity:reset-mfa`: the operator's emergency recovery (ADR 0024). Server access is its root of trust, so the
 * friction is the point: interactive only, target shown, address typed back.
 */

/** @param  array<array-key, mixed>  $arguments */
function resetMfaCommand(array $arguments = []): PendingCommand
{
    $pending = artisan('identity:reset-mfa', $arguments);
    assert($pending instanceof PendingCommand);

    return $pending;
}

it('resets the sole administrator after showing who it is and asking for the address back', function () {
    $root = Access::admin('root@example.org', 'Root Administrator');
    $factor = Mfa::enroll($root);
    $console = new Console;
    $console->loginWithMfa('root@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $hash = DB::table('accounts')->value('password_hash');

    resetMfaCommand(['email' => 'Root@Example.org'])
        ->expectsOutputToContain('Root Administrator')
        ->expectsOutputToContain('root@example.org')
        ->expectsOutputToContain('recovery codes left')
        ->expectsQuestion('To continue, type the email address again (root@example.org)', 'ROOT@example.org')
        ->expectsOutputToContain('Second factor reset. 1 session(s) ended.')
        ->assertExitCode(0);

    expect(Mfa::isEnrolled($root))->toBeFalse()
        ->and(DB::table('account_recovery_codes')->count())->toBe(0)
        ->and(DB::table('accounts')->value('password_hash'))->toBe($hash)
        ->and(DB::table('accounts')->value('status'))->toBe('active')
        ->and(Access::activeAdministrators())->toBe(1)
        ->and(Identity::events('mfa.reset_from_server'))->toHaveCount(1);
    $console->me()->assertUnauthorized();
    (new Console)->login('root@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
});

it('changes nothing when the address typed back does not match', function () {
    $root = Access::admin('root@example.org');
    Mfa::enroll($root);

    resetMfaCommand(['email' => 'root@example.org'])
        ->expectsQuestion('To continue, type the email address again (root@example.org)', 'someone-else@example.org')
        ->expectsOutputToContain('The confirmation did not match. Nothing was changed.')
        ->assertExitCode(1);

    expect(Mfa::isEnrolled($root))->toBeTrue()->and(Identity::events('mfa.reset_from_server'))->toBe([]);
});

it('refuses outright to run non-interactively, and no flag changes that', function () {
    $root = Access::admin('root@example.org');
    Mfa::enroll($root);

    $code = Artisan::call('identity:reset-mfa', ['email' => 'root@example.org', '--no-interaction' => true]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('needs an interactive terminal')
        ->and(Mfa::isEnrolled($root))->toBeTrue()
        ->and(Identity::events('mfa.reset_from_server'))->toBe([]);
});

it('does not exist as an Account: nothing is changed and nothing is invented', function () {
    resetMfaCommand(['email' => 'nobody@example.org'])
        ->expectsOutputToContain('No account has the address nobody@example.org')
        ->assertExitCode(1);

    expect(DB::table('security_events')->count())->toBe(0);
});

it('rejects an address that is not one', function () {
    resetMfaCommand(['email' => 'not an address'])->expectsOutputToContain('not a valid email address')->assertExitCode(1);
});

it('says so, and records nothing, when the Account has no second factor to reset', function () {
    Mfa::guardian('ada@example.org');

    resetMfaCommand(['email' => 'ada@example.org'])
        ->expectsQuestion('To continue, type the email address again (ada@example.org)', 'ada@example.org')
        ->expectsOutputToContain('There was nothing to reset')
        ->assertExitCode(0);

    expect(Identity::events('mfa.reset_from_server'))->toBe([]);
});

it('never prints a secret, a code or a hash', function () {
    $root = Access::admin('root@example.org');
    $factor = Mfa::enroll($root);
    $captured = '';

    $pending = resetMfaCommand(['email' => 'root@example.org']);
    $pending->expectsQuestion('To continue, type the email address again (root@example.org)', 'root@example.org')->assertExitCode(0);
    // The fixture secret and codes were what existed; they must not have been echoed anywhere in the audit either.
    Mfa::assertAbsent(Mfa::auditText().$captured, $factor['secret'], ...$factor['codes']);
});
