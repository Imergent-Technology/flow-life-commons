<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AccountSecurityGeneration;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\EnableAccount;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Http\ConsoleSession;
use App\Modules\Identity\Http\EnforceSecurityGeneration;
use App\Shared\Domain\AccountId;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Recovery;
use Tests\Support\Totp;

/*
 * The Account security generation (ADR 0025), the platform's one invariant for "authentication
 * established from stale security state":
 *
 *     An authenticated session carries the security generation its proof was checked against,
 *     and has authority only while that is still the Account's current generation.
 *
 * These are the deterministic proofs. The real two-process races are in
 * tests/Concurrency/StaleAuthenticationRaceTest.php, on both engines.
 */

function generation(AccountId $account): int
{
    $value = app(AccountSecurityGeneration::class)->current($account);
    expect($value)->toBeInt();
    assert(is_int($value));

    return $value;
}

/** A request with a started session, as the transport hands one to ConsoleSession. */
function startedRequest(): Request
{
    $store = app('session')->driver();
    assert($store instanceof Store);
    $request = Request::create('/api/v1/me');
    $request->setLaravelSession($store);
    $request->session()->start();

    return $request;
}

/** Session rows that belong to an Account (an anonymous row is not a sign-in). */
function attributedSessions(): int
{
    return DB::table('sessions')->whereNotNull('user_id')->count();
}

/** What the single live session row holds under the given key. */
function sessionPayload(string $key): mixed
{
    $rows = DB::table('sessions')->pluck('payload')->all();
    expect($rows)->toHaveCount(1);
    assert(is_string($rows[0]));
    $payload = json_decode(base64_decode($rows[0], true) ?: '[]', true);

    return is_array($payload) ? ($payload[$key] ?? null) : null;
}

describe('what advances it', function () {
    it('advances when an account is disabled', function () {
        $account = Identity::savedActiveAccount();
        $before = generation($account->id);

        app(DisableAccount::class)($account->id);

        expect(generation($account->id))->toBe($before + 1);
    });

    it('advances when a password is reset', function () {
        $account = Identity::savedActiveAccount();
        $before = generation($account->id);

        Recovery::reset($account->email->value, Recovery::tokenFor($account), 'a brand new long passphrase')->assertNoContent();

        expect(generation($account->id))->toBe($before + 1);
    });

    it('advances when a second factor is administratively reset', function () {
        $account = Mfa::guardian();
        Mfa::enroll($account);
        $before = generation($account->id);

        app(ResetMultiFactor::class)->fromServer($account->id);

        expect(generation($account->id))->toBe($before + 1);
    });

    it('advances when a signed-in person changes their own password', function () {
        [$console, $account] = Mfa::signedIn();
        $before = generation($account->id);

        $console->post(Console::API.'/password/change', [
            'current_password' => Identity::PASSWORD,
            'password' => 'another entirely different passphrase',
            'password_confirmation' => 'another entirely different passphrase',
        ])->assertNoContent();

        expect(generation($account->id))->toBe($before + 1);
    });

    it('advances when an authenticator is replaced', function () {
        [$console, $account, $factor] = Mfa::signedIn();
        $before = generation($account->id);

        $console->post(Console::API.'/mfa/authenticator', [
            'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
        ])->assertOk();
        $secret = Mfa::text($console->post(Console::API.'/mfa/authenticator', [
            'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
        ])->json('secret'));
        $console->post(Console::API.'/mfa/authenticator/confirm', ['code' => Totp::next($secret)])->assertNoContent();

        expect(generation($account->id))->toBe($before + 1);
    });

    it('leaves it alone for operations that invalidate no authentication', function () {
        // Re-enabling grants nothing to an existing session (a disable already revoked and advanced),
        // authorization is live so a role change is not a session concern, regenerating recovery codes
        // replaces no authentication factor, and proving recent verification invalidates nothing.
        [$console, $account, $factor] = Mfa::signedIn();
        $before = generation($account->id);

        Access::grant($account, Role::PlatformAdministrator);
        Access::revoke($account, Role::PlatformAdministrator);
        $console->post(Console::API.'/security/verify', [
            'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
        ])->assertNoContent();
        $console->post(Console::API.'/mfa/recovery-codes', [
            'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
        ])->assertOk();

        expect(generation($account->id))->toBe($before);

        $disabled = Identity::savedDisabledAccount('other@example.org');
        $afterDisable = generation($disabled->id);
        app(EnableAccount::class)($disabled->id);
        expect(generation($disabled->id))->toBe($afterDisable);
    });
});

describe('what a session is bound to', function () {
    it('binds a session to the generation its proof was checked against', function () {
        [, $account] = Mfa::signedIn();

        expect(sessionPayload(ConsoleSession::SECURITY_GENERATION))->toBe(generation($account->id));
    });

    it('binds a password-only sign-in too', function () {
        // No Console access, so no second factor is due: the straight one-step sign-in.
        $account = Identity::savedActiveAccount();
        $console = new Console;
        $console->login($account->email->value, Identity::PASSWORD)->assertOk();

        expect(sessionPayload(ConsoleSession::SECURITY_GENERATION))->toBe(generation($account->id));
    });
});

describe('a superseded session', function () {
    /*
     * The documented boundary (ADR 0025). A session row CAN exist for a proof that has just been
     * superseded — the transport writes it after the response is prepared, so a reset landing in that
     * last instant is not prevented. It is never USABLE: the first request that presents it is refused,
     * and the session is destroyed. These prove that, rather than the weaker "it is unlikely".
     */
    it('is refused on its very next request, and the row is gone', function () {
        [$console, $account] = Mfa::signedIn();
        $console->me()->assertOk();
        expect(attributedSessions())->toBe(1);

        // Exactly what a reset committing in that last instant leaves behind: a live row whose
        // generation the Account has moved past.
        app(AccountSecurityGeneration::class)->advance($account->id);

        $console->me()->assertUnauthorized();
        expect(attributedSessions())->toBe(0)
            ->and(Identity::context(Identity::events('session.superseded')[0]))
            ->toBe(['held_generation' => 1, 'current_generation' => 2]);
    });

    it('cannot reach a capability-gated route either', function () {
        Mfa::registerProbeRoutes();
        [$console, $account] = Mfa::signedInAdmin();
        $console->get(Console::API.'/zz/console')->assertOk();

        app(AccountSecurityGeneration::class)->advance($account->id);

        $console->get(Console::API.'/zz/console')->assertUnauthorized();
    });

    it('fails safe when the session holds no generation at all', function () {
        // A session written before the mechanism existed. It must read as superseded, never as exempt.
        [$console] = Mfa::signedIn();
        $console->tamperSession(fn ($store) => $store->forget(ConsoleSession::SECURITY_GENERATION));

        $console->me()->assertUnauthorized();
        expect(Identity::events('session.superseded'))->toHaveCount(1);
    });

    it('fails safe when the session holds something that is not an integer', function () {
        [$console] = Mfa::signedIn();
        $console->tamperSession(fn ($store) => $store->put(ConsoleSession::SECURITY_GENERATION, '1'));

        $console->me()->assertUnauthorized();
    });

    it('is not resurrected by the request that was in flight when it was revoked', function () {
        // A framework behaviour ADR 0025 relies on, measured rather than assumed, because the whole
        // revocation design rests on it. A test-only route deletes the Account's session row from INSIDE
        // a request it is making, exactly as a concurrent reset would. Laravel's database session handler
        // then persists that request's session with an UPDATE (not an upsert), which matches nothing, so
        // the revoked row stays revoked. Were that ever to become an upsert, the resurrected row would
        // carry a stale generation and be refused anyway — but this pins the simpler fact.
        [$console, $account] = Mfa::signedIn();
        $id = $account->id;
        Route::middleware(['stateful', 'auth:web'])->get('/api/v1/zz/revoke-mid-request', function () use ($id) {
            DB::table('sessions')->where('user_id', $id->value)->delete();
            app(AccountSecurityGeneration::class)->advance($id);

            return response()->json(['ok' => true]);
        });

        $console->get(Console::API.'/zz/revoke-mid-request')->assertOk();

        expect(attributedSessions())->toBe(0);
        $console->me()->assertUnauthorized();
    });
});

describe('the sessions that must survive', function () {
    it('keeps the caller signed in after they change their own password', function () {
        [$console, $account] = Mfa::signedIn();

        $console->post(Console::API.'/password/change', [
            'current_password' => Identity::PASSWORD,
            'password' => 'another entirely different passphrase',
            'password_confirmation' => 'another entirely different passphrase',
        ])->assertNoContent();

        $console->me()->assertOk();
        expect(sessionPayload(ConsoleSession::SECURITY_GENERATION))->toBe(generation($account->id))
            ->and(Identity::events('session.superseded'))->toBe([]);
    });

    it('keeps the caller signed in after they replace their authenticator', function () {
        [$console, $account, $factor] = Mfa::signedIn();

        $secret = Mfa::text($console->post(Console::API.'/mfa/authenticator', [
            'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
        ])->json('secret'));
        $console->post(Console::API.'/mfa/authenticator/confirm', ['code' => Totp::next($secret)])->assertNoContent();

        $console->me()->assertOk();
        expect(sessionPayload(ConsoleSession::SECURITY_GENERATION))->toBe(generation($account->id));
    });
});

describe('establishment refuses a superseded proof outright', function () {
    /*
     * The Phase 8 ordering, deterministically: the proof has committed, the reset commits, and only then
     * does the transport establish. No session is created at all — prevention, not cleanup.
     */
    it('creates no session when the generation moved between the proof and establishment', function () {
        $account = Mfa::guardian();
        Mfa::enroll($account);
        $proved = generation($account->id);

        app(ResetMultiFactor::class)->fromServer($account->id); // the reset commits in the window

        $request = startedRequest();

        expect(app(ConsoleSession::class)->establish($request, $account->id, $proved, secondFactor: true))->toBeFalse()
            ->and($request->session()->get(ConsoleSession::SECURITY_GENERATION))->toBeNull();
    });

    it('still establishes when nothing moved', function () {
        // The control: a refuse-everything implementation would pass the test above.
        $account = Mfa::guardian();
        Mfa::enroll($account);

        expect(app(ConsoleSession::class)->establish(startedRequest(), $account->id, generation($account->id), secondFactor: true))->toBeTrue();
    });

    it('tells a challenge that an advance overtook that the sign-in expired, over real HTTP', function () {
        // The Phase 8 interleaving through the whole request pipeline. The double controls only WHEN the
        // generation advances — the instant after the challenge's transaction read it and before the
        // transport establishes, which is the window itself — and delegates everything else to the real
        // implementation. Every assertion is about the platform's own behaviour.
        $account = Mfa::guardian();
        $factor = Mfa::enroll($account);
        $console = new Console;
        $console->login($account->email->value, Identity::PASSWORD)->assertStatus(202);

        app()->instance(AccountSecurityGeneration::class, new class(app(AccountSecurityGeneration::class)) implements AccountSecurityGeneration
        {
            private bool $fired = false;

            public function __construct(private AccountSecurityGeneration $inner) {}

            public function current(AccountId $account): ?int
            {
                $value = $this->inner->current($account);
                if (! $this->fired) {
                    $this->fired = true;      // the proof has just read it; a reset commits now
                    $this->inner->advance($account);
                }

                return $value;
            }

            public function advance(AccountId $account): int
            {
                return $this->inner->advance($account);
            }
        });

        // The same undifferentiated answer as any other half-finished sign-in that cannot continue.
        $console->challenge($factor['secret'])->assertUnauthorized()
            ->assertJson(['message' => 'This sign-in has expired. Sign in again.']);
        $console->me()->assertUnauthorized();
        expect(attributedSessions())->toBe(0);
    });
});

it('checks the generation before authentication, never after', function () {
    // Order matters: a superseded session must never satisfy the guard, even for one request.
    $priority = (fn (): mixed => $this->middlewarePriority)->call(app(Kernel::class));
    assert(is_array($priority));
    $generation = array_search(EnforceSecurityGeneration::class, $priority, true);
    $authenticate = array_search(AuthenticatesRequests::class, $priority, true);

    expect($generation)->toBeInt()->and($authenticate)->toBeInt();
    assert(is_int($generation) && is_int($authenticate));
    expect($generation < $authenticate)->toBeTrue('the security-generation check must run before authentication');
});
