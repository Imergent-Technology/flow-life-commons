<?php

declare(strict_types=1);

use App\Modules\Access\Application\AuthorizerEffectiveCapabilities;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\EffectiveCapabilities;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Infrastructure\NoEffectiveCapabilities;
use App\Shared\Domain\Actor;
use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
});

/** @return list<string> */
function capabilitiesOfMe(Console $console): array
{
    $capabilities = $console->me()->assertOk()->json('capabilities');
    assert(is_array($capabilities));

    $ids = [];
    foreach ($capabilities as $capability) {
        assert(is_string($capability));
        $ids[] = $capability;
    }

    return $ids;
}

it('reports no capabilities to an account that holds none', function () {
    Identity::savedActiveAccount();
    $console = new Console;

    $console->login('ada@example.org', Identity::PASSWORD)->assertOk()->assertJsonPath('capabilities', []);
    $console->me()->assertOk()->assertJsonPath('capabilities', []);
});

it('reports capability identifiers, never role names, in a stable order', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);
    Access::grant($account, Role::PlatformAdministrator);
    Mfa::enroll($account);
    $console = new Console;

    $login = $console->loginWithMfa('ada@example.org', Identity::PASSWORD)->assertOk();
    $me = $console->me()->assertOk();

    foreach ([$login, $me] as $response) {
        expect($response->json('capabilities'))->toBe(Access::everyCapabilityId());
        // The client learns what it may do, not which label produced it.
        foreach (['platform_administrator', 'guardian'] as $roleName) {
            expect((string) $response->getContent())->not->toContain($roleName);
        }
        expect($response->json())->not->toHaveKeys(['roles', 'permissions', 'role']);
    }
});

it('reports a guardian\'s Console access and nothing else', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);
    Mfa::enroll($account);
    $console = new Console;
    $console->loginWithMfa('ada@example.org', Identity::PASSWORD)->assertOk();

    expect(capabilitiesOfMe($console))->toBe(['console.access']);
});

it('derives capabilities fresh on every request, so a revoked role disappears without signing in again', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    Mfa::enroll($account);
    $console = new Console;
    $console->loginWithMfa('ada@example.org', Identity::PASSWORD)->assertOk();
    expect(capabilitiesOfMe($console))->toBe(Access::everyCapabilityId());

    Access::revoke($account, Role::PlatformAdministrator);
    expect(capabilitiesOfMe($console))->toBe([]);

    Access::grant($account, Role::Guardian);
    expect(capabilitiesOfMe($console))->toBe(['console.access']);
});

it('reports the same list whatever order the roles were assigned in', function () {
    $first = Identity::savedActiveAccount('first@example.org');
    Access::grant($first, Role::Guardian);
    Access::grant($first, Role::PlatformAdministrator);
    $second = Identity::savedActiveAccount('second@example.org', name: 'Second');
    Access::grant($second, Role::PlatformAdministrator);
    Access::grant($second, Role::Guardian);

    Mfa::enroll($first);
    Mfa::enroll($second);
    $a = new Console;
    $a->loginWithMfa('first@example.org', Identity::PASSWORD)->assertOk();
    $b = new Console;
    $b->loginWithMfa('second@example.org', Identity::PASSWORD)->assertOk();

    expect(capabilitiesOfMe($a))->toBe(capabilitiesOfMe($b));
});

it('stops reporting anything once the account can no longer authenticate', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    Mfa::enroll($account);
    $console = new Console;
    $console->loginWithMfa('ada@example.org', Identity::PASSWORD)->assertOk();

    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    $console->me()->assertUnauthorized();
});

it('does not let the client influence what is reported', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $response = $console->get('/api/v1/me?capabilities[]=access.roles.assign', ['X-Capabilities' => 'access.roles.assign', 'X-Roles' => 'platform_administrator']);

    expect($response->json('capabilities'))->toBe([]);
});

it('is served by Access when the platform runs, and by nothing when Access is absent', function () {
    expect(app(EffectiveCapabilities::class))->toBeInstanceOf(AuthorizerEffectiveCapabilities::class);

    // Identity's own default grants nothing, so Identity works without Access.
    $account = Identity::savedActiveAccount();
    expect((new NoEffectiveCapabilities)->for(Actor::user($account->id, $account->personId)))->toBe([]);
});
