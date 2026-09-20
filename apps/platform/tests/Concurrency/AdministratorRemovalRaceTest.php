<?php

declare(strict_types=1);

use App\Modules\Access\Application\LastAdministratorRequired;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Application\AccountDeactivationRefused;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * The last-administrator invariant under REAL concurrency (ADR 0020), across two PHP processes
 * and two database connections.
 *
 * Method. The test process runs one real use case and PAUSES INSIDE ITS OPEN TRANSACTION, after
 * it has changed state but before it commits: the audit write is exactly that moment, so a hook
 * on the audit writer is the pause point. There it launches a second PHP process
 * (tests/Concurrency/worker.php) that runs a competing removal, waits until that process reports
 * READY, gives it a moment to reach the database, and observes whether it is still running.
 *
 * With the serialization in place the worker BLOCKS on the administrator lock until the first
 * transaction commits, then re-reads the committed state, sees it is now the last administrator,
 * and is refused. Without it the worker sails through on stale data and both removals succeed.
 * Every scenario asserts both halves (it blocked, and the platform still has an active
 * administrator), because either alone can pass for the wrong reason.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    cleanConcurrencyData();
});

afterEach(function () {
    cleanConcurrencyData();
});

/** These tests commit real rows, so they must never run against anything but the test database. */
function cleanConcurrencyData(): void
{
    $database = config()->string('database.connections.'.config()->string('database.default').'.database');
    assert(str_ends_with($database, '_test'), 'concurrency tests only run on a _test database');

    foreach (['security_events', 'sessions', 'role_assignments', 'account_invitations', 'accounts', 'people'] as $table) {
        DB::table($table)->delete();
    }
}

/** @param  array<array-key, mixed>  $settings */
function setting(array $settings, string $key): string
{
    $value = $settings[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
}

/** @param  array<string, string>  $arguments */
function startWorker(string $operation, array $arguments): Process
{
    $connection = config()->string('database.default');
    $settings = config()->array("database.connections.{$connection}");

    // Explicit, so the worker can never fall back to the development database in .env.
    $environment = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_URL' => '',
        'DB_HOST' => setting($settings, 'host'),
        'DB_PORT' => setting($settings, 'port'),
        'DB_DATABASE' => setting($settings, 'database'),
        'DB_USERNAME' => setting($settings, 'username'),
        'DB_PASSWORD' => setting($settings, 'password'),
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];

    $process = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), $operation, json_encode($arguments, JSON_THROW_ON_ERROR)], base_path(), $environment, null, 180);
    $process->start();

    return $process;
}

function waitUntilReady(Process $worker): void
{
    $deadline = microtime(true) + 90;
    while (! str_contains($worker->getOutput(), 'READY')) {
        if (! $worker->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('the worker never became ready: '.$worker->getOutput().$worker->getErrorOutput());
        }
        usleep(50_000);
    }
}

/** What the first operation's pause hook observed about the worker. */
final class RaceState
{
    public ?Process $worker = null;

    public bool $blocked = false;
}

/**
 * Runs $first in this process. At the pause point (its audit write of type $pauseOn, or, when
 * $pauseOn is null, when $first calls the closure it is given) it is inside its transaction with
 * state changed but not committed. There it starts the competing worker and observes it.
 *
 * @param  Closure(Closure): mixed  $first
 * @param  array<string, string>  $workerArguments
 * @return array{blocked: bool, exit: int|null, class: string|null}
 */
function raceAgainst(Closure $first, ?string $pauseOn, string $workerOperation, array $workerArguments): array
{
    $state = new RaceState;
    $inner = app(SecurityEventWriter::class);

    $hook = function () use ($state, $workerOperation, $workerArguments): void {
        $worker = startWorker($workerOperation, $workerArguments);
        $state->worker = $worker;
        waitUntilReady($worker);
        usleep(1_500_000); // let it reach the lock; with no lock it would be finished by now
        $state->blocked = $worker->isRunning();
    };

    if ($pauseOn !== null) {
        app()->bind(SecurityEventWriter::class, fn () => new class($inner, $pauseOn, $hook) implements SecurityEventWriter
        {
            private bool $fired = false;

            public function __construct(private SecurityEventWriter $inner, private string $type, private Closure $hook) {}

            public function append(SecurityEvent $event): void
            {
                if ($event->type === $this->type && ! $this->fired) {
                    $this->fired = true;
                    ($this->hook)(); // still inside the first transaction: state changed, not committed
                }
                $this->inner->append($event);
            }
        });
    }

    $first($hook);
    $worker = $state->worker;
    assert($worker instanceof Process, 'the first operation never reached its pause point');
    $worker->wait();

    $lines = array_filter(explode("\n", trim($worker->getOutput())));
    $report = json_decode((string) end($lines), true);

    return [
        'blocked' => $state->blocked,
        'exit' => $worker->getExitCode(),
        'class' => is_array($report) && is_string($report['class'] ?? null) ? $report['class'] : null,
    ];
}

/** @return array{Account, Account} two ACTIVE administrators, committed */
function twoAdministrators(): array
{
    return [Access::admin('ada@example.org', 'Ada'), Access::admin('bob@example.org', 'Bob')];
}

it('serialises a revoke against a disable: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = raceAgainst(
        fn (Closure $pause) => app(RevokeRole::class)(Access::actorFor($ada), $bob->personId, Role::PlatformAdministrator),
        'role.revoked', 'disable', ['account' => $ada->id->value],
    );

    expect($race['blocked'])->toBeTrue('the competing disable did not wait for the revoke to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(AccountDeactivationRefused::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('serialises a disable against a revoke: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = raceAgainst(
        fn (Closure $pause) => app(DisableAccount::class)($ada->id),
        'account.disabled', 'revoke', ['actor_account' => $ada->id->value, 'actor_person' => $ada->personId->value, 'person' => $bob->personId->value],
    );

    expect($race['blocked'])->toBeTrue('the competing revoke did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(LastAdministratorRequired::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('serialises two revokes: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = raceAgainst(
        fn (Closure $pause) => app(RevokeRole::class)(Access::actorFor($ada), $ada->personId, Role::PlatformAdministrator),
        'role.revoked', 'revoke', ['actor_account' => $bob->id->value, 'actor_person' => $bob->personId->value, 'person' => $bob->personId->value],
    );

    expect($race['blocked'])->toBeTrue('the competing revoke did not wait')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(LastAdministratorRequired::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('serialises two disables: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = raceAgainst(
        fn (Closure $pause) => app(DisableAccount::class)($ada->id),
        'account.disabled', 'disable', ['account' => $bob->id->value],
    );

    expect($race['blocked'])->toBeTrue('the competing disable did not wait')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(AccountDeactivationRefused::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('still serialises, but does not over-refuse, when a third administrator makes the removal safe', function () {
    // The control: the lock makes the second operation WAIT, and it then succeeds because,
    // on the committed state, an active administrator really does remain.
    [$ada, $bob] = twoAdministrators();
    $cleo = Access::admin('cleo@example.org', 'Cleo');

    $race = raceAgainst(
        fn (Closure $pause) => app(RevokeRole::class)(Access::actorFor($ada), $bob->personId, Role::PlatformAdministrator),
        'role.revoked', 'disable', ['account' => $ada->id->value],
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(Access::activeAdministrators())->toBe(1)
        ->and(app(AccountRepository::class)->find($cleo->id)?->canAuthenticate())->toBeTrue();
});

it('holds even against an account change that bypasses the guard, because the survivors\' accounts are locked too', function () {
    // Defence in depth. A future path that took an administrator out of service WITHOUT going
    // through the deactivation guard (it forgot, or it is a bug) never takes the assignments lock.
    // The survivors' account-row locks are what still make a concurrent revoke wait for it and
    // then see it, instead of counting an administrator that is about to stop being active.
    [$ada, $bob] = twoAdministrators();

    $race = raceAgainst(
        fn (Closure $pause) => DB::transaction(function () use ($ada, $pause): void {
            app(AccountRepository::class)->save($ada->disable(Identity::now()->modify('+1 day'))); // no guard
            $pause();
        }),
        null, 'revoke', ['actor_account' => $ada->id->value, 'actor_person' => $ada->personId->value, 'person' => $bob->personId->value],
    );

    expect($race['blocked'])->toBeTrue('the revoke did not wait for the unguarded disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(LastAdministratorRequired::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('does not let a sign-in that raced a disable bring the account back', function () {
    // The Phase 2 defect, across two real processes: login has read the account and is about to
    // record the sign-in while a disable is uncommitted. It must wait for it, see it, and fail.
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $race = raceAgainst(
        fn (Closure $pause) => app(DisableAccount::class)($target->id),
        'account.disabled', 'login', ['email' => 'target@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue('the sign-in did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('accounts')->where('id', $target->id->value)->value('status'))->toBe('disabled')
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});
