<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * The People API on the wire (ADR 0034): its route table, its exact response shapes, what it must never disclose, its stable
 * errors, and its OpenAPI contract. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

// --- The route table ---------------------------------------------------------------------------------------------

it('serves exactly the documented People routes, each behind the Console boundary and ONE capability, none behind step-up', function () {
    $expected = [
        'GET api/v1/admin/people' => 'crm.people.view',
        'POST api/v1/admin/people' => 'crm.people.manage',
        'GET api/v1/admin/people/{person}' => 'crm.people.view',
        'PATCH api/v1/admin/people/{person}' => 'crm.people.manage',
        'POST api/v1/admin/people/{person}/contact-methods' => 'crm.people.manage',
        'PATCH api/v1/admin/people/{person}/contact-methods/{method}' => 'crm.people.manage',
        'DELETE api/v1/admin/people/{person}/contact-methods/{method}' => 'crm.people.manage',
        'PUT api/v1/admin/people/{person}/tags' => 'crm.people.manage',
        'GET api/v1/admin/contact-tags' => 'crm.people.view',
        'POST api/v1/admin/contact-tags' => 'crm.people.manage',
        'PATCH api/v1/admin/contact-tags/{tag}' => 'crm.people.manage',
        'DELETE api/v1/admin/contact-tags/{tag}' => 'crm.people.manage',
    ];

    $actual = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), 'App\\Modules\\Crm\\Http\\')) {
            continue;
        }
        $middleware = Api::strings($route->gatherMiddleware());
        $capabilities = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));
        foreach (array_diff(Api::strings($route->methods()), ['HEAD']) as $method) {
            $actual["{$method} {$route->uri()}"] = substr($capabilities[0] ?? '', 4);
        }
        expect($middleware)->toContain('stateful', 'auth:web', 'can:console.access')
            ->and($capabilities)->toHaveCount(1)
            ->and($middleware)->not->toContain('security.verified');
    }

    ksort($actual);
    ksort($expected);
    expect($actual)->toBe($expected);
    // Reads need view, every change needs manage: no operation is reachable on the wrong capability.
    foreach ($actual as $key => $capability) {
        expect($capability)->toBe(str_starts_with($key, 'GET ') ? 'crm.people.view' : 'crm.people.manage', $key);
    }
});

it('does not match a malformed id: the route is simply not there (404), not a 500', function () {
    [$console] = Mfa::signedIn();

    $console->get('/api/v1/admin/people/not-a-ulid')->assertNotFound();
    $console->patch('/api/v1/admin/people/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['affiliation' => 'x'])->assertNotFound(); // uppercase / not a valid ULID
    $console->delete('/api/v1/admin/contact-tags/12345')->assertNotFound();
});

// --- Exact response shapes ---------------------------------------------------------------------------------------

/** @return array{Console, string, string, string} a signed-in Guardian, a Person with everything, a method, a tag */
function crmWorld(): array
{
    [$console, $guardian] = Crm::signedInGuardian();
    $by = Access::actorFor($guardian);
    $person = Identity::savedPerson('Ada Lovelace');
    $method = Crm::email($by, $person->id, 'ada@example.org');
    Crm::phone($by, $person->id, '(555) 010-0100');
    $tag = Crm::tag($by, 'Partner');
    Crm::tagPerson($by, $person->id, [$tag]);
    $console->patch("/api/v1/admin/people/{$person->id->value}", ['how_we_know' => 'Workshop', 'affiliation' => 'Guild'])->assertOk();

    return [$console, $person->id->value, $method->id->value, $tag->value];
}

it('returns exactly the documented keys, in every response', function () {
    [$console, $person, $method, $tag] = crmWorld();

    $record = Api::map($console->get("/api/v1/admin/people/{$person}")->assertOk()->json());
    expect(array_keys($record))->toBe(['person', 'profile', 'contact_methods', 'tags'])
        ->and(array_keys(Api::map($record['person'])))->toBe(['id', 'display_name'])
        ->and(array_keys(Api::map($record['profile'])))->toBe(['how_we_know', 'affiliation', 'updated_at'])
        ->and(array_keys(Api::rows($record['contact_methods'])[0]))->toBe(['id', 'kind', 'value', 'label', 'is_primary', 'created_at', 'updated_at'])
        ->and(array_keys(Api::rows($record['tags'])[0]))->toBe(['id', 'name']);

    $page = Api::map($console->get('/api/v1/admin/people?q=ada')->assertOk()->json());
    expect(array_keys($page))->toBe(['data', 'meta'])
        ->and(array_keys(Api::rows($page['data'])[0]))->toBe(['id', 'display_name', 'primary_email', 'primary_phone', 'tags'])
        ->and(array_keys(Api::map($page['meta'])))->toBe(['page', 'per_page', 'total', 'last_page']);

    expect(array_keys(Api::map($console->patch("/api/v1/admin/people/{$person}", ['affiliation' => 'Guild'])->assertOk()->json())))->toBe(['person', 'profile'])
        ->and(array_keys(Api::map($console->post("/api/v1/admin/people/{$person}/contact-methods", ['kind' => 'email', 'value' => 'b@example.org'])->assertCreated()->json())))->toBe(['id', 'kind', 'value', 'label', 'is_primary', 'created_at', 'updated_at'])
        ->and(array_keys(Api::map($console->patch("/api/v1/admin/people/{$person}/contact-methods/{$method}", ['label' => 'home'])->assertOk()->json())))->toBe(['id', 'kind', 'value', 'label', 'is_primary', 'created_at', 'updated_at'])
        ->and(array_keys(Api::map($console->put("/api/v1/admin/people/{$person}/tags", ['tag_ids' => [$tag]])->assertOk()->json())))->toBe(['data'])
        ->and(array_keys(Api::rows($console->get('/api/v1/admin/contact-tags')->assertOk()->json('data'))[0]))->toBe(['id', 'name', 'person_count'])
        ->and(array_keys(Api::map($console->post('/api/v1/admin/contact-tags', ['name' => 'Lead'])->assertCreated()->json())))->toBe(['id', 'name', 'person_count']);
});

it('discloses nothing about Accounts, access, Membership or security, even for a Person who has all of them', function () {
    [$console] = Mfa::signedIn();
    $operator = Identity::savedActiveAccount('operator.login@example.org', name: 'Operator Person');
    Access::grant($operator, Role::Guardian);
    Membership::savedGrant($operator->personId);
    Mfa::enroll($operator);
    $console->post("/api/v1/admin/people/{$operator->personId->value}/contact-methods", ['kind' => 'email', 'value' => 'different.contact@example.org'])->assertCreated();

    $bodies = [
        $console->get("/api/v1/admin/people/{$operator->personId->value}")->getContent(),
        $console->get('/api/v1/admin/people?q=operator')->getContent(),
        $console->get('/api/v1/admin/people?per_page=100')->getContent(),
    ];

    foreach ($bodies as $body) {
        $text = strtolower((string) $body);
        expect($text)->not->toContain('operator.login@example.org') // the Account's login email
            ->and($text)->not->toContain($operator->id->value)       // the Account id
            ->and($text)->not->toMatch('/account|role|capabilit|guardian|mfa|totp|recovery|invitation|session|generation|membership|grant|status|password|secret|token/');
    }
    expect((string) $bodies[0])->toContain('different.contact@example.org'); // what CRM holds IS shown
});

it('does not find a Person by their Account login email through HTTP either', function () {
    [$console] = Mfa::signedIn();
    Identity::savedActiveAccount('hidden.login@example.org', name: 'Someone');

    expect(Api::rows($console->get('/api/v1/admin/people?q=hidden.login')->assertOk()->json('data')))->toBe([]);
});

// --- Behaviour over HTTP ---------------------------------------------------------------------------------------------

it('pages, filters and searches the directory', function () {
    [$console, $person, , $tag] = crmWorld();
    Identity::savedPerson('Grace Hopper');

    $byTag = Api::map($console->get("/api/v1/admin/people?tag={$tag}")->assertOk()->json());
    $byEmail = Api::map($console->get('/api/v1/admin/people?q=ADA@example')->assertOk()->json());
    $byPhone = Api::map($console->get('/api/v1/admin/people?q=555-0100')->assertOk()->json());
    $paged = Api::map($console->get('/api/v1/admin/people?per_page=1&page=2')->assertOk()->json());

    expect(array_column(Api::rows($byTag['data']), 'id'))->toBe([$person])
        ->and(array_column(Api::rows($byEmail['data']), 'id'))->toBe([$person])
        ->and(array_column(Api::rows($byPhone['data']), 'id'))->toBe([$person])
        ->and(Api::map($paged['meta'])['per_page'])->toBe(1)->and(Api::map($paged['meta'])['page'])->toBe(2)
        ->and(Api::rows($byTag['data'])[0]['primary_email'])->toBe('ada@example.org')
        ->and(Api::rows($byTag['data'])[0]['primary_phone'])->toBe('(555) 010-0100');
});

it('validates the directory query', function () {
    [$console] = Mfa::signedIn();

    foreach (['page=0', 'page=abc', 'per_page=101', 'per_page=0', 'tag=not-a-ulid', 'q='.str_repeat('x', 256)] as $query) {
        expect($console->get("/api/v1/admin/people?{$query}")->status())->toBe(422, $query);
    }
});

it('registers a Person over HTTP and answers 201 with the record', function () {
    [$console] = Mfa::signedIn();

    $response = $console->post('/api/v1/admin/people', [
        'display_name' => 'Mia Maker', 'how_we_know' => 'Market', 'affiliation' => 'Guild',
        'contact_methods' => [['kind' => 'email', 'value' => 'mia@example.org', 'label' => 'home'], ['kind' => 'phone', 'value' => '555 010 0199']],
    ])->assertCreated();

    expect($response->json('person.display_name'))->toBe('Mia Maker')
        ->and($response->json('profile.how_we_know'))->toBe('Market')
        ->and($response->json('contact_methods.0.is_primary'))->toBeTrue()
        ->and($response->json('contact_methods.0.label'))->toBe('home')
        ->and(DB::table('accounts')->where('person_id', $response->json('person.id'))->count())->toBe(0);
});

// --- Stable errors -----------------------------------------------------------------------------------------------------

it('answers possible_duplicate with directory information only, and registers with confirm_distinct', function () {
    [$console, $person] = crmWorld();

    $conflict = $console->post('/api/v1/admin/people', ['display_name' => 'Ada Lovelace', 'contact_methods' => [['kind' => 'email', 'value' => 'ADA@example.org']]]);
    $conflict->assertStatus(409);

    expect($conflict->json('code'))->toBe('possible_duplicate')
        ->and(array_keys(Api::map($conflict->json())))->toBe(['message', 'code', 'candidates'])
        ->and(Api::rows($conflict->json('candidates')))->toHaveCount(1)
        ->and(array_keys(Api::rows($conflict->json('candidates'))[0]))->toBe(['id', 'display_name', 'matched_on'])
        ->and($conflict->json('candidates.0.id'))->toBe($person)
        ->and(Api::strings($conflict->json('candidates.0.matched_on')))->toEqualCanonicalizing(['email', 'display_name']);

    $console->post('/api/v1/admin/people', ['display_name' => 'Ada Lovelace', 'confirm_distinct' => true])->assertCreated();
    expect(DB::table('people')->where('display_name', 'Ada Lovelace')->count())->toBe(2);
});

it('uses a stable code for each refusal, and never leaks SQL or the offending value', function () {
    [$console, $person, $method, $tag] = crmWorld();
    $unknown = '01jzzzzzzzzzzzzzzzzzzzzzzz'; // a valid-looking id that names nothing
    $unknown = strtolower((string) Str::ulid());

    $cases = [
        'person_not_found' => [404, $console->get("/api/v1/admin/people/{$unknown}")],
        'person_not_found ' => [404, $console->post("/api/v1/admin/people/{$unknown}/contact-methods", ['kind' => 'email', 'value' => 'a@example.org'])],
        'contact_method_not_found' => [404, $console->patch("/api/v1/admin/people/{$person}/contact-methods/{$unknown}", ['label' => 'x'])],
        'contact_method_not_found ' => [404, $console->delete("/api/v1/admin/people/{$person}/contact-methods/{$unknown}")],
        'tag_not_found' => [404, $console->patch("/api/v1/admin/contact-tags/{$unknown}", ['name' => 'Whatever'])],
        'tag_not_found ' => [404, $console->delete("/api/v1/admin/contact-tags/{$unknown}")],
        'duplicate_contact_method' => [409, $console->post("/api/v1/admin/people/{$person}/contact-methods", ['kind' => 'email', 'value' => 'ADA@example.org'])],
        'duplicate_tag' => [409, $console->post('/api/v1/admin/contact-tags', ['name' => 'partner'])],
        'tag_in_use' => [409, $console->delete("/api/v1/admin/contact-tags/{$tag}")],
        'unknown_tag' => [422, $console->put("/api/v1/admin/people/{$person}/tags", ['tag_ids' => [$unknown]])],
        'invalid_contact_input' => [422, $console->post("/api/v1/admin/people/{$person}/contact-methods", ['kind' => 'email', 'value' => 'not an email'])],
    ];

    foreach ($cases as $code => [$status, $response]) {
        $body = (string) $response->getContent();
        expect($response->status())->toBe($status, $code)->and($response->json('code'))->toBe(trim($code), $code)
            ->and($body)->not->toMatch('/SQLSTATE|constraint|contact_methods|contact_tags|exception|vendor|Illuminate/i')
            ->and($body)->not->toContain('not an email');
    }
});

it('answers 422 with per-field errors for malformed requests', function () {
    [$console, $person, $method] = crmWorld();

    $cases = [
        [$console->post('/api/v1/admin/people', []), 'display_name'],
        [$console->post('/api/v1/admin/people', ['display_name' => 'X', 'contact_methods' => [['kind' => 'fax', 'value' => '1']]]), 'contact_methods.0.kind'],
        [$console->patch("/api/v1/admin/people/{$person}", []), 'display_name'],
        [$console->patch("/api/v1/admin/people/{$person}", ['display_name' => '']), 'display_name'],
        [$console->patch("/api/v1/admin/people/{$person}/contact-methods/{$method}", []), 'value'],
        [$console->post("/api/v1/admin/people/{$person}/contact-methods", ['kind' => 'email']), 'value'],
        [$console->put("/api/v1/admin/people/{$person}/tags", []), 'tag_ids'],
        [$console->put("/api/v1/admin/people/{$person}/tags", ['tag_ids' => ['nope']]), 'tag_ids.0'],
        [$console->post('/api/v1/admin/contact-tags', ['name' => str_repeat('x', 65)]), 'name'],
    ];
    foreach ($cases as [$response, $field]) {
        expect($response->status())->toBe(422)->and(Api::map($response->json('errors')))->toHaveKey($field);
    }
});

it('clears a profile field sent as null, and corrects a name, in one PATCH', function () {
    [$console, $person] = crmWorld();

    $response = $console->patch("/api/v1/admin/people/{$person}", ['display_name' => 'Ada King', 'affiliation' => null])->assertOk();

    expect($response->json('person.display_name'))->toBe('Ada King')
        ->and($response->json('profile.affiliation'))->toBeNull()
        ->and($response->json('profile.how_we_know'))->toBe('Workshop')
        ->and(Identity::events('person.renamed'))->toHaveCount(1);
});

it('deletes a method and a tag, and answers 204 with no body', function () {
    [$console, $person, $method] = crmWorld();
    $unused = Api::string($console->post('/api/v1/admin/contact-tags', ['name' => 'Spare'])->json('id'));

    $console->delete("/api/v1/admin/people/{$person}/contact-methods/{$method}")->assertNoContent();
    $console->delete("/api/v1/admin/contact-tags/{$unused}")->assertNoContent();

    expect(DB::table('contact_methods')->where('id', $method)->exists())->toBeFalse()
        ->and(DB::table('contact_tags')->where('id', $unused)->exists())->toBeFalse();
});

// --- The OpenAPI contract ----------------------------------------------------------------------------------------------

/** @return array<string, mixed> */
function crmSpec(): array
{
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($spec));

    /** @var array<string, mixed> $spec */
    return $spec;
}

/** @return array<string, mixed> */
function crmSchema(string $name): array
{
    return Api::map(Api::map(Api::map(crmSpec()['components'])['schemas'])[$name]);
}

function crmRef(mixed $node): string
{
    $ref = Api::map($node)['$ref'] ?? null;
    assert(is_string($ref));

    return substr($ref, strlen('#/components/schemas/'));
}

function crmResponseRef(string $path, string $method, string $status): string
{
    $operation = Api::map(Api::map(Api::map(crmSpec()['paths'])[$path])[$method]);

    return crmRef(Api::map(Api::map(Api::map(Api::map($operation['responses'])[$status])['content'])['application/json'])['schema']);
}

/** Asserts a real body has exactly the keys its schema declares, all required (the platform's convention). */
function expectCrmBody(mixed $body, string $schemaName): void
{
    $schema = crmSchema($schemaName);
    $required = Api::strings($schema['required']);

    expect(array_keys(Api::map($body)))->toEqualCanonicalizing($required, "{$schemaName}: body vs required")
        ->and(array_keys(Api::map($schema['properties'])))->toEqualCanonicalizing($required, "{$schemaName}: properties vs required");
}

it('serves responses matching their OpenAPI schemas', function () {
    [$console, $person, , $tag] = crmWorld();

    $record = $console->get("/api/v1/admin/people/{$person}")->assertOk()->json();
    expectCrmBody($record, 'PersonRecord');
    expectCrmBody(Api::map($record)['person'], 'PersonRef');
    expectCrmBody(Api::map($record)['profile'], 'ContactProfile');
    expectCrmBody(Api::rows(Api::map($record)['contact_methods'])[0], 'ContactMethod');
    expectCrmBody(Api::rows(Api::map($record)['tags'])[0], 'ContactTagRef');

    $page = Api::map($console->get('/api/v1/admin/people')->assertOk()->json());
    expect(array_keys($page))->toEqualCanonicalizing(Api::strings(crmSchema('PersonListingPage')['required']))
        ->and(array_keys(Api::map($page['meta'])))->toEqualCanonicalizing(Api::strings(Api::map(Api::map(crmSchema('PersonListingPage')['properties'])['meta'])['required']));
    expectCrmBody(Api::rows($page['data'])[0], 'PersonListing');

    expectCrmBody($console->patch("/api/v1/admin/people/{$person}", ['affiliation' => 'Guild'])->assertOk()->json(), 'PersonUpdate');
    expectCrmBody($console->post("/api/v1/admin/people/{$person}/contact-methods", ['kind' => 'email', 'value' => 'b@example.org'])->assertCreated()->json(), 'ContactMethod');
    expectCrmBody($console->put("/api/v1/admin/people/{$person}/tags", ['tag_ids' => [$tag]])->assertOk()->json(), 'PersonTagList');
    expectCrmBody($console->get('/api/v1/admin/contact-tags')->assertOk()->json(), 'ContactTagList');
    expectCrmBody(Api::rows($console->get('/api/v1/admin/contact-tags')->json('data'))[0], 'ContactTag');
    expectCrmBody($console->post('/api/v1/admin/contact-tags', ['name' => 'Lead'])->assertCreated()->json(), 'ContactTag');

    $duplicate = $console->post('/api/v1/admin/people', ['display_name' => 'Ada Lovelace'])->assertStatus(409)->json();
    expectCrmBody($duplicate, 'PossibleDuplicate');
    $candidate = Api::rows(Api::map($duplicate)['candidates'])[0];
    expect(array_keys($candidate))->toEqualCanonicalizing(Api::strings(Api::map(Api::map(Api::map(crmSchema('PossibleDuplicate')['properties'])['candidates'])['items'])['required']));
});

it('points each response location at the schema that describes it', function () {
    expect(crmResponseRef('/admin/people', 'get', '200'))->toBe('PersonListingPage')
        ->and(crmResponseRef('/admin/people', 'post', '201'))->toBe('PersonRecord')
        ->and(crmResponseRef('/admin/people/{person}', 'get', '200'))->toBe('PersonRecord')
        ->and(crmResponseRef('/admin/people/{person}', 'patch', '200'))->toBe('PersonUpdate')
        ->and(crmResponseRef('/admin/people/{person}/contact-methods', 'post', '201'))->toBe('ContactMethod')
        ->and(crmResponseRef('/admin/people/{person}/contact-methods/{method}', 'patch', '200'))->toBe('ContactMethod')
        ->and(crmResponseRef('/admin/people/{person}/tags', 'put', '200'))->toBe('PersonTagList')
        ->and(crmResponseRef('/admin/contact-tags', 'get', '200'))->toBe('ContactTagList')
        ->and(crmResponseRef('/admin/contact-tags', 'post', '201'))->toBe('ContactTag')
        ->and(crmResponseRef('/admin/contact-tags/{tag}', 'patch', '200'))->toBe('ContactTag')
        ->and(crmRef(Api::map(Api::map(crmSchema('PersonRecord')['properties'])['contact_methods'])['items']))->toBe('ContactMethod');
});

it('documents the request schemas with the limits the runtime enforces', function () {
    $register = Api::map(crmSchema('RegisterPersonRequest')['properties']);
    $tag = Api::map(Api::map(crmSchema('TagNameRequest')['properties'])['name']);
    $method = Api::map(crmSchema('NewContactMethod')['properties']);

    expect(Api::map($register['display_name'])['maxLength'])->toBe(255)
        ->and(Api::map($register['how_we_know'])['maxLength'])->toBe(2000)
        ->and(Api::map($register['affiliation'])['maxLength'])->toBe(255)
        ->and(Api::map($register['contact_methods'])['maxItems'])->toBe(20)
        ->and($tag['maxLength'])->toBe(64)
        ->and(Api::map($method['label'])['maxLength'])->toBe(64)
        ->and(Api::map($method['kind'])['enum'])->toBe(['email', 'phone']);
});
