<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Infrastructure\Persistence\AccountRecord;
use App\Shared\Domain\AccountId;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    // TEST-ONLY routes. No production endpoint exists solely to demonstrate the middleware.
    Route::middleware(['stateful', 'auth:web', 'can:'.Capability::ConsoleAccess->value])
        ->get('/api/v1/zz/console', fn () => response()->json(['ok' => true]));
    Route::middleware(['stateful', 'auth:web', 'can:'.Capability::AssignRoles->value])
        ->get('/api/v1/zz/assign', fn () => response()->json(['ok' => true]));
    Route::middleware(['stateful', 'auth:web'])
        ->get('/api/v1/zz/action', function (Request $request, AuthorizeAction $authorize) {
            $user = $request->user();
            assert($user instanceof Authenticatable && is_string($user->getAuthIdentifier()));
            $account = app(AccountRepository::class)->find(AccountId::fromString($user->getAuthIdentifier()));
            assert($account !== null);
            $authorize(Access::actorFor($account), Capability::AssignRoles);

            return response()->json(['ok' => true]);
        });
});

/**
 * @return array{Console, Account}
 */
function signedInAs(?Role $role = null): array
{
    $account = Identity::savedActiveAccount();
    if ($role !== null) {
        Access::grant($account, $role);
    }
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    return [$console, $account];
}

it('answers 401 to an anonymous request for a protected route', function () {
    (new Console)->get('/api/v1/zz/console')->assertUnauthorized();
});

it('answers 403 to a signed-in person who lacks the capability', function () {
    [$console] = signedInAs();

    $console->get('/api/v1/zz/console')->assertForbidden();
    $console->get('/api/v1/zz/assign')->assertForbidden();
});

it('lets a guardian through the Console gate but not the role-assignment gate', function () {
    [$console] = signedInAs(Role::Guardian);

    $console->get('/api/v1/zz/console')->assertOk();
    $console->get('/api/v1/zz/assign')->assertForbidden();
});

it('lets the platform administrator through every gate', function () {
    [$console] = signedInAs(Role::PlatformAdministrator);

    $console->get('/api/v1/zz/console')->assertOk();
    $console->get('/api/v1/zz/assign')->assertOk();
});

it('takes a revoked role away on the next request, without signing in again', function () {
    [$console, $account] = signedInAs(Role::PlatformAdministrator);
    $console->get('/api/v1/zz/assign')->assertOk();

    Access::revoke($account, Role::PlatformAdministrator);

    $console->get('/api/v1/zz/assign')->assertForbidden();
    $console->get('/api/v1/zz/console')->assertForbidden();
    $console->me()->assertOk(); // still signed in: authentication and authorization are separate
});

it('gives a newly granted role on the next request, without signing in again', function () {
    [$console, $account] = signedInAs();
    $console->get('/api/v1/zz/console')->assertForbidden();

    Access::grant($account, Role::Guardian);

    $console->get('/api/v1/zz/console')->assertOk();
});

it('gives an account disabled mid-session no authority through its still-live session', function () {
    [$console, $account] = signedInAs(Role::PlatformAdministrator);
    $console->get('/api/v1/zz/assign')->assertOk();

    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    $console->get('/api/v1/zz/assign')->assertUnauthorized();
    $console->get('/api/v1/zz/console')->assertUnauthorized();
});

it('never trusts capabilities, roles or permissions supplied by the client', function () {
    [$console] = signedInAs(); // holds nothing
    $claims = [
        'X-Capabilities' => 'console.access,access.roles.assign',
        'X-Roles' => 'platform_administrator',
        'X-Permissions' => 'access.roles.assign',
        'X-Role' => 'guardian',
        'X-Is-Admin' => '1',
    ];

    $console->get('/api/v1/zz/assign?capabilities[]=access.roles.assign&roles[]=platform_administrator&is_admin=1&permissions=access.roles.assign', $claims)->assertForbidden();
    $console->get('/api/v1/zz/console', $claims)->assertForbidden();
});

it('never reads capabilities from the session, even if something put them there', function () {
    [$console] = signedInAs();
    $console->tamperSession(function (Store $session): void {
        $session->put('capabilities', ['console.access', 'access.roles.assign']);
        $session->put('roles', ['platform_administrator']);
        $session->put('is_admin', true);
    });

    $console->get('/api/v1/zz/assign')->assertForbidden();
    $console->get('/api/v1/zz/console')->assertForbidden();
});

it('keeps no capability or role data in the stored session', function () {
    [$console] = signedInAs(Role::PlatformAdministrator);
    $console->get('/api/v1/zz/assign')->assertOk();
    $console->me()->assertOk();

    $stored = base64_decode(Identity::scalar('sessions', 'payload'));

    foreach (['console.access', 'access.roles.assign', 'platform_administrator', 'guardian', 'capabilit', 'role'] as $needle) {
        expect($stored)->not->toContain($needle);
    }
});

it('turns AccessDenied from business code into a 403', function () {
    [$console] = signedInAs(Role::Guardian);

    $console->get('/api/v1/zz/action')->assertForbidden()->assertExactJson(['message' => 'This action is unauthorized.']);
});

// --- The Gate is an edge, not a second catalog ---------------------------------------------------------------

it('defines exactly one Gate ability per capability, and none for anything else', function () {
    $abilities = array_keys(Gate::abilities());
    $capabilities = array_map(fn (Capability $c): string => $c->value, Capability::cases());
    // Test-only routes use `can:` with these very names, so nothing else may have been defined.
    sort($abilities);
    sort($capabilities);

    expect($abilities)->toBe($capabilities);
});

it('has no Gate ability named after a role', function () {
    foreach (Role::cases() as $role) {
        expect(Gate::has($role->value))->toBeFalse($role->value);
    }
});

it('denies a Gate check for an ability that is not a capability, whoever asks', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    $user = DB::table('accounts')->first();
    assert($user !== null);
    $record = AccountRecord::query()->findOrFail($account->id->value);

    expect(Gate::forUser($record)->allows('platform_administrator'))->toBeFalse()
        ->and(Gate::forUser($record)->allows('anything.else'))->toBeFalse()
        ->and(Gate::forUser($record)->allows(Capability::AssignRoles->value))->toBeTrue();
});

it('delegates every decision to the Authorizer: the Gate and the Authorizer never disagree', function () {
    foreach (['none' => null, 'guardian' => Role::Guardian, 'admin' => Role::PlatformAdministrator] as $label => $role) {
        $account = Identity::savedActiveAccount("{$label}@example.org", name: $label);
        if ($role !== null) {
            Access::grant($account, $role);
        }
        $record = AccountRecord::query()->findOrFail($account->id->value);

        foreach (Capability::cases() as $capability) {
            expect(Gate::forUser($record)->allows($capability->value))
                ->toBe(app(Authorizer::class)->allows(Access::actorFor($account), $capability), "{$label} / {$capability->value}");
        }
    }
});

it('denies the Gate for a guest', function () {
    foreach (Capability::cases() as $capability) {
        expect(Gate::forUser(null)->allows($capability->value))->toBeFalse();
    }
});

it('gives no authority through the Gate to an account that can no longer authenticate', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    $record = AccountRecord::query()->findOrFail($account->id->value);
    expect(Gate::forUser($record)->allows(Capability::AssignRoles->value))->toBeTrue();

    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    expect(Gate::forUser($record)->allows(Capability::AssignRoles->value))->toBeFalse();
});

it('exposes AccessDenied only for callers that ask for a capability they lack', function () {
    expect(fn () => app(AuthorizeAction::class)(Access::actorFor(Identity::savedActiveAccount()), Capability::ConsoleAccess))
        ->toThrow(AccessDenied::class);
});
