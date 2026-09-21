<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\DisableManagedAccount;
use App\Modules\Access\Application\EnableManagedAccount;
use App\Modules\Access\Application\GrantRoleToAccount;
use App\Modules\Access\Application\InviteOperator;
use App\Modules\Access\Application\ListManagedAccounts;
use App\Modules\Access\Application\ListRoleCatalog;
use App\Modules\Access\Application\ReissueOperatorInvitation;
use App\Modules\Access\Application\ResetManagedMfa;
use App\Modules\Access\Application\RevokeRoleFromAccount;
use App\Modules\Access\Application\ShowManagedAccount;
use App\Modules\Identity\Application\AccountSearch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Who may administer, and how strong their proof must be (ADR 0024). Every mutation needs a capability from Access
 * AND a recent password-and-second-factor proof; a guardian, who may use the Console, may not administer it.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
    Mail::fake();
});

/**
 * Every administration operation, as [method, path, body]. `{a}` is a target account id.
 *
 * @return list<array{string, string, array<string, mixed>}>
 */
function adminOperations(): array
{
    return [
        ['GET', '/api/v1/admin/accounts', []],
        ['GET', '/api/v1/admin/accounts/{a}', []],
        ['GET', '/api/v1/admin/roles', []],
        ['POST', '/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New']],
        ['POST', '/api/v1/admin/accounts/{a}/invitation', []],
        ['POST', '/api/v1/admin/accounts/{a}/disable', []],
        ['POST', '/api/v1/admin/accounts/{a}/enable', []],
        ['POST', '/api/v1/admin/accounts/{a}/mfa/reset', []],
        ['POST', '/api/v1/admin/accounts/{a}/assignments', ['key' => 'guardian']],
        ['DELETE', '/api/v1/admin/accounts/{a}/assignments/guardian', []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function callAdmin(Console $console, string $method, string $path, array $body, string $target): TestResponse
{
    $path = str_replace('{a}', $target, $path);

    return match ($method) {
        'GET' => $console->get($path),
        'DELETE' => $console->delete($path, $body),
        default => $console->post($path, $body),
    };
}

it('answers 401 to every administration route for someone who is not signed in', function () {
    $target = Identity::savedActiveAccount('target@example.org');
    $console = new Console;
    $console->bootstrap();

    foreach (adminOperations() as [$method, $path, $body]) {
        callAdmin($console, $method, $path, $body, $target->id->value)->assertUnauthorized();
    }
});

it('refuses a GUARDIAN every administration route, reads and mutations alike: it may enter the Console and nothing more', function () {
    $target = Identity::savedActiveAccount('target@example.org');
    [$console] = Mfa::signedIn('guardian@example.org', Totp::RFC_SECRET);

    foreach (adminOperations() as [$method, $path, $body]) {
        $response = callAdmin($console, $method, $path, $body, $target->id->value);
        // 403 and NOT verification_required: a guardian is refused before being asked to prove anything, because the
        // capability is checked ahead of the proof.
        expect($response->status())->toBe(403, "{$method} {$path}")
            ->and($response->json('verification_required'))->toBeNull("{$method} {$path}");
    }

    // ...and it changed nothing.
    expect(DB::table('accounts')->count())->toBe(2)
        ->and(Identity::events('account.disabled'))->toBe([])
        ->and(DB::table('role_assignments')->count())->toBe(1);
});

it('lets an ADMINISTRATOR read, and mutate while freshly verified', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');

    $console->get('/api/v1/admin/accounts')->assertOk();
    $console->get('/api/v1/admin/accounts/'.$target->id->value)->assertOk();
    $console->get('/api/v1/admin/roles')->assertOk();
    $console->post('/api/v1/admin/accounts/'.$target->id->value.'/disable')->assertOk();
});

it('demands RECENT VERIFICATION for every mutation, independently of the capability: a stale proof is refused and nothing changes', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');
    Console::advance(16 * 60); // past the 15 minutes, well inside the 30-minute inactivity window
    $console->me()->assertOk(); // still signed in: it is the PROOF that is stale, not the session

    foreach (adminOperations() as [$method, $path, $body]) {
        if ($method === 'GET') {
            callAdmin($console, $method, $path, $body, $target->id->value)->assertOk(); // reads need the capability only
            $console->me()->assertOk();

            continue;
        }
        $response = callAdmin($console, $method, $path, $body, $target->id->value);
        expect($response->status())->toBe(403, "{$method} {$path}")
            ->and($response->json('verification_required'))->toBeTrue("{$method} {$path}");
    }

    expect(DB::table('accounts')->where('id', $target->id->value)->value('status'))->toBe('active')
        ->and(DB::table('accounts')->count())->toBe(2)
        ->and(Identity::events('account.disabled'))->toBe([])
        ->and(DB::table('role_assignments')->count())->toBe(1);
});

it('lets the mutation succeed once the person re-proves, and never before', function () {
    [$console, $admin, $factor] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');
    Console::advance(16 * 60);

    $console->post('/api/v1/admin/accounts/'.$target->id->value.'/disable')->assertForbidden()->assertJson(['verification_required' => true]);
    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret'])])->assertNoContent();
    $console->post('/api/v1/admin/accounts/'.$target->id->value.'/disable')->assertOk()->assertJson(['status' => 'disabled']);
});

it('respects the 15-minute boundary exactly', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');
    $path = '/api/v1/admin/accounts/'.$target->id->value.'/disable';

    Console::advance(15 * 60 - 1);
    $console->post('/api/v1/admin/accounts/'.$target->id->value.'/enable')->assertStatus(409); // verified one second before the edge: reaches the use case
    Console::advance(1);
    $console->post($path)->assertForbidden()->assertJson(['verification_required' => true]);
});

it('does not treat a session that is merely authenticated as verified', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');
    // A signed-in session that has no proof recorded at all (as one that lost it would be): still authenticated, and
    // it can read, but every mutation is refused.
    $console->tamperSession(fn ($session) => $session->forget('security_verified_at'));

    $console->me()->assertOk();
    $console->get('/api/v1/admin/accounts')->assertOk();
    $console->post('/api/v1/admin/accounts/'.$target->id->value.'/disable')->assertForbidden()->assertJson(['verification_required' => true]);
});

it('checks the capability again inside every use case, so no other caller can skip it', function () {
    $guardian = Mfa::guardian('guardian@example.org');
    $actor = Access::actorFor($guardian);
    $target = Identity::savedActiveAccount('target@example.org');
    $id = $target->id;

    foreach ([
        fn () => app(ListManagedAccounts::class)($actor, new AccountSearch),
        fn () => app(ShowManagedAccount::class)($actor, $id),
        fn () => app(ListRoleCatalog::class)($actor),
        fn () => app(InviteOperator::class)($actor, 'new@example.org', 'New'),
        fn () => app(ReissueOperatorInvitation::class)($actor, $id),
        fn () => app(DisableManagedAccount::class)($actor, $id),
        fn () => app(EnableManagedAccount::class)($actor, $id),
        fn () => app(ResetManagedMfa::class)($actor, $id),
        fn () => app(GrantRoleToAccount::class)($actor, $id, 'guardian'),
        fn () => app(RevokeRoleFromAccount::class)($actor, $id, 'guardian'),
    ] as $call) {
        expect($call)->toThrow(AccessDenied::class);
    }
});

it('binds each operation to ITS capability, in the route and in the use case', function () {
    // Bundles are code-owned, so no real actor holds "some but not all" administrative capabilities; that leaves a
    // wrong capability (say `manage` where `view` was meant) invisible to a behavioural test. So the mapping is
    // pinned directly: the route's `can:` and the use case's authorize call must agree with this table.
    $expected = [
        'api.v1.admin.accounts.index' => ['identity.accounts.view', ListManagedAccounts::class, 'ViewAccounts'],
        'api.v1.admin.accounts.show' => ['identity.accounts.view', ShowManagedAccount::class, 'ViewAccounts'],
        'api.v1.admin.roles.index' => ['identity.accounts.view', ListRoleCatalog::class, 'ViewAccounts'],
        'api.v1.admin.invitations.store' => ['identity.invitations.issue', InviteOperator::class, 'IssueInvitations'],
        'api.v1.admin.invitations.reissue' => ['identity.invitations.issue', ReissueOperatorInvitation::class, 'IssueInvitations'],
        'api.v1.admin.accounts.disable' => ['identity.accounts.manage', DisableManagedAccount::class, 'ManageAccounts'],
        'api.v1.admin.accounts.enable' => ['identity.accounts.manage', EnableManagedAccount::class, 'ManageAccounts'],
        'api.v1.admin.mfa.reset' => ['identity.mfa.recover', ResetManagedMfa::class, 'RecoverMfa'],
        'api.v1.admin.assignments.store' => ['access.roles.assign', GrantRoleToAccount::class, 'AssignRoles'],
        'api.v1.admin.assignments.destroy' => ['access.roles.assign', RevokeRoleFromAccount::class, 'AssignRoles'],
    ];

    foreach ($expected as $name => [$capability, $useCase, $case]) {
        $route = app('router')->getRoutes()->getByName($name);
        $file = (new ReflectionClass($useCase))->getFileName();
        assert(is_string($file));
        $source = (string) file_get_contents($file);
        preg_match_all('/\(\$this->authorize\)\(\$actor, Capability::(\w+)\)/', $source, $matches);

        expect($route?->gatherMiddleware())->toContain("can:{$capability}")
            ->and(array_values(array_unique($matches[1])))->toContain($case);
        // Exactly the ones it needs (InviteOperator additionally needs role assignment, when roles are chosen).
        expect(array_values(array_diff(array_unique($matches[1]), $useCase === InviteOperator::class ? ['AssignRoles'] : [])))->toBe([$case]);
    }
});
