<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Access\Infrastructure\Console\CreateAdministratorCommand;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;

use function Pest\Laravel\artisan;

/**
 * @param  array<array-key, mixed>  $arguments
 */
function interactive(string $command, array $arguments = []): PendingCommand
{
    $pending = artisan($command, $arguments);
    assert($pending instanceof PendingCommand);

    return $pending;
}

const COMMAND = 'identity:create-administrator';

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 12:00:00');
});

/**
 * Runs the command non-interactively and returns its exit code and output.
 *
 * @param  array<array-key, mixed>  $arguments
 * @return array{int, string}
 */
function runCommand(array $arguments): array
{
    $code = Artisan::call(COMMAND, $arguments + ['--no-interaction' => true]);

    return [$code, Artisan::output()];
}

function tokenIn(string $output): ?string
{
    return preg_match('/^\s*([A-Za-z0-9_-]{43})\s*$/m', $output, $m) === 1 ? $m[1] : null;
}

it('creates the first administrator and prints the one-time token after the commit', function () {
    [$code, $output] = runCommand(['email' => 'Root@Example.org', '--name' => 'Root Administrator']);

    $token = tokenIn($output);
    expect($code)->toBe(0)
        ->and($token)->not->toBeNull()
        ->and($output)->toContain('Administrator invited')->toContain('Root@Example.org')->toContain('status: invited')
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', (string) $token))
        ->and(DB::table('accounts')->value('status'))->toBe('invited')
        ->and(DB::table('accounts')->value('password_hash'))->toBeNull()
        ->and(DB::table('role_assignments')->value('role_key'))->toBe('platform_administrator');
});

it('accepts no password, in any form', function () {
    $definition = app(CreateAdministratorCommand::class)->getDefinition();
    $names = [...array_keys($definition->getOptions()), ...array_keys($definition->getArguments())];

    foreach ($names as $name) {
        expect($name)->not->toMatch('/pass|secret|credential|hash/i');
    }
});

it('never writes the token to the database, the audit trail or the logs', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.json_encode($e->context);
    });

    [, $output] = runCommand(['email' => 'root@example.org', '--name' => 'Root']);
    $token = (string) tokenIn($output);

    expect(Faults::everythingStored())->not->toContain($token)
        ->and(implode("\n", $logged))->not->toContain($token);
});

it('shows no token, and changes nothing, when the transaction fails', function () {
    Faults::auditFailsAt(3);
    $counts = Faults::counts();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.json_encode($e->context);
    });

    // The command lets the failure surface; what matters is what was and was not revealed.
    expect(fn () => Artisan::call(COMMAND, ['email' => 'root@example.org', '--name' => 'Root', '--no-interaction' => true]))
        ->toThrow(RuntimeException::class);
    $output = Artisan::output();

    expect(tokenIn($output))->toBeNull()
        ->and($output)->not->toContain('Invitation token')
        ->and(Faults::counts())->toBe($counts);
});

it('refuses when an administrator already exists, and says how many can sign in', function () {
    Access::admin('existing@example.org', 'Existing');
    $counts = Faults::counts();

    [$code, $output] = runCommand(['email' => 'second@example.org', '--name' => 'Second']);

    expect($code)->toBe(1)
        ->and($output)->toContain('An administrator already exists (1 assigned, 1 able to sign in)')->toContain('Nothing was changed')
        ->and(tokenIn($output))->toBeNull()
        ->and(Faults::counts())->toBe($counts);
});

it('does NOT let --force override the protection in a non-interactive run', function () {
    Access::admin('existing@example.org', 'Existing');
    $counts = Faults::counts();

    [$code, $output] = runCommand(['email' => 'second@example.org', '--name' => 'Second', '--force' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Recovery needs an interactive terminal')
        ->and(Faults::counts())->toBe($counts);
});

it('needs the operator to type the address back for a recovery, and refuses a wrong answer', function () {
    Access::admin('existing@example.org', 'Existing');
    $counts = Faults::counts();

    interactive(COMMAND, ['email' => 'second@example.org', '--name' => 'Second', '--force' => true])
        ->expectsQuestion('To continue, type the email address again (second@example.org)', 'yes')
        ->expectsOutputToContain('The confirmation did not match')
        ->assertExitCode(1);

    expect(Faults::counts())->toBe($counts);
});

it('creates a recovery administrator when the operator confirms interactively, touching no existing account', function () {
    $existing = Access::admin('existing@example.org', 'Existing');
    $before = DB::table('accounts')->where('id', $existing->id->value)->first();

    interactive(COMMAND, ['email' => 'second@example.org', '--name' => 'Second', '--force' => true])
        ->expectsOutputToContain('This creates ANOTHER administrator')
        ->expectsQuestion('To continue, type the email address again (second@example.org)', 'Second@Example.org')
        ->expectsOutputToContain('Recovery administrator invited')
        ->assertExitCode(0);

    expect(DB::table('role_assignments')->where('role_key', 'platform_administrator')->count())->toBe(2)
        ->and(DB::table('accounts')->where('id', $existing->id->value)->first())->toEqual($before)
        ->and(Identity::context(Identity::events('administrator.bootstrapped')[0])['recovery'])->toBeTrue();
});

it('says --force is unnecessary when no administrator exists, and carries on normally', function () {
    [$code, $output] = runCommand(['email' => 'root@example.org', '--name' => 'Root', '--force' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('--force is not needed')
        ->and(Identity::context(Identity::events('administrator.bootstrapped')[0])['recovery'])->toBeFalse();
});

it('fails clearly on an address that already belongs to an account, and never changes it', function () {
    $victim = Identity::savedActiveAccount('victim@example.org', name: 'Victim');
    $before = DB::table('accounts')->where('id', $victim->id->value)->first();
    $counts = Faults::counts();

    [$code, $output] = runCommand(['email' => 'VICTIM@example.org', '--name' => 'Hijack']);

    expect($code)->toBe(1)
        ->and($output)->toContain('already belongs to an account')->toContain('never repurposed')
        ->and(Faults::counts())->toBe($counts)
        ->and(DB::table('accounts')->where('id', $victim->id->value)->first())->toEqual($before);
});

it('reports an invalid address or name without creating anything', function (array $arguments, string $message) {
    $counts = Faults::counts();

    [$code, $output] = runCommand($arguments);

    expect($code)->toBe(1)->and($output)->toContain($message)->and(Faults::counts())->toBe($counts);
})->with([
    'bad address' => [['email' => 'not an email', '--name' => 'Root'], 'not a valid email address'],
    'non-ASCII address' => [['email' => 'josé@example.org', '--name' => 'Root'], 'not a valid email address'],
    'name too long' => [['email' => 'a@example.org', '--name' => str_repeat('x', 256)], 'display name must be'],
]);

it('requires both an address and a name when it cannot ask', function () {
    $counts = Faults::counts();

    [$missingName, $output] = runCommand(['email' => 'root@example.org']);
    [$missingEmail] = runCommand(['--name' => 'Root']);

    expect($missingName)->toBe(1)->and($output)->toContain('required')->and($missingEmail)->toBe(1)
        ->and(Faults::counts())->toBe($counts);
});

it('asks for the address and name when run from a terminal without them', function () {
    interactive(COMMAND)
        ->expectsQuestion('Email address', 'asked@example.org')
        ->expectsQuestion('Display name', 'Asked Administrator')
        ->expectsOutputToContain('Administrator invited')
        ->assertExitCode(0);

    expect(DB::table('accounts')->value('email'))->toBe('asked@example.org');
});

it('makes the invited administrator an administrator in name only: they cannot sign in, and cannot exercise authority', function () {
    [, $output] = runCommand(['email' => 'root@example.org', '--name' => 'Root']);
    $account = DB::table('accounts')->first();
    assert($account !== null);

    (new Console)->login('root@example.org', (string) tokenIn($output))->assertUnauthorized();
    expect(Access::activeAdministrators())->toBe(0)
        ->and(Role::PlatformAdministrator->value)->toBe('platform_administrator');
});
