<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * What Membership discloses and what it leaves behind (Work Package 7), across a whole operator journey rather than one
 * response at a time: every Membership response the Console can receive, and every table it could have touched.
 *
 * The per-endpoint exact key sets live beside each endpoint (RegisterMemberTest, GrantMembershipTest, MemberDetailTest,
 * MemberListTest). This adds what those cannot see: the union over the journey, VALUES that must never appear whatever key
 * they hide under, and the side effects that must not happen.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/**
 * Registers a member, adds a grant, revokes the first one, then lists and inspects them: every Membership response shape.
 *
 * @return array{console: Console, responses: array<string, TestResponse<Response>>, person: string}
 */
function membershipJourney(): array
{
    [$console] = Mfa::signedInAdmin();
    $responses = [];

    $responses['register'] = $console->post('/api/v1/admin/members', [
        'display_name' => 'Journey Member', 'starts_at' => '2026-01-01T00:00:00Z', 'open_ended' => false, 'ends_at' => '2026-12-31T00:00:00Z',
        'source' => 'luma_legacy', 'source_reference' => 'luma-4821',
    ])->assertCreated();
    $person = Api::string($responses['register']->json('person.id'));
    $first = Api::string($responses['register']->json('grants.0.id'));

    $responses['grant'] = $console->post("/api/v1/admin/members/{$person}/grants", [
        'starts_at' => '2026-06-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated();
    $responses['revoke'] = $console->post("/api/v1/admin/membership-grants/{$first}/revoke")->assertNoContent();
    $responses['list'] = $console->get('/api/v1/admin/members')->assertOk();
    $responses['detail'] = $console->get("/api/v1/admin/members/{$person}")->assertOk();

    return ['console' => $console, 'responses' => $responses, 'person' => $person];
}

/**
 * Every key at every depth of a decoded JSON document.
 *
 * @return list<string>
 */
function allKeys(mixed $json): array
{
    if (! is_array($json)) {
        return [];
    }
    $keys = [];
    foreach ($json as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...allKeys($value)];
    }

    return $keys;
}

it('discloses, across every Membership response, exactly the approved concepts and no others', function () {
    ['responses' => $responses] = membershipJourney();
    $keys = [];
    foreach ($responses as $response) {
        if ($response->getContent() !== '') { // the revoke's 204 has no body at all
            $keys = [...$keys, ...allKeys($response->json())];
        }
    }
    $keys = array_values(array_unique($keys));

    // Person (id, display name), derived state (active, access-through, open-ended), grant history (id, term, provenance,
    // revocation), the page envelope, and `person_id` on the standalone created grant. Nothing about accounts, provenance
    // actors, roles, sessions, credentials or payment.
    expect($keys)->toEqualCanonicalizing([
        'person', 'id', 'display_name', 'active', 'current_access_ends_at', 'open_ended',
        'grants', 'starts_at', 'ends_at', 'source', 'source_reference', 'revoked_at', 'person_id',
        'data', 'meta', 'page', 'per_page', 'total', 'last_page',
    ]);
});

it('never discloses an Account id, an email, a role, a session, a credential or an audit detail, under any key', function () {
    ['responses' => $responses] = membershipJourney();
    $bodies = implode("\n", array_map(fn (TestResponse $r): string => (string) $r->getContent(), $responses));

    $secrets = [
        ...Api::strings(DB::table('accounts')->pluck('id')->all()),              // including the operator's: provenance is not shown
        ...Api::strings(DB::table('accounts')->pluck('email')->all()),
        ...Api::strings(DB::table('accounts')->pluck('password_hash')->all()),
        ...Api::strings(DB::table('sessions')->pluck('id')->all()),
        ...Api::strings(DB::table('role_assignments')->pluck('role_key')->all()),
        ...Api::strings(DB::table('membership_grants')->whereNotNull('granted_by_account_id')->pluck('granted_by_account_id')->all()),
        ...Api::strings(DB::table('membership_grants')->whereNotNull('revoked_by_account_id')->pluck('revoked_by_account_id')->all()),
    ];

    expect($secrets)->not->toBeEmpty(); // positive control: there is something to find
    foreach ($secrets as $secret) {
        expect($bodies)->not->toContain($secret);
    }
    foreach (['password', 'totp', 'recovery', 'security_generation', 'session', 'role', 'capabilit', 'email', 'account'] as $word) {
        expect(strtolower($bodies))->not->toContain($word);
    }
});

it('changes no role assignment and records no security event, anywhere in the journey', function () {
    // Baseline after the operator's own sign-in, which IS a security event.
    [$console] = Mfa::signedInAdmin();
    $roles = DB::table('role_assignments')->orderBy('id')->get()->toArray();
    $events = DB::table('security_events')->count();

    $person = Api::string($console->post('/api/v1/admin/members', [
        'display_name' => 'Journey Member', 'starts_at' => '2026-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated()->json('person.id'));
    $grant = Api::string($console->post("/api/v1/admin/members/{$person}/grants", [
        'starts_at' => '2027-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator',
    ])->assertCreated()->json('id'));
    $console->post("/api/v1/admin/membership-grants/{$grant}/revoke")->assertNoContent();

    // ADR 0028: membership is never mirrored as a (non-expiring) role, and in Phase 1 there is no membership role at all.
    expect(DB::table('role_assignments')->orderBy('id')->get()->toArray())->toEqual($roles)
        ->and(DB::table('role_assignments')->where('person_id', $person)->count())->toBe(0)
        // ADR 0019's seam is for identity and access; Membership actions are not written there in Phase 1.
        ->and(DB::table('security_events')->count())->toBe($events)
        ->and(DB::table('security_events')->where('type', 'like', '%member%')->count())->toBe(0);
});

it('stores Membership in one table: no history table, no client or token table, nothing for a delegated person', function () {
    // This connection's own schema only: on MariaDB the server also lists the development database beside the test one.
    $tables = Api::strings(Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false));

    // Positive control: this is the real, migrated schema.
    expect($tables)->toContain('accounts')->toContain('sessions')->toContain('membership_grants');

    expect(array_values(array_filter($tables, fn (string $t): bool => preg_match('/member/i', $t) === 1)))->toBe(['membership_grants']);
    // Phase-1 fact (ADR 0018 accepted, not built): no service-client, API-token or delegated-person store exists yet. When one
    // is built this is expected to change deliberately. `password_reset_tokens` is Identity's reset store, not a credential
    // for calling the API, so the pattern does not name tokens in general.
    expect(array_values(array_filter($tables, fn (string $t): bool => preg_match('/client|oauth|personal_access|api_token|delegat|jwt/i', $t) === 1)))
        ->toBe([]);
});

it('carries a source reference opaquely and calls no provider while doing so (ADR 0029)', function () {
    Http::preventStrayRequests(); // any outbound HTTP from the platform during the journey throws
    [$console] = Mfa::signedInAdmin();
    $reference = 'https://lu.ma/event/evt-XYZ?member=4821&token=not-a-token';

    $response = $console->post('/api/v1/admin/members', [
        'display_name' => 'Legacy Member', 'starts_at' => '2025-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null,
        'source' => 'luma_legacy', 'source_reference' => $reference,
    ])->assertCreated();

    expect($response->json('grants.0.source_reference'))->toBe($reference)
        ->and(DB::table('membership_grants')->value('source_reference'))->toBe($reference);
    Http::assertNothingSent();
});

it('documents Membership schemas with exactly their approved fields, so no payment fact can appear in the contract', function () {
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($spec));
    $schemas = Api::map(Api::map($spec['components'])['schemas']);
    $fields = fn (string $name): array => array_keys(Api::map(Api::map($schemas[$name])['properties']));

    expect($fields('Member'))->toBe(['person', 'active', 'current_access_ends_at', 'open_ended', 'grants'])
        ->and($fields('MembershipGrant'))->toBe(['id', 'person_id', 'starts_at', 'ends_at', 'source', 'source_reference', 'revoked_at'])
        ->and($fields('MembershipGrantHistoryEntry'))->toBe(['id', 'starts_at', 'ends_at', 'source', 'source_reference', 'revoked_at'])
        ->and($fields('MemberPage'))->toBe(['data', 'meta'])
        ->and($fields('RegisterMemberRequest'))->toBe(['display_name', 'starts_at', 'open_ended', 'ends_at', 'source', 'source_reference'])
        ->and($fields('GrantMembershipRequest'))->toBe(['starts_at', 'open_ended', 'ends_at', 'source', 'source_reference']);

    // The only accepted sources are provenance, and they are the two Phase-1 ones.
    expect(Api::map(Api::map(Api::map($schemas['MembershipGrant'])['properties'])['source'])['enum'])->toBe(['operator', 'luma_legacy']);
    expect(Access::everyCapabilityId())->toContain('membership.records.manage'); // the catalog these operations are gated by
});
