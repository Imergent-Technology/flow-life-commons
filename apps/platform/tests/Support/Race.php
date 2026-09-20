<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The two-process harness the concurrency tests share (tests/Concurrency/*).
 *
 * Method. The test process runs one real use case and PAUSES INSIDE ITS OPEN TRANSACTION, after
 * it has changed state but before it commits: the audit write is exactly that moment, so a hook
 * on the audit writer is the pause point. There it launches a second PHP process
 * (tests/Concurrency/worker.php) that runs a competing operation, waits until that process reports
 * READY, gives it a moment to reach the database, and observes whether it is still running.
 *
 * With the serialization in place the worker BLOCKS on a row lock until the first transaction
 * commits, then re-reads the committed state and decides on it. Without it the worker sails
 * through on stale data. Every scenario asserts both halves (it blocked, and the resulting state
 * is right), because either alone can pass for the wrong reason.
 */
final class Race
{
    /** Tables these tests commit into, children first. */
    private const array TABLES = [
        'security_events', 'sessions', 'password_reset_tokens', 'role_assignments',
        'account_invitations', 'accounts', 'people',
    ];

    /** These tests commit real rows, so they must never run against anything but the test database. */
    public static function clean(): void
    {
        $database = config()->string('database.connections.'.config()->string('database.default').'.database');
        assert(str_ends_with($database, '_test'), 'concurrency tests only run on a _test database');

        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    /** @param  array<string, string>  $arguments */
    public static function start(string $operation, array $arguments): Process
    {
        $connection = config()->string('database.default');
        $settings = config()->array("database.connections.{$connection}");

        // Explicit, so the worker can never fall back to the development database in .env.
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connection,
            'DB_URL' => '',
            'DB_HOST' => self::setting($settings, 'host'),
            'DB_PORT' => self::setting($settings, 'port'),
            'DB_DATABASE' => self::setting($settings, 'database'),
            'DB_USERNAME' => self::setting($settings, 'username'),
            'DB_PASSWORD' => self::setting($settings, 'password'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
            'IDENTITY_COMPROMISED_PASSWORD_CHECK' => 'none',
            'IDENTITY_PASSWORD_RESET_RESPONSE_FLOOR_MS' => '0',
        ];

        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), $operation, json_encode($arguments, JSON_THROW_ON_ERROR)], base_path(), $environment, null, 180);
        $process->start();

        return $process;
    }

    public static function waitUntilReady(Process $worker): void
    {
        $deadline = microtime(true) + 90;
        while (! str_contains($worker->getOutput(), 'READY')) {
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('the worker never became ready: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(50_000);
        }
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
    public static function against(Closure $first, ?string $pauseOn, string $workerOperation, array $workerArguments): array
    {
        $state = new RaceState;
        $inner = app(SecurityEventWriter::class);

        $hook = function () use ($state, $workerOperation, $workerArguments): void {
            $worker = self::start($workerOperation, $workerArguments);
            $state->worker = $worker;
            self::waitUntilReady($worker);
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

    /** @param  array<array-key, mixed>  $settings */
    private static function setting(array $settings, string $key): string
    {
        $value = $settings[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
