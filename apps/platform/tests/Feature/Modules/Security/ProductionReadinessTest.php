<?php

declare(strict_types=1);

use App\Modules\Resources\Application\AssetLimits;
use App\Modules\Security\Application\PhpIni;
use App\Modules\Security\Application\ProductionReadiness;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/*
 * `security:production-check` (`./flow doctor --production`).
 *
 * Its whole value is that it FAILS on a dangerous value, so the tests here are mostly about failing:
 * a check that always passes is worse than no check, because it is believed.
 *
 * It is not tested exhaustively, on purpose. Asserting that each of twenty-six checks reads the
 * setting it says it reads would be twenty-six restatements of the implementation. What is pinned is
 * the shape (green configuration passes), the settings whose loss would be most costly, and the
 * separation between what the command can know and what only a person on the host can.
 */

/**
 * `artisan()` returns PendingCommand|int; every call here needs the object.
 *
 * @param  array<array-key, mixed>  $arguments
 */
function commandProductionReadiness(string $name, array $arguments = []): PendingCommand
{
    $pending = artisan($name, $arguments);
    assert($pending instanceof PendingCommand);

    return $pending;
}

/** @return array<string, bool> check name => passed */
function readiness(): array
{
    $results = [];
    foreach (app(ProductionReadiness::class)->checks() as $check) {
        $results[$check->name] = $check->passed;
    }

    return $results;
}

/** A syntactically valid key that protects nothing. Generated, never a real one. */
const PRODUCTION_CHECK_KEY_BYTES = 'this-is-exactly-32-bytes-long!!!';

/** A non-development database password, for the assertion that it never appears in output. */
const PRODUCTION_CHECK_DB_PASSWORD = 'correct-horse-battery-staple-not-real';

/** Configuration as a correct production host would have it. */
function asProduction(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://commons.flowlifeglobal.org',
        'app.key' => 'base64:'.base64_encode(PRODUCTION_CHECK_KEY_BYTES),
        'app.cipher' => 'AES-256-CBC',
        'app.maintenance.driver' => 'file',
        'identity.password.compromised_check.driver' => 'pwned_passwords',
        'hashing.bcrypt.rounds' => 12,
        'identity.login_throttle.max_attempts_per_ip' => 30,
        'identity.credential_throttle.mfa_challenge.per_ip' => 30,
        'identity.password_reset.response_floor_ms' => 1500,
        'cors.allowed_origins' => [],
        'database.default' => 'mariadb',
        'database.connections.mariadb.database' => 'acct_commons',
        'database.connections.mariadb.username' => 'acct_commons',
        'database.connections.mariadb.password' => PRODUCTION_CHECK_DB_PASSWORD,
        'cache.default' => 'database',
        'queue.default' => 'database',
        'mail.default' => 'log',
        'resources.assets.max_bytes' => 20 * 1024 * 1024,
    ]);
    // PHP as a correctly configured host would report it, not as this test container's PHP does (ADR 0037, decision 64).
    app()->instance(PhpIni::class, new PhpIni(['file_uploads' => '1', 'max_file_uploads' => '20', 'upload_max_filesize' => '20M', 'post_max_size' => '24M']));
}

/**
 * The connection this TEST PROCESS actually runs on: `mariadb` under the ordinary suite, `pgsql`
 * under `./flow test backend --pgsql` (ADR 0014, no SQLite stand-in). Remembered fresh by the
 * `beforeEach()` below, before `asProduction()` — or any test directly setting `database.default` to
 * model production — can overwrite it.
 *
 * Why this matters, and is not merely tidiness: `RefreshDatabase::beginDatabaseTransaction()`
 * registers a `beforeApplicationDestroyed` callback that re-reads `config('database.default')` AT
 * TEARDOWN TIME, not at setup time (`connectionsToTransact()` is called again, fresh, inside the
 * closure), then rolls back and disconnects whatever that name resolves to. Every test in this file
 * models a production host by setting `database.default` to `mariadb`; left that way when the test
 * body returns, that teardown tries to open and roll back a `mariadb` connection this process was
 * never running on under `--pgsql`, which is a real, working connection name it must actually dial —
 * this hung for ~120 seconds per test in CI (`SQLSTATE[HY000] [2006] MySQL server has gone away`) and
 * took the `--pgsql` pass well past the workflow timeout.
 *
 * @param  string|null  $set  pass the real connection to remember it (`beforeEach`, below); omit to
 *                            read it back (`afterEach`, and the direct regression test below).
 */
function realDatabaseConnection(?string $set = null): string
{
    /** @var string|null $remembered */
    static $remembered = null;
    if ($set !== null) {
        $remembered = $set;
    }

    return $remembered ?? throw new LogicException(
        'realDatabaseConnection() was read before any test remembered it — the beforeEach() below did not run.',
    );
}

beforeEach(function () {
    realDatabaseConnection(config()->string('database.default'));
});

// Runs before Laravel's own database-transaction teardown, not after: Pest's tearDown() calls every
// afterEach() hook first and calls parent::tearDown() (where RefreshDatabase rolls back and
// disconnects) only afterward, in a `finally` — so this always restores the real connection before
// that teardown reads it, whether the test itself passed, failed, or threw. This is what makes the
// class of bug above impossible to reintroduce by simply forgetting to reset `database.default`
// somewhere in a new test: nothing about it depends on execution order or a test's own happy path.
afterEach(function () {
    config(['database.default' => realDatabaseConnection()]);
});

/** @return array<string, bool> deferred item name => closed */
function deferredItems(): array
{
    $results = [];
    foreach (app(ProductionReadiness::class)->deferred() as $item) {
        $results[$item->name] = $item->passed;
    }

    return $results;
}

it('passes on a correctly configured production deployment', function () {
    asProduction();

    expect(array_keys(array_filter(readiness(), static fn (bool $passed): bool => ! $passed)))->toBe([]);
});

it('fails on the development configuration this repository ships', function () {
    // The positive control for every case below: the suite's own environment is a development one, and
    // the command must say so rather than wave it through.
    expect(readiness())->not->toContain(true, 'the development configuration passed the production check');
    expect(array_filter(readiness(), static fn (bool $passed): bool => ! $passed))->not->toBe([]);
});

describe('each dangerous value is refused', function () {
    beforeEach(fn () => asProduction());

    it('refuses debug mode', function () {
        config(['app.debug' => true]);
        expect(readiness()['APP_DEBUG is off'])->toBeFalse();
    });

    it('refuses a local or testing environment', function () {
        foreach (['local', 'testing', 'staging'] as $environment) {
            config(['app.env' => $environment]);
            expect(readiness()['APP_ENV is production'] ?? true)->toBeFalse($environment);
        }
    });

    it('refuses the no-op breached-password checker', function () {
        // The single likeliest production mistake: apps/platform/.env.example sets it to `none` for
        // development and CI, and copying that file is how a host ends up accepting known passwords.
        config(['identity.password.compromised_check.driver' => 'none']);
        expect(readiness()['the breached-password check is the real one'])->toBeFalse();
    });

    it('refuses the raised development login limit', function () {
        // The browser suite needs 200 attempts per address; production keeps 30.
        config(['identity.login_throttle.max_attempts_per_ip' => 200]);
        expect(readiness()['the login rate limit is not the raised development one'])->toBeFalse();
    });

    it('refuses the raised development MFA challenge limit', function () {
        // The browser suite presents many second-factor codes from one address and raises this to 200;
        // production keeps 30, so a stolen password is never paired with unlimited guesses at the code.
        config(['identity.credential_throttle.mfa_challenge.per_ip' => 200]);
        expect(readiness()['the MFA challenge rate limit is not the raised development one'])->toBeFalse();
    });

    it('accepts the ordinary production MFA challenge limit, and refuses one just above it', function () {
        expect(readiness()['the MFA challenge rate limit is not the raised development one'])->toBeTrue();

        config(['identity.credential_throttle.mfa_challenge.per_ip' => 31]);
        expect(readiness()['the MFA challenge rate limit is not the raised development one'])->toBeFalse();
    });

    it('refuses an http:// application URL', function () {
        config(['app.url' => 'http://commons.flowlifeglobal.org']);
        expect(readiness()['APP_URL is an https:// address'])->toBeFalse();
    });

    it('refuses a test-speed bcrypt cost', function () {
        config(['hashing.bcrypt.rounds' => 4]);
        expect(readiness()['password hashing is not at a development cost'])->toBeFalse();
    });

    it('refuses a cross-origin browser allow-list', function () {
        config(['cors.allowed_origins' => ['https://somewhere.example']]);
        expect(readiness()['no cross-origin browser client is allowed'])->toBeFalse();
    });

    it('refuses a reset endpoint that answers as fast as its work allows', function () {
        config(['identity.password_reset.response_floor_ms' => 0]);
        expect(readiness()['"I forgot my password" pads its response'])->toBeFalse();
    });

    it('refuses a maintenance driver the web server cannot see', function () {
        // `php artisan down` under the cache driver writes a database row, not storage/framework/down,
        // so Apache's maintenance arm never fires and the Console keeps serving while the API is down.
        config(['app.maintenance.driver' => 'cache']);
        expect(readiness()['maintenance mode is driven by a file, which the web server can see'])->toBeFalse();
    });

    it('refuses an APP_KEY of the wrong size, and a missing one', function () {
        foreach (['base64:'.base64_encode('too-short'), 'base64:not-base64-at-all!!', 'plain-string-key'] as $key) {
            config(['app.key' => $key]);
            expect(readiness()['APP_KEY is a key of the right size for the cipher'])->toBeFalse($key);
        }

        config(['app.key' => '']);
        expect(readiness()['APP_KEY is set'])->toBeFalse();
    });

    it('accepts both forms of a correctly sized key', function () {
        config(['app.key' => 'base64:'.base64_encode(PRODUCTION_CHECK_KEY_BYTES)]);
        expect(readiness()['APP_KEY is a key of the right size for the cipher'])->toBeTrue();

        config(['app.key' => PRODUCTION_CHECK_KEY_BYTES]);
        expect(readiness()['APP_KEY is a key of the right size for the cipher'])->toBeTrue();
    });

    it('refuses missing database credentials', function () {
        foreach (['database', 'username', 'password'] as $setting) {
            asProduction();
            config(["database.connections.mariadb.{$setting}" => '']);
            expect(readiness()['the database credentials are all supplied'])->toBeFalse($setting);
        }
    });

    it('refuses the development database credentials', function () {
        foreach (['flowlife_dev_only', 'flowlife_root_dev_only'] as $password) {
            config(['database.connections.mariadb.password' => $password]);
            expect(readiness()['no development credential reached production'])->toBeFalse($password);
        }
    });

    it('refuses a database engine production does not run', function () {
        // Restored at once, to the connection this test PROCESS actually runs on — not a hardcoded
        // 'mariadb', which would itself be the exact bug realDatabaseConnection()/afterEach() above
        // exist to catch under `--pgsql`. Belt-and-braces: afterEach() would fix this regardless, but
        // there is no reason for 'sqlite' to survive even until then.
        config(['database.default' => 'sqlite']);
        $result = readiness()['the database connection is the engine production runs'];
        config(['database.default' => realDatabaseConnection()]);

        expect($result)->toBeFalse();
    });

    it('refuses a cache or queue that needs a service the host does not run', function () {
        foreach (['cache.default' => 'redis', 'queue.default' => 'redis'] as $key => $value) {
            asProduction();
            config([$key => $value]);
            expect(readiness()['nothing depends on a service this host does not run'])->toBeFalse($key);
        }
    });

    it('refuses the unapproved PHP mail() transport', function () {
        // Tested on the host on 2026-09-21: it delivered, unsigned and not DMARC-aligned.
        config(['mail.default' => 'sendmail']);
        expect(readiness()['the configured mail transport is one production currently approves (log or smtp)'])->toBeFalse();
    });

    it('refuses every weakening of the session cookie', function () {
        // These are fixed in config/security-critical files rather than read from the environment, so
        // this is the check that notices someone editing them.
        foreach ([
            'session.driver' => 'file',
            'session.secure' => false,
            'session.http_only' => false,
            'session.domain' => '.flowlifeglobal.org',
            'session.same_site' => 'none',
            'session.lifetime' => 480,
            'session.cookie' => 'flowlife-session',
        ] as $key => $value) {
            asProduction();
            config([$key => $value]);
            expect(array_filter(readiness(), static fn (bool $passed): bool => ! $passed))
                ->not->toBe([], "changing {$key} was not noticed");
        }
    });
});

describe('Resources files (ADR 0037, decision 64)', function () {
    $withPhp = function (array $ini): void {
        /** @var array<string, string> $ini */
        app()->instance(PhpIni::class, new PhpIni(array_merge(['file_uploads' => '1', 'max_file_uploads' => '20', 'upload_max_filesize' => '20M', 'post_max_size' => '24M'], $ini)));
    };
    $failing = fn (): array => array_keys(array_filter(readiness(), static fn (bool $passed): bool => ! $passed));

    it('refuses PHP limits below the largest Resources file, as the development defaults are', function () use ($withPhp, $failing) {
        asProduction();
        $withPhp(['upload_max_filesize' => '2M', 'post_max_size' => '8M']);

        expect($failing())->toBe([
            'PHP\'s upload_max_filesize admits the largest Resources file',
            'PHP\'s post_max_size admits the largest Resources upload, with room for the rest of the form',
        ]);
    });

    it('asks post_max_size for room beyond the file itself, and accepts PHP\'s "unlimited"', function () use ($withPhp, $failing) {
        asProduction();
        $withPhp(['post_max_size' => '20M']);
        expect($failing())->toBe(['PHP\'s post_max_size admits the largest Resources upload, with room for the rest of the form']);

        $withPhp(['post_max_size' => '21M']);
        expect($failing())->toBe([]);

        $withPhp(['upload_max_filesize' => '0', 'post_max_size' => '0']);
        expect($failing())->toBe([]);
    });

    it('judges the limit the application will enforce: a lowered setting passes on a smaller host, and an absurd one is bounded', function () use ($withPhp, $failing) {
        asProduction();
        $withPhp(['upload_max_filesize' => '8M', 'post_max_size' => '9M']);
        config(['resources.assets.max_bytes' => 8 * 1024 * 1024]);
        expect($failing())->toBe([]);

        config(['resources.assets.max_bytes' => PHP_INT_MAX]); // enforced as the 100 MiB ceiling
        $withPhp(['upload_max_filesize' => '100M', 'post_max_size' => '101M']);
        expect($failing())->toBe([])
            ->and(ProductionReadiness::RESOURCES_ASSET_FLOOR)->toBe(AssetLimits::FLOOR)
            ->and(ProductionReadiness::RESOURCES_ASSET_CEILING)->toBe(AssetLimits::CEILING);
    });

    it('refuses a host with uploads switched off, or a limit it cannot read', function () use ($withPhp, $failing) {
        asProduction();
        $withPhp(['file_uploads' => '0']);
        expect($failing())->toBe(['PHP accepts file uploads']);

        $withPhp(['upload_max_filesize' => 'lots']);
        expect($failing())->toBe(['PHP\'s upload_max_filesize admits the largest Resources file']);
    });

    it('refuses a Resources store that is public, served, has a URL, or lives under public/', function (string $case) use ($failing) {
        asProduction();
        $disk = match ($case) {
            'under public/' => ['root' => public_path('resources')],
            'the public directory itself' => ['root' => public_path()],
            'with a URL' => ['url' => 'https://commons.flowlifeglobal.org/files'],
            'served' => ['serve' => true],
            default => ['visibility' => 'public'],
        };
        config(['filesystems.disks.resources' => [...config()->array('filesystems.disks.resources'), ...$disk]]);

        expect($failing())->toBe(['the Resources file store is private and outside the public directory']);
    })->with(['under public/', 'the public directory itself', 'with a URL', 'served', 'public visibility']);

    it('accepts the store exactly as config/filesystems.php ships it', function () use ($failing) {
        asProduction();

        expect($failing())->toBe([])
            ->and(config('filesystems.disks.resources.root'))->toBe(storage_path('app/private/resources'));
    });

    it('lists the web server\'s PHP limits and the Resource files\' backup as owner verifications', function () {
        asProduction();

        commandProductionReadiness('security:production-check')
            ->expectsOutputToContain('upload_max_filesize and post_max_size admit RESOURCES_ASSET_MAX_BYTES')
            ->expectsOutputToContain('Resource files (shared/storage/app/private/resources) are backed up together with the database dump')
            ->assertSuccessful();
    });
});

it('names what it cannot see, and does not pretend otherwise', function () {
    // A green result must never read as "the host is ready". The hosting account's capabilities —
    // HTTPS, mod_headers, the cron entry, the web server's PHP — cannot be established by reading
    // configuration, and the command says so every time it runs.
    asProduction();

    commandProductionReadiness('security:production-check')
        ->expectsOutputToContain('Not checked here')
        ->expectsOutputToContain('mod_headers')
        ->expectsOutputToContain('schedule:run')
        ->expectsOutputToContain('HTTPS is active')
        ->expectsOutputToContain('still outstanding')
        ->assertSuccessful();
});

it('never fails on expose_php, and says why', function () {
    // Regression: the CLI process's own ini is not necessarily the web server's — cPanel commonly ships
    // separate ea-php83 (web) and ea-php83-cli packages with independent php.ini files — so failing the
    // command on THIS process's expose_php reading would block a deployment over a fact about the wrong
    // process. It would also be blocking on the wrong FACT: the first deployment rehearsal (2026-09-22)
    // found X-Powered-By reaching every PHP response despite this same ini reading On, so the ini value
    // — on either SAPI — was never what decided this; public/.htaccess (ADR 0026) is. This stays
    // informational, never a `checks()` entry, regardless of what this test process's own ini says.
    asProduction();

    expect(readiness())->not->toHaveKey('the version of PHP is not announced');

    commandProductionReadiness('security:production-check')
        ->expectsOutputToContain('expose_php reads')
        ->expectsOutputToContain('not the web server')
        ->assertSuccessful();
});

it('exits non-zero so a deployment script can stop on it', function () {
    config(['app.debug' => true]);

    commandProductionReadiness('security:production-check')->assertFailed();
});

describe('outbound mail: deferred, exactly as documented', function () {
    it('does not fail a deployment whose mail is deliberately not configured yet', function () {
        // Deployment is not blocked by mail: the first administrator's token goes out of band over SSH.
        asProduction();
        config(['mail.default' => 'log']);

        expect(array_keys(array_filter(readiness(), static fn (bool $passed): bool => ! $passed)))->toBe([]);
        commandProductionReadiness('security:production-check')->assertSuccessful();
    });

    it('reports it as open on every run, so it cannot be forgotten', function () {
        asProduction();
        config(['mail.default' => 'log']);

        expect(deferredItems()['outbound mail is authenticated and its deliverability is verified'])->toBeFalse();

        commandProductionReadiness('security:production-check')
            ->expectsOutputToContain('Deliberately open')
            ->expectsOutputToContain('outbound mail is authenticated')
            ->expectsOutputToContain('1 item(s) remain deliberately open')
            ->assertSuccessful();
    });

    it('never closes the item, however plausible the configured transport looks', function () {
        // Regression: the earlier version of this check closed on ANY mailer other than log/array,
        // including an smtp transport with real-looking host/credentials that had never actually been
        // verified — reporting "closed" from configuration SHAPE alone, when what the item claims
        // (SPF alignment, DKIM, DMARC, real delivery) can only be established by sending real mail and
        // checking where it landed. This can never be "closed" by this command, on any configuration.
        asProduction();
        foreach (['smtp', 'ses', 'postmark', 'resend', 'log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);
            expect(deferredItems()['outbound mail is authenticated and its deliverability is verified'])
                ->toBeFalse("mailer '$mailer' must not close the item");
        }
    });

    it('describes a configured transport differently from an unconfigured one, without closing either', function () {
        asProduction();

        config(['mail.default' => 'log']);
        commandProductionReadiness('security:production-check')
            ->expectsOutputToContain('so nothing is sent')
            ->assertSuccessful();

        // A structurally VALID smtp configuration, not merely `mail.default => 'smtp'`: since this
        // package, "configured" for `deferred()`'s wording and "passes the structural checks" for
        // `checks()` are two different questions, and this asserts the command succeeds only once
        // both are true — bare `smtp` with no host/credentials fails the structural checks below now,
        // by design.
        asValidProductionSmtp();
        commandProductionReadiness('security:production-check')
            ->expectsOutputToContain('necessary but not')
            ->assertSuccessful();
    });

    it('still fails the deployment on sendmail through the required check, independent of the deferred item', function () {
        // sendmail is refused outright (it is the specific transport measured unsigned and DMARC-
        // misaligned on 2026-09-21), and that refusal does not depend on the deferred item's wording.
        asProduction();
        config(['mail.default' => 'sendmail']);

        expect(readiness()['the configured mail transport is one production currently approves (log or smtp)'])->toBeFalse();
        expect(deferredItems()['outbound mail is authenticated and its deliverability is verified'])->toBeFalse();
        commandProductionReadiness('security:production-check')->assertFailed();
    });
});

/** The name `ProductionReadiness::mail()` gives the allowlist check, reused across the mail describe blocks. */
const MAIL_APPROVED_TRANSPORT_CHECK = 'the configured mail transport is one production currently approves (log or smtp)';

/** A structurally correct smtp configuration, as a real provider (never named) would hand it out. */
function asValidProductionSmtp(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.transactional-provider.example',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'commons-account',
        'mail.mailers.smtp.password' => 'a-provider-issued-secret',
        'mail.mailers.smtp.scheme' => null,
        'mail.mailers.smtp.require_tls' => true,
        'mail.mailers.smtp.timeout' => 10.0,
        'mail.from.address' => 'commons@mail.commons.flowlifeglobal.org',
    ]);
}

describe('outbound mail: fail-closed transport allowlist (ADR 0031)', function () {
    beforeEach(fn () => asProduction());

    it('accepts log', function () {
        config(['mail.default' => 'log']);
        expect(readiness()[MAIL_APPROVED_TRANSPORT_CHECK])->toBeTrue();
    });

    it('rejects array as a production transport, even though it is safe for tests', function () {
        config(['mail.default' => 'array']);
        expect(readiness()[MAIL_APPROVED_TRANSPORT_CHECK])->toBeFalse();
    });

    it('rejects an unrecognised mailer rather than assuming it is safe', function () {
        foreach (['mailgun', 'ses', 'postmark', 'resend', 'failover', 'roundrobin', 'typo-transport'] as $mailer) {
            config(['mail.default' => $mailer]);
            expect(readiness()[MAIL_APPROVED_TRANSPORT_CHECK])->toBeFalse($mailer);
        }
    });

    it('accepts a structurally complete, TLS-required smtp configuration', function () {
        asValidProductionSmtp();

        expect(array_keys(array_filter(readiness(), static fn (bool $passed): bool => ! $passed)))->toBe([]);
    });

    it('accepts implicit TLS on port 465 without require_tls', function () {
        asValidProductionSmtp();
        config(['mail.mailers.smtp.port' => 465, 'mail.mailers.smtp.require_tls' => false]);

        expect(readiness()['an smtp mailer requires TLS rather than merely allowing it'])->toBeTrue();
    });

    it('rejects the development Mailpit host as production smtp', function () {
        asValidProductionSmtp();
        config(['mail.mailers.smtp.host' => 'mailpit']);
        expect(readiness()['an smtp mailer names a real host, not the development Mailpit one'])->toBeFalse();

        foreach (['127.0.0.1', 'localhost', '::1', ''] as $host) {
            config(['mail.mailers.smtp.host' => $host]);
            expect(readiness()['an smtp mailer names a real host, not the development Mailpit one'])->toBeFalse($host);
        }
    });

    it('rejects Mailpit\'s fixed development port as production smtp', function () {
        asValidProductionSmtp();
        config(['mail.mailers.smtp.port' => 1025]);
        expect(readiness()['an smtp mailer uses a real submission port, not the development Mailpit one'])->toBeFalse();
    });

    it('rejects missing smtp authentication credentials', function () {
        foreach (['mail.mailers.smtp.username', 'mail.mailers.smtp.password'] as $key) {
            asValidProductionSmtp();
            config([$key => null]);
            expect(readiness()['an smtp mailer supplies authentication credentials'])->toBeFalse($key);
        }
    });

    it('rejects a missing or placeholder sender identity', function () {
        foreach (['', 'hello@example.com', 'not-an-address'] as $address) {
            asValidProductionSmtp();
            config(['mail.from.address' => $address]);
            expect(readiness()['an smtp mailer has a real sender identity configured'])->toBeFalse($address);
        }
    });

    it('rejects smtp that neither requires TLS nor implies it, so a downgrade cannot send in plaintext', function () {
        asValidProductionSmtp();
        config(['mail.mailers.smtp.require_tls' => false]); // still port 587
        expect(readiness()['an smtp mailer requires TLS rather than merely allowing it'])->toBeFalse();
    });

    it('rejects a config that disables certificate verification', function () {
        asValidProductionSmtp();
        config(['mail.mailers.smtp.verify_peer' => false]);
        expect(readiness()['an smtp mailer does not disable certificate verification'])->toBeFalse();
    });

    it('rejects an unset, zero, negative or excessive smtp timeout', function () {
        foreach ([null, 0, 0.0, -1, 31, 999] as $timeout) {
            asValidProductionSmtp();
            config(['mail.mailers.smtp.timeout' => $timeout]);
            expect(readiness()['an smtp mailer\'s timeout is set and bounded'])->toBeFalse(var_export($timeout, true));
        }
    });

    it('accepts the timeout at its boundary and just under it', function () {
        foreach ([0.001, 1, 10, 30, 30.0] as $timeout) {
            asValidProductionSmtp();
            config(['mail.mailers.smtp.timeout' => $timeout]);
            expect(readiness()['an smtp mailer\'s timeout is set and bounded'])->toBeTrue((string) $timeout);
        }
    });

    it('never fails a log-configured deployment on any smtp-only structural fact', function () {
        // `! $isSmtp || …`: every smtp-shape check is vacuously satisfied when the transport is not
        // smtp, so leftover or absent smtp.* config never adds a second, surprising failure mode to a
        // deployment that has correctly deferred mail with `log`.
        asProduction();
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.port' => null]);

        expect(array_keys(array_filter(readiness(), static fn (bool $passed): bool => ! $passed)))->toBe([]);
    });
});

describe('secrets', function () {
    it('never prints APP_KEY or the database password, when they pass', function () {
        asProduction();

        commandProductionReadiness('security:production-check')
            ->doesntExpectOutputToContain(base64_encode(PRODUCTION_CHECK_KEY_BYTES))
            ->doesntExpectOutputToContain(PRODUCTION_CHECK_KEY_BYTES)
            ->doesntExpectOutputToContain(PRODUCTION_CHECK_DB_PASSWORD)
            ->assertSuccessful();
    });

    it('never prints them when they fail either, only what is wrong with them', function () {
        asProduction();
        $shortKey = 'base64:'.base64_encode('sixteen-bytes!!!');
        config(['app.key' => $shortKey, 'database.connections.mariadb.password' => 'flowlife_dev_only']);

        commandProductionReadiness('security:production-check')
            ->expectsOutputToContain('APP_KEY is a key of the right size for the cipher')
            ->expectsOutputToContain('decodes to 16 bytes')
            ->expectsOutputToContain('no development credential reached production')
            ->doesntExpectOutputToContain($shortKey)
            ->doesntExpectOutputToContain('sixteen-bytes')
            ->doesntExpectOutputToContain('flowlife_dev_only')
            ->assertFailed();
    });
});

it('observes and never mutates: no key is generated, no file is written, no directory is made', function () {
    asProduction();
    config(['app.key' => '']);
    $env = base_path('.env');
    $before = is_file($env) ? (string) file_get_contents($env) : null;

    commandProductionReadiness('security:production-check')->assertFailed();

    expect(config('app.key'))->toBe('')
        ->and(is_file($env) ? (string) file_get_contents($env) : null)->toBe($before);

    // Nothing in the implementation writes, spawns or connects.
    $code = php_strip_whitespace(app_path('Modules/Security/Application/ProductionReadiness.php'))
        .php_strip_whitespace(app_path('Modules/Security/Infrastructure/Console/ProductionReadinessCommand.php'));
    expect($code)->not->toMatch('/\b(file_put_contents|fwrite|mkdir|chmod|unlink|touch|exec|shell_exec|proc_open|curl_init|fsockopen|Artisan::call|Mail::)\b/');
});

describe('test-harness isolation: the connection this process actually runs on', function () {
    // Regression for a CI hang, not a hypothetical: every test above models a production host by
    // setting `database.default` to `mariadb`. Under `./flow test backend --pgsql`, leaving it that
    // way let Laravel's own RefreshDatabase teardown try to roll back a connection this process never
    // opened, which hung for ~120s per test and failed with "MySQL server has gone away" — 28 of 30
    // tests in this file, well past the CI workflow's 25-minute timeout.
    //
    // These two tests exercise the restoration primitive itself, directly and synchronously, so a
    // regression is a fast, clear assertion failure here rather than a 120-second timeout rediscovered
    // only under `--pgsql`. The end-to-end proof that the wiring above actually prevents the hang is
    // running this whole file under both engines (`./flow test backend [--pgsql] --filter=ProductionReadinessTest`).
    it('restores the connection this process runs on after asProduction() models a different one', function () {
        $real = realDatabaseConnection();
        expect(config('database.default'))->toBe($real, 'nothing has touched it yet in this test');

        asProduction();
        expect(config('database.default'))->toBe('mariadb');

        config(['database.default' => realDatabaseConnection()]); // what afterEach() does automatically
        expect(config('database.default'))->toBe($real, 'must be the connection this PROCESS runs on, never a hardcoded value');
    });

    it('restores correctly even when the scoped work throws between the mutation and the restore', function () {
        // Pest's own tearDown() guarantees afterEach() above runs whether a test passes, fails or
        // throws (it calls every afterEach() hook in a try, then Laravel's teardown — the RefreshDatabase
        // rollback included — in a finally). What THIS test proves directly is the one-line restoration
        // that hook performs: it is exactly `config(['database.default' => realDatabaseConnection()])`,
        // reproduced here after an exception, not conditioned on how execution reached it.
        $real = realDatabaseConnection();

        try {
            asProduction();
            throw new RuntimeException('modeling production readiness threw mid-test');
        } catch (RuntimeException) {
        } finally {
            config(['database.default' => realDatabaseConnection()]);
        }

        expect(config('database.default'))->toBe($real);
    });
});
