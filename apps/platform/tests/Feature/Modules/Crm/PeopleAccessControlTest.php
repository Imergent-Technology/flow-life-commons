<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * Who may use the People API (ADR 0034), at each layer on its own. The role catalog has no role that holds
 * `crm.people.view` without `.manage` (a Guardian holds both, deliberately), so, as in Membership's tests, ONE layer is moved
 * at a time through the platform's own seam, the Laravel Gate the route `can:` middleware asks, keeping the other real.
 * Runs on MariaDB and PostgreSQL.
 */

/** Makes the Gate answer `$answer` for these abilities only; every other ability still asks the real Authorizer. */
function crmGate(bool $answer, string ...$abilities): void
{
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => in_array($ability, $abilities, true) ? $answer : null);
}

/** @return list<array{string, string, array<string, mixed>}> every operation: method, path, a valid body */
function crmOperations(string $person, string $method, string $tag, string $note): array
{
    return [
        ['GET', '/api/v1/admin/people', []],
        ['POST', '/api/v1/admin/people', ['display_name' => 'Fresh Face']],
        ['GET', "/api/v1/admin/people/{$person}", []],
        ['PATCH', "/api/v1/admin/people/{$person}", ['affiliation' => 'Guild']],
        ['POST', "/api/v1/admin/people/{$person}/contact-methods", ['kind' => 'email', 'value' => 'new@example.org']],
        ['PATCH', "/api/v1/admin/people/{$person}/contact-methods/{$method}", ['label' => 'work']],
        ['DELETE', "/api/v1/admin/people/{$person}/contact-methods/{$method}", []],
        ['GET', "/api/v1/admin/people/{$person}/interactions", []],
        ['POST', "/api/v1/admin/people/{$person}/interactions", ['body' => 'A new note']],
        ['PATCH', "/api/v1/admin/people/{$person}/interactions/{$note}", ['body' => 'Corrected']],
        ['DELETE', "/api/v1/admin/people/{$person}/interactions/{$note}", []],
        ['PUT', "/api/v1/admin/people/{$person}/tags", ['tag_ids' => []]],
        ['GET', '/api/v1/admin/contact-tags', []],
        ['POST', '/api/v1/admin/contact-tags', ['name' => 'Fresh']],
        ['PATCH', "/api/v1/admin/contact-tags/{$tag}", ['name' => 'Renamed']],
        ['DELETE', "/api/v1/admin/contact-tags/{$tag}", []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function crmCall(Console $console, string $verb, string $path, array $body): TestResponse
{
    return match ($verb) {
        default => throw new InvalidArgumentException($verb),
        'GET' => $console->get($path),
        'POST' => $console->post($path, $body),
        'PATCH' => $console->patch($path, $body),
        'PUT' => $console->put($path, $body),
        'DELETE' => $console->delete($path),
    };
}

/** @return array{string, string, string, string} a Person, one of their contact methods, an unused tag, and one of their notes */
function crmFixtures(): array
{
    $by = Crm::manager('fixture.manager@example.org');
    $person = Identity::savedPerson('Subject');
    $method = Crm::email($by, $person->id, 'subject@example.org');

    $note = Crm::interaction($by, $person->id, 'An existing note');

    return [$person->id->value, $method->id->value, Crm::tag($by, 'Unused')->value, $note->interaction->id->value];
}

it('refuses every operation to someone who is not signed in', function () {
    [$person, $method, $tag, $note] = crmFixtures();
    $guest = new Console;

    foreach (crmOperations($person, $method, $tag, $note) as [$verb, $path, $body]) {
        expect(crmCall($guest, $verb, $path, $body)->status())->toBe(401, "{$verb} {$path}");
    }
});

it('refuses every operation to a signed-in Account without Console access, before the capability is even asked', function () {
    [$person, $method, $tag, $note] = crmFixtures();
    [$member] = Mfa::signedInMember();

    foreach (crmOperations($person, $method, $tag, $note) as [$verb, $path, $body]) {
        $response = crmCall($member, $verb, $path, $body);
        expect($response->status())->toBe(403, "{$verb} {$path}")->and($response->json('verification_required'))->toBeNull();
    }
    expect(DB::table('contact_methods')->count())->toBe(1)->and(DB::table('contact_interactions')->count())->toBe(1);
});

it('lets a Guardian, who holds both CRM capabilities, read AND write', function () {
    [$console] = Mfa::signedIn();
    $person = Identity::savedPerson('Subject');

    $console->get('/api/v1/admin/people')->assertOk();
    $console->post("/api/v1/admin/people/{$person->id->value}/contact-methods", ['kind' => 'email', 'value' => 'a@example.org'])->assertCreated();
    $console->post('/api/v1/admin/contact-tags', ['name' => 'Lead'])->assertCreated();
    $console->get("/api/v1/admin/people/{$person->id->value}")->assertOk();
});

it('lets a platform administrator do the same, holding every capability', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Subject');

    $console->get('/api/v1/admin/people')->assertOk();
    $console->patch("/api/v1/admin/people/{$person->id->value}", ['affiliation' => 'Guild'])->assertOk();
});

it('asks for no fresh proof: a CRM mutation succeeds long after sign-in, where an authority-bearing one is refused', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Subject');
    $console->advanceWhileActive(20 * 60); // beyond the 15-minute step-up window, session still alive

    // Control: the window really has closed. A Membership mutation (it grants entitlement) asks for the proof.
    $stale = $console->post('/api/v1/admin/members', ['display_name' => 'X', 'starts_at' => '2026-10-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator']);
    expect($stale->status())->toBe(403)->and($stale->json('verification_required'))->toBeTrue();

    // CRM maintenance does not: correcting a name, a note, a number, a tag.
    $console->post("/api/v1/admin/people/{$person->id->value}/contact-methods", ['kind' => 'phone', 'value' => '555 010 0100'])->assertCreated();
    $console->patch("/api/v1/admin/people/{$person->id->value}", ['display_name' => 'Subject Renamed', 'how_we_know' => 'Market'])->assertOk();
    $console->post('/api/v1/admin/contact-tags', ['name' => 'Lead'])->assertCreated();

    expect(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Subject Renamed');
});

it('stops a mutation at the HTTP layer alone: capability refused at the route, although the use case would allow it', function () {
    [$console] = Mfa::signedIn();
    [$person, $method, $tag, $note] = crmFixtures();
    crmGate(false, 'crm.people.manage'); // view stays real (granted), manage is refused at the route only

    foreach (crmOperations($person, $method, $tag, $note) as [$verb, $path, $body]) {
        if ($verb === 'GET') {
            expect(crmCall($console, $verb, $path, $body)->status())->toBe(200, "{$verb} {$path}"); // a view-only operator still reads
        } else {
            $response = crmCall($console, $verb, $path, $body);
            expect($response->status())->toBe(403, "{$verb} {$path}")->and($response->json('verification_required'))->toBeNull();
        }
    }
    expect(DB::table('contact_methods')->count())->toBe(1)->and(DB::table('contact_tags')->count())->toBe(1)
        ->and(DB::table('contact_interactions')->count())->toBe(1)->and(DB::table('contact_interactions')->value('body'))->toBe('An existing note');
});

it('stops a read at the HTTP layer alone, and does not make reading a precondition of writing', function () {
    [$console] = Mfa::signedIn();
    [$person, $method, $tag, $note] = crmFixtures();
    crmGate(false, 'crm.people.view'); // manage stays real, view is refused at the route only

    foreach (crmOperations($person, $method, $tag, $note) as [$verb, $path, $body]) {
        $response = crmCall($console, $verb, $path, $body);
        if ($verb === 'GET') {
            expect($response->status())->toBe(403, "{$verb} {$path}");
        } else {
            // No hidden manage -> view dependency: every mutation completes (the response is what was written, never a re-read).
            expect($response->status())->toBeIn([200, 201, 204], "{$verb} {$path}");
        }
    }
});

it('stops every operation at the Application layer alone: the route lets a signed-in Account through, the use case refuses', function () {
    [$person, $method, $tag, $note] = crmFixtures();
    [$member] = Mfa::signedInMember('plain@example.org');
    crmGate(true, 'console.access', 'crm.people.view', 'crm.people.manage'); // every route-level check passes; the Account holds nothing

    foreach (crmOperations($person, $method, $tag, $note) as [$verb, $path, $body]) {
        $response = crmCall($member, $verb, $path, $body);
        expect($response->status())->toBe(403, "{$verb} {$path}");
    }
    expect(DB::table('contact_methods')->count())->toBe(1)->and(DB::table('contact_tags')->count())->toBe(1)
        ->and(DB::table('contact_interactions')->count())->toBe(1)->and(DB::table('contact_interactions')->value('body'))->toBe('An existing note')
        ->and(DB::table('people')->where('display_name', 'Fresh Face')->exists())->toBeFalse();
});

it('does not let a read capability change data, nor a Guardian\'s tag grant them anything', function () {
    [$console, $guardian] = Mfa::signedIn();
    $tags = [$console->post('/api/v1/admin/contact-tags', ['name' => 'platform_administrator'])->json('id')];

    $console->put("/api/v1/admin/people/{$guardian->personId->value}/tags", ['tag_ids' => $tags])->assertOk();

    expect(Api::strings($console->me()->json('capabilities')))->toBe(['console.access', 'crm.people.manage', 'crm.people.view', 'discussions.participate', 'discussions.view', 'resources.manage', 'resources.view']);
});

it('resolves the caller from the session, never from the request: a person_id in a body is only data', function () {
    [$console, $guardian] = Mfa::signedIn();
    $other = Identity::savedPerson('Other');

    $console->post('/api/v1/admin/people', ['display_name' => 'Spoof', 'person_id' => $other->id->value, 'account_id' => $guardian->id->value, 'actor' => $other->id->value])->assertCreated();

    expect(DB::table('people')->where('display_name', 'Spoof')->count())->toBe(1)
        ->and(DB::table('contact_profiles')->count())->toBe(0);
});
