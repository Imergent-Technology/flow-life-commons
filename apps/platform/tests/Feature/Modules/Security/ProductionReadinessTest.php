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

/** Configuration as a correct production host would have it. */
function asProduction(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://commons.flowlifeglobal.org',
        'identity.password.compromised_check.driver' => 'pwned_passwords',
        'hashing.bcrypt.rounds' => 12,
        'identity.login_throttle.max_attempts_per_ip' => 30,
        'identity.password_reset.response_floor_ms' => 1500,
        'cors.allowed_origins' => [],
    ]);
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
