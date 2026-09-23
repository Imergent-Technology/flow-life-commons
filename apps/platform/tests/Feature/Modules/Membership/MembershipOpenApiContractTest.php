<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Api;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * The Membership operations' schemas say what the runtime really returns and accepts. The per-endpoint exact-key tests pin
 * the runtime; these tie the runtime to the published spec, which is where a nested-shape mismatch (a history entry with no
 * `person_id`, documented as if it had one) once went unnoticed. Same method as OpenApiContractTest's ManagedAccount check:
 * real response keys against the schema's `required` and `properties`, plus the `$ref` each location points at.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

/** @return array<string, mixed> */
function membershipSpec(): array
{
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($spec));

    /** @var array<string, mixed> $spec */
    return $spec;
}

/** @return array<string, mixed> */
function membershipSchema(string $name): array
{
    return Api::map(Api::map(Api::map(membershipSpec()['components'])['schemas'])[$name]);
}

/** The `$ref` target name of the schema an operation's response (or an inline schema node) points at. */
/** @return array<string, mixed> one property's schema node */
function propertyOf(string $schema, string $property): array
{
    return Api::map(Api::map(membershipSchema($schema)['properties'])[$property]);
}

function refName(mixed $node): string
{
    $ref = Api::map($node)['$ref'] ?? null;
    assert(is_string($ref));

    return substr($ref, strlen('#/components/schemas/'));
}

function responseSchemaRef(string $path, string $method, string $status): string
{
    $operation = Api::map(Api::map(Api::map(membershipSpec()['paths'])[$path])[$method]);
    $response = Api::map(Api::map($operation['responses'])[$status]);

    return refName(Api::map(Api::map($response['content'])['application/json'])['schema']);
}

/** Asserts a real body has exactly the keys its schema declares, all of them required (the platform's convention). */
function expectBodyMatchesSchema(mixed $body, string $schemaName): void
{
    $schema = membershipSchema($schemaName);
    $required = Api::strings($schema['required']);

    expect(array_keys(Api::map($body)))->toEqualCanonicalizing($required, "{$schemaName}: body keys vs required")
        ->and(array_keys(Api::map($schema['properties'])))->toEqualCanonicalizing($required, "{$schemaName}: properties vs required");
}

it('documents a nested history entry WITHOUT person_id, and the standalone grant WITH it, each as the runtime returns them', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Contract');
    Membership::savedGrant($person->id);

    $standalone = $console->post("/api/v1/admin/members/{$person->id->value}/grants", ['starts_at' => '2027-01-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'])->assertCreated()->json();
    $nested = $console->get("/api/v1/admin/members/{$person->id->value}")->assertOk()->json('grants.0');

    expectBodyMatchesSchema($standalone, 'MembershipGrant');
    expectBodyMatchesSchema($nested, 'MembershipGrantHistoryEntry');

    // The distinction itself, stated in both directions.
    expect(Api::strings(membershipSchema('MembershipGrant')['required']))->toContain('person_id')
        ->and(Api::strings(membershipSchema('MembershipGrantHistoryEntry')['required']))->not->toContain('person_id')
        ->and(array_keys(Api::map(membershipSchema('MembershipGrantHistoryEntry')['properties'])))->not->toContain('person_id')
        ->and(Api::map($nested))->not->toHaveKey('person_id');
});

it('points each Membership response location at the schema that describes it', function () {
    $members = '/admin/members';

    expect(refName(propertyOf('Member', 'grants')['items'] ?? null))->toBe('MembershipGrantHistoryEntry')
        ->and(refName(propertyOf('MemberPage', 'data')['items'] ?? null))->toBe('Member')
        ->and(responseSchemaRef($members, 'get', '200'))->toBe('MemberPage')
        ->and(responseSchemaRef($members, 'post', '201'))->toBe('Member')
        ->and(responseSchemaRef('/admin/members/{person}', 'get', '200'))->toBe('Member')
        ->and(responseSchemaRef('/admin/members/{person}/grants', 'post', '201'))->toBe('MembershipGrant');
});

it('serves Member and MemberPage responses matching their schemas, nested grant history included', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Contract');
    Membership::savedGrant($person->id);

    $detail = $console->get("/api/v1/admin/members/{$person->id->value}")->assertOk();
    $page = $console->get('/api/v1/admin/members')->assertOk();
    $registered = $console->post('/api/v1/admin/members', ['display_name' => 'Fresh', 'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator'])->assertCreated();

    foreach ([$detail->json(), $page->json('data.0'), $registered->json()] as $member) {
        expectBodyMatchesSchema($member, 'Member');
        expectBodyMatchesSchema(Api::rows(Api::map($member)['grants'])[0] ?? null, 'MembershipGrantHistoryEntry');
    }
});

it('serves the page envelope and its meta as documented', function () {
    [$console] = Mfa::signedInAdmin();
    Membership::savedGrant(Identity::savedPerson('Contract')->id);

    $page = Api::map($console->get('/api/v1/admin/members')->assertOk()->json());
    $schema = membershipSchema('MemberPage');
    $meta = Api::map(Api::map($schema['properties'])['meta']);

    expect(array_keys($page))->toEqualCanonicalizing(Api::strings($schema['required']))
        ->and(array_keys(Api::map($page['meta'])))->toEqualCanonicalizing(Api::strings($meta['required']));
});

it('documents request-validation failures on the two term-carrying operations as the ordinary ValidationError, which the runtime really returns', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Contract');

    // An end that is not after the start: the HTTP rule catches it first, so there is NO stable `code` (the domain's
    // InvalidMembershipTerm is a backstop for non-HTTP callers and is not advertised as the HTTP response).
    $bodies = [
        '/api/v1/admin/members' => ['display_name' => 'X', 'starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => false, 'ends_at' => '2026-09-23T12:00:00Z', 'source' => 'operator'],
        "/api/v1/admin/members/{$person->id->value}/grants" => ['starts_at' => '2026-09-23T12:00:00Z', 'open_ended' => false, 'ends_at' => '2026-09-23T12:00:00Z', 'source' => 'operator'],
    ];
    $validation = membershipSchema('ValidationError');
    foreach ($bodies as $path => $body) {
        $response = $console->post($path, $body)->assertStatus(422);
        expect(array_keys(Api::map($response->json())))->toEqualCanonicalizing(Api::strings($validation['required']), $path)
            ->and(Api::map($response->json()))->not->toHaveKey('code');
    }

    expect(responseSchemaRef('/admin/members', 'post', '422'))->toBe('ValidationError')
        ->and(responseSchemaRef('/admin/members/{person}/grants', 'post', '422'))->toBe('ValidationError')
        ->and(Api::strings($validation['required']))->not->toContain('code')
        ->and(file_get_contents(base_path('openapi/openapi.yaml')))->not->toContain('invalid_membership_term');
});

it('requires an explicit open_ended and ends_at in both term-carrying request schemas', function () {
    foreach (['RegisterMemberRequest', 'GrantMembershipRequest'] as $name) {
        $schema = membershipSchema($name);
        $properties = Api::map($schema['properties']);

        expect(Api::strings($schema['required']))->toContain('open_ended')->toContain('ends_at')
            ->and(Api::map($properties['open_ended'])['type'])->toBe('boolean');
    }
});
