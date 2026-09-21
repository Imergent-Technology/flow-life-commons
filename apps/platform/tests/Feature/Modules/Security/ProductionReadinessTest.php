<?php

declare(strict_types=1);

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
        'identity.password_reset.response_floor_ms' => 1500,
        'cors.allowed_origins' => [],
        'database.default' => 'mariadb',
        'database.connections.mariadb.database' => 'acct_commons',
        'database.connections.mariadb.username' => 'acct_commons',
        'database.connections.mariadb.password' => PRODUCTION_CHECK_DB_PASSWORD,
        'cache.default' => 'database',
        'queue.default' => 'database',
        'mail.default' => 'log',
    ]);
}

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
        // Restored at once: the suite's own transaction teardown uses the default connection too.
        config(['database.default' => 'sqlite']);
        $result = readiness()['the database connection is the engine production runs'];
        config(['database.default' => 'mariadb']);

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
        expect(readiness()['the unapproved PHP mail() transport is not in use'])->toBeFalse();
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

        expect(deferredItems()['outbound mail is configured with an authenticated transport'])->toBeFalse();

        commandProductionReadiness('security:production-check')
            ->expectsOutputToContain('Deliberately open')
            ->expectsOutputToContain('outbound mail is configured with an authenticated transport')
            ->expectsOutputToContain('1 item(s) remain deliberately open')
            ->assertSuccessful();
    });

    it('closes the item only when a real transport is configured, and never by approving mail()', function () {
        asProduction();
        config(['mail.default' => 'smtp']);
        expect(deferredItems()['outbound mail is configured with an authenticated transport'])->toBeTrue();

        // sendmail would "close" the deferred item and fail the required one: the required check wins.
        config(['mail.default' => 'sendmail']);
        expect(readiness()['the unapproved PHP mail() transport is not in use'])->toBeFalse();
        commandProductionReadiness('security:production-check')->assertFailed();
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
