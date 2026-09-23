<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Membership\Application\GetMembershipRecord;
use App\Modules\Membership\Application\GrantMembershipAccess;
use App\Modules\Membership\Application\PageMembershipRecords;
use App\Modules\Membership\Application\RegisterPersonWithMembershipAccess;
use App\Modules\Membership\Application\RevokeMembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Who may administer membership records, and how strong their proof must be (ADR 0028, Work
 * Package 5), following exactly the rule AccessControlTest already pins for Access's own
 * administration surface: every mutation needs a capability AND a recent password-and-second-
 * factor proof; a guardian, who may use the Console, may not administer it.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
});

/**
 * Every Membership administration operation, as [method, path, body]. `{p}` is a target Person
 * id, `{g}` a target membership grant id.
 *
 * @return list<array{string, string, array<string, mixed>}>
 */
function membershipOperations(): array
{
    return [
        ['GET', '/api/v1/admin/members', []],
        ['GET', '/api/v1/admin/members/{p}', []],
        ['POST', '/api/v1/admin/members', ['display_name' => 'New Member', 'starts_at' => '2026-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator']],
        ['POST', '/api/v1/admin/members/{p}/grants', ['starts_at' => '2026-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator']],
        ['POST', '/api/v1/admin/membership-grants/{g}/revoke', []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function callMembership(Console $console, string $method, string $path, array $body, string $person, string $grant): TestResponse
{
    $path = str_replace(['{p}', '{g}'], [$person, $grant], $path);

    return match ($method) {
        'GET' => $console->get($path),
        default => $console->post($path, $body),
    };
}

it('answers 401 to every membership administration route for someone who is not signed in', function () {
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);
    $console = new Console;
    $console->bootstrap();

    foreach (membershipOperations() as [$method, $path, $body]) {
        callMembership($console, $method, $path, $body, $target->id->value, $grant->id->value)->assertUnauthorized();
    }
});

it('refuses a GUARDIAN every membership administration route, reads and mutations alike: it may enter the Console and nothing more', function () {
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);
    [$console] = Mfa::signedIn('guardian@example.org', Totp::RFC_SECRET);

    foreach (membershipOperations() as [$method, $path, $body]) {
        $response = callMembership($console, $method, $path, $body, $target->id->value, $grant->id->value);
        expect($response->status())->toBe(403, "{$method} {$path}")
            ->and($response->json('verification_required'))->toBeNull("{$method} {$path}");
    }

    expect(DB::table('membership_grants')->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNull('revoked_at')->count())->toBe(1);
});

it('lets an ADMINISTRATOR read, and mutate while freshly verified', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);

    $console->get('/api/v1/admin/members')->assertOk();
    $console->get('/api/v1/admin/members/'.$target->id->value)->assertOk();
    $console->post('/api/v1/admin/members', [
        'display_name' => 'New Member', 'starts_at' => '2026-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();
    $console->post('/api/v1/admin/members/'.$target->id->value.'/grants', [
        'starts_at' => '2027-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();
    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();
});

it('demands RECENT VERIFICATION for every mutation, independently of the capability: a stale proof is refused and nothing changes', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);
    Console::advance(16 * 60); // past the 15 minutes, well inside the 30-minute inactivity window
    $console->me()->assertOk();

    foreach (membershipOperations() as [$method, $path, $body]) {
        if ($method === 'GET') {
            callMembership($console, $method, $path, $body, $target->id->value, $grant->id->value)->assertOk();
            $console->me()->assertOk();

            continue;
        }
        $response = callMembership($console, $method, $path, $body, $target->id->value, $grant->id->value);
        expect($response->status())->toBe(403, "{$method} {$path}")
            ->and($response->json('verification_required'))->toBeTrue("{$method} {$path}");
    }

    expect(DB::table('membership_grants')->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('people')->where('display_name', 'New Member')->count())->toBe(0);
});

it('lets the mutation succeed once the person re-proves, and never before', function () {
    [$console, , $factor] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);
    Console::advance(16 * 60);

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')
        ->assertForbidden()->assertJson(['verification_required' => true]);
    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret'])])->assertNoContent();
    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')->assertNoContent();
});

it('respects the 15-minute boundary exactly', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');
    Membership::savedGrant($target->id);

    Console::advance(15 * 60 - 1);
    // Verified one second before the edge: reaches the use case (a duplicate grant is allowed, not refused).
    $console->post('/api/v1/admin/members/'.$target->id->value.'/grants', [
        'starts_at' => '2027-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();
    Console::advance(1);
    $console->post('/api/v1/admin/members/'.$target->id->value.'/grants', [
        'starts_at' => '2028-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertForbidden()->assertJson(['verification_required' => true]);
});

it('does not treat a session that is merely authenticated as verified', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);
    $console->tamperSession(fn ($session) => $session->forget('security_verified_at'));

    $console->me()->assertOk();
    $console->get('/api/v1/admin/members')->assertOk();
    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke')
        ->assertForbidden()->assertJson(['verification_required' => true]);
});

it('checks the capability again inside every use case, so no other caller can skip it', function () {
    $guardian = Mfa::guardian('guardian@example.org');
    $actor = Access::actorFor($guardian);
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);

    foreach ([
        fn () => app(PageMembershipRecords::class)($actor, 1, 25),
        fn () => app(GetMembershipRecord::class)($actor, $target->id),
        fn () => app(RegisterPersonWithMembershipAccess::class)($actor, 'New Member', Identity::now(), null, MembershipGrantSource::Operator),
        fn () => app(GrantMembershipAccess::class)($actor, $target->id, Identity::now(), null, MembershipGrantSource::Operator),
        fn () => app(RevokeMembershipGrant::class)($actor, $grant->id),
    ] as $call) {
        expect($call)->toThrow(AccessDenied::class);
    }
});

it('binds each operation to ITS capability, in the route and in the use case', function () {
    $expected = [
        'api.v1.admin.members.index' => ['membership.records.view', PageMembershipRecords::class, 'ViewMembershipRecords'],
        'api.v1.admin.members.show' => ['membership.records.view', GetMembershipRecord::class, 'ViewMembershipRecords'],
        'api.v1.admin.members.store' => ['membership.records.manage', RegisterPersonWithMembershipAccess::class, 'ManageMembershipRecords'],
        'api.v1.admin.members.grants.store' => ['membership.records.manage', GrantMembershipAccess::class, 'ManageMembershipRecords'],
        'api.v1.admin.membership-grants.revoke' => ['membership.records.manage', RevokeMembershipGrant::class, 'ManageMembershipRecords'],
    ];

    foreach ($expected as $name => [$capability, $useCase, $case]) {
        $route = app('router')->getRoutes()->getByName($name);
        $file = (new ReflectionClass($useCase))->getFileName();
        assert(is_string($file));
        $source = (string) file_get_contents($file);
        preg_match_all('/\(\$this->authorize\)\(\$actor, Capability::(\w+)\)/', $source, $matches);

        expect($route?->gatherMiddleware())->toContain("can:{$capability}")
            ->and(array_values(array_unique($matches[1])))->toBe([$case]);
    }
});

it('is CSRF-protected like every other stateful mutation', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');
    $grant = Membership::savedGrant($target->id);

    $console->post('/api/v1/admin/membership-grants/'.$grant->id->value.'/revoke', [], [], withXsrfHeader: false)
        ->assertStatus(419);

    expect(DB::table('membership_grants')->whereNull('revoked_at')->count())->toBe(1);
});

it('treats a route PersonId and GrantId as subjects only: they never become the caller', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Identity::savedPerson('Target Member');

    // The route parameter names the SUBJECT of the grant, never the caller: provenance is still the
    // signed-in admin's own account, not the person named in the URL.
    $response = $console->post('/api/v1/admin/members/'.$target->id->value.'/grants', [
        'starts_at' => '2026-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();

    $grantId = $response->json('id');
    expect(DB::table('membership_grants')->where('id', $grantId)->value('person_id'))->toBe($target->id->value)
        ->and(DB::table('membership_grants')->where('id', $grantId)->value('granted_by_account_id'))->toBe($admin->id->value)
        ->and($target->id->value)->not->toBe($admin->id->value);
});
