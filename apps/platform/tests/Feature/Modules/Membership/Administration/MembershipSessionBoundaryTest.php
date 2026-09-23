<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * Membership inherits the Console's existing request-forgery protection; it adds none of its own and bypasses none
 * (Work Package 7). The rest of the session matrix — no session (401), no capability (403 before any proof is asked
 * for), a stale proof (403 with verification_required), re-proving, the exact 15-minute edge and a session holding no
 * proof — is pinned per route in MembershipAccessControlTest; this file covers what that one does not.
 *
 * Precisely: the forgery check (Laravel's PreventRequestForgery, in the `stateful` group) applies to state-changing
 * methods. It does NOT apply to GET, by design and in every browser's model of it, so a read is protected by the
 * session and the capability alone. That is asserted here as what it is, not claimed as CSRF protection.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/**
 * The three Membership mutations, each sent with the given XSRF header (or none).
 *
 * @param  array<string, string>  $headers
 * @return array<string, TestResponse<Response>>
 */
function membershipMutations(Console $console, string $person, string $grant, array $headers, bool $withXsrfHeader): array
{
    $term = ['starts_at' => '2026-09-23T12:00:00Z', 'ends_at' => null, 'source' => 'operator'];

    return [
        'register' => $console->post('/api/v1/admin/members', ['display_name' => 'Forged Member', ...$term], $headers, $withXsrfHeader),
        'grant' => $console->post("/api/v1/admin/members/{$person}/grants", $term, $headers, $withXsrfHeader),
        'revoke' => $console->post("/api/v1/admin/membership-grants/{$grant}/revoke", [], $headers, $withXsrfHeader),
    ];
}

it('refuses every Membership mutation with no forgery token, from a fully authorized, freshly verified operator', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Subject');
    $grant = Membership::savedGrant($person->id);

    foreach (membershipMutations($console, $person->id->value, $grant->id->value, [], withXsrfHeader: false) as $name => $response) {
        expect($response->status())->toBe(419, $name);
    }
    expect(DB::table('membership_grants')->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('people')->where('display_name', 'Forged Member')->count())->toBe(0);
});

it('refuses every Membership mutation carrying a token that is not this session\'s', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Subject');
    $grant = Membership::savedGrant($person->id);
    $forged = ['X-XSRF-TOKEN' => 'eyJpdiI6ImZvcmdlZCJ9', 'X-CSRF-TOKEN' => 'not-this-sessions-token'];

    foreach (membershipMutations($console, $person->id->value, $grant->id->value, $forged, withXsrfHeader: false) as $name => $response) {
        expect($response->status())->toBe(419, $name);
    }
    expect(DB::table('membership_grants')->count())->toBe(1)
        ->and(DB::table('membership_grants')->whereNotNull('revoked_at')->count())->toBe(0);
});

it('lets the same mutations through with the session\'s own token (control: the refusals above were the forgery check)', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Subject');
    $grant = Membership::savedGrant($person->id);

    $responses = membershipMutations($console, $person->id->value, $grant->id->value, [], withXsrfHeader: true);

    $responses['register']->assertCreated();
    $responses['grant']->assertCreated();
    $responses['revoke']->assertNoContent();
});

it('does not apply the forgery check to reads: a read needs the session and the capability, and no token', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Subject');
    Membership::savedGrant($person->id);

    // A token that is NOT this session's (it would earn a 419 on any mutation, above): a read is answered anyway, because
    // the check is not applied to GET. What protects a read is the session and the capability.
    $garbled = ['X-XSRF-TOKEN' => 'eyJpdiI6ImZvcmdlZCJ9'];
    $console->get('/api/v1/admin/members', $garbled)->assertOk();
    $console->get('/api/v1/admin/members/'.$person->id->value, $garbled)->assertOk();

    // ...and without a session, the same read is refused by authentication (401), not by the forgery check (419).
    $stranger = new Console;
    $stranger->get('/api/v1/admin/members', $garbled)->assertUnauthorized();
});
