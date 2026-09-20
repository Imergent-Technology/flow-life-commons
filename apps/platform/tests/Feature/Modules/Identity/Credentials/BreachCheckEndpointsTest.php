<?php

declare(strict_types=1);

use App\Modules\Identity\Application\CompromisedPasswords;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Infrastructure\Password\PwnedPasswordsRange;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BreachService;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Passwords;
use Tests\Support\Recovery;

use function Pest\Laravel\postJson;

/*
 * The breached-password check as the PLATFORM uses it: the real PwnedPasswordsRange adapter, behind
 * each of the three endpoints that set a password, with the remote service faked. The adapter's own
 * behaviour is in PwnedPasswordsRangeTest; this proves the composition, deterministically and with no
 * network (TestCase forbids stray requests): what each endpoint does when the service says a password
 * is clean, is breached, is unreachable, errors, or answers nonsense; that the outage is a retryable
 * 503 that changes nothing; that the call is made outside the transaction; and that only a five-
 * character hash prefix leaves the process.
 *
 * The browser journey and CI never reach the real service; a separate, manually run smoke test does
 * (tests/Live).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:30:00');
    // The REAL adapter (phpunit.xml selects the no-op one), over faked HTTP. Limits are lifted so a
    // scenario may try an endpoint several times.
    config([
        'identity.password.compromised_check.driver' => 'pwned_passwords',
        'identity.credential_throttle.invitation_acceptance.per_ip' => 100,
        'identity.credential_throttle.password_reset_completion.per_ip' => 100,
        'identity.credential_throttle.password_reset_completion.per_identifier' => 100,
        'identity.credential_throttle.password_change.per_ip' => 100,
        'identity.credential_throttle.password_change.per_identifier' => 100,
    ]);
});

/** How a scenario decides the fake service's next answer. */
final class ServiceMode
{
    /** @var Closure(Request): mixed */
    public Closure $respond;

    /** @var list<array{url: string, body: string, headers: string, depth: int}> */
    public array $seen = [];

    public function __construct()
    {
        $this->reset();
    }

    /** Back to "the service says everything is clean", and forget what was seen. */
    public function reset(): void
    {
        $this->respond = fn () => Http::response(BreachService::cleanAnswer());
        $this->seen = [];
    }
}

/**
 * Fakes the service ONCE for a test. Http::fake() appends stubs rather than replacing them and the
 * first one wins, so a scenario that loops over endpoints must reset this one fake, not make another.
 */
function fakeService(): ServiceMode
{
    $mode = new ServiceMode;
    Http::fake([BreachService::HOST.'/*' => function (Request $request) use ($mode) {
        $mode->seen[] = [
            'url' => $request->url(),
            'body' => $request->body(),
            'headers' => json_encode($request->headers(), JSON_THROW_ON_ERROR),
            'depth' => DB::transactionLevel(),
        ];

        return ($mode->respond)($request);
    }]);

    return $mode;
}

/**
 * The three endpoints that set a password. Each entry sets up fresh state and returns how to attempt
 * the change with a password, and whether that state has changed.
 *
 * @return array<string, Closure(): array{attempt: Closure(string): TestResponse<covariant Response>, changed: Closure(): bool, success: int}>
 */
function passwordEndpoints(): array
{
    return [
        'invitation acceptance' => function (): array {
            $account = Identity::savedInvitedAccount('invitee@example.org');
            $token = InvitationToken::generate();
            app(AccountInvitationRepository::class)->save(Identity::invitation($account, $token));

            return [
                'attempt' => fn (string $password): TestResponse => postJson('/api/v1/invitations/accept', [
                    'token' => $token->reveal(), 'password' => $password, 'password_confirmation' => $password,
                ]),
                'changed' => fn (): bool => DB::table('accounts')->where('id', $account->id->value)->value('password_hash') !== null,
                'success' => 204,
            ];
        },
        'password reset' => function (): array {
            $account = Identity::savedActiveAccount('resetter@example.org');
            $token = Recovery::tokenFor($account);

            return [
                'attempt' => fn (string $password): TestResponse => Recovery::reset('resetter@example.org', $token, $password),
                'changed' => fn (): bool => DB::table('accounts')->where('id', $account->id->value)->value('password_hash') !== $account->passwordHash,
                'success' => 204,
            ];
        },
        'password change' => function (): array {
            $account = Identity::savedActiveAccount('changer@example.org');
            $console = new Console;
            $console->login('changer@example.org', Identity::PASSWORD)->assertOk();

            return [
                'attempt' => fn (string $password): TestResponse => $console->post(Console::API.'/password/change', [
                    'current_password' => Identity::PASSWORD, 'password' => $password, 'password_confirmation' => $password,
                ]),
                'changed' => fn (): bool => DB::table('accounts')->where('id', $account->id->value)->value('password_hash') !== $account->passwordHash,
                'success' => 204,
            ];
        },
    ];
}

it('uses the real adapter here, so these tests prove the platform and not a stand-in', function () {
    expect(app(CompromisedPasswords::class))->toBeInstanceOf(PwnedPasswordsRange::class);
});

it('accepts a password the service says is clean, on every endpoint', function () {
    $service = fakeService();
    foreach (passwordEndpoints() as $name => $setUp) {
        $service->reset();
        $endpoint = $setUp();

        expect($endpoint['attempt'](Passwords::STRONG)->status())->toBe($endpoint['success'], $name)
            ->and($endpoint['changed']())->toBeTrue($name)
            ->and($service->seen)->toHaveCount(1, $name);
    }
});

it('refuses a password the service says is breached, on every endpoint, and changes nothing', function () {
    $service = fakeService();
    foreach (passwordEndpoints() as $name => $setUp) {
        $service->reset();
        $service->respond = fn () => Http::response(BreachService::answer(Passwords::STRONG));
        $endpoint = $setUp();

        $response = $endpoint['attempt'](Passwords::STRONG);

        expect($response->status())->toBe(422, $name)
            ->and($response->json('errors.password.0'))->toContain('data breaches')
            ->and($endpoint['changed']())->toBeFalse($name);
    }
});

it('does NOT treat an unreachable or failing service as "safe": a retryable 503, and nothing changes', function () {
    // Every way the provider can fail: 4xx, 5xx, nonsense, an empty body, and no connection at all.
    $failures = [
        '400' => fn () => Http::response('bad request', 400),
        '404' => fn () => Http::response('not found', 404),
        '429' => fn () => Http::response('slow down', 429),
        '500' => fn () => Http::response('boom', 500),
        '503' => fn () => Http::response('down', 503),
        '503 with a plausible body' => fn () => Http::response(BreachService::cleanAnswer(), 503),
        'malformed' => fn () => Http::response('<html>Bad gateway</html>'),
        'empty' => fn () => Http::response(''),
        'connection failure' => fn () => throw new ConnectionException('connection refused'),
    ];

    $service = fakeService();
    foreach (passwordEndpoints() as $name => $setUp) {
        $service->reset();
        $endpoint = $setUp();

        foreach ($failures as $why => $failure) {
            $service->respond = $failure;

            $response = $endpoint['attempt'](Passwords::STRONG);

            expect($response->status())->toBe(503, "{$name}: {$why}")
                ->and($response->headers->get('Retry-After'))->toBe('30', "{$name}: {$why}")
                ->and($endpoint['changed']())->toBeFalse("{$name}: {$why} must change nothing");
        }

        // Retryable: the very same request succeeds once the service is back.
        $service->respond = fn () => Http::response(BreachService::cleanAnswer());
        expect($endpoint['attempt'](Passwords::STRONG)->status())->toBe($endpoint['success'], $name)
            ->and($endpoint['changed']())->toBeTrue($name);
    }
});

it('never asks the service about a password the offline rules already refuse', function () {
    $service = fakeService();
    foreach (passwordEndpoints() as $name => $setUp) {
        $service->reset();
        $endpoint = $setUp();

        expect($endpoint['attempt']('too short')->status())->toBe(422, $name)
            ->and($endpoint['attempt'](str_repeat('a', 73))->status())->toBe(422, $name)
            ->and($service->seen)->toBe([], $name);
    }
});

it('sends only the first five characters of the password\'s SHA-1, and nothing else about it', function () {
    $service = fakeService();
    foreach (passwordEndpoints() as $name => $setUp) {
        $service->reset();
        $endpoint = $setUp();
        $prefix = substr(strtoupper(sha1(Passwords::STRONG)), 0, 5);

        $endpoint['attempt'](Passwords::STRONG);

        expect($service->seen)->toHaveCount(1, $name);
        $sent = $service->seen[0];
        $hash = strtoupper(sha1(Passwords::STRONG));
        $everything = strtolower($sent['url'].$sent['body'].$sent['headers']);

        expect($sent['url'])->toBe('https://api.pwnedpasswords.com/range/'.$prefix)
            ->and($sent['body'])->toBe('')
            ->and(str_contains($everything, strtolower(Passwords::STRONG)))->toBeFalse($name)
            ->and(str_contains($everything, rawurlencode(strtolower(Passwords::STRONG))))->toBeFalse($name)
            // Not even a little more of the hash than the prefix.
            ->and(str_contains($everything, strtolower(substr($hash, 0, 8))))->toBeFalse($name)
            ->and(str_contains($everything, strtolower(substr($hash, 5, 10))))->toBeFalse($name);
    }
});

it('makes the call before the credential transaction opens, never inside it', function () {
    $service = fakeService();
    foreach (passwordEndpoints() as $name => $setUp) {
        $service->reset();
        $endpoint = $setUp();
        $baseline = DB::transactionLevel();

        $endpoint['attempt'](Passwords::STRONG);

        expect(array_column($service->seen, 'depth'))->toBe([$baseline], $name);
    }
});
