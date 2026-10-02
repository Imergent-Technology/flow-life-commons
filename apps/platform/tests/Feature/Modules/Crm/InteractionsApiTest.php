<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Membership;
use Tests\Support\Mfa;

/*
 * Notes and interactions on the wire (ADR 0034): the exact response shape, validation, stable errors, the attribution a reader
 * sees, and what must never appear in any of it. Capability layering is in PeopleAccessControlTest. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

const INTERACTION_KEYS = ['id', 'kind', 'body', 'occurred_at', 'author', 'updated_by', 'created_at', 'updated_at'];

it('records, lists, corrects and removes a note over HTTP, with the documented shape', function () {
    [$console, $guardian] = Crm::signedInGuardian();
    $person = Identity::savedPerson('Ada')->id->value;

    $created = $console->post("/api/v1/admin/people/{$person}/interactions", ['kind' => 'call', 'body' => 'Rang about the workshop', 'occurred_at' => '2026-09-30T08:30:00.000-05:00'])->assertCreated();
    $id = Api::string($created->json('id'));

    expect(array_keys(Api::map($created->json())))->toBe(INTERACTION_KEYS)
        ->and($created->json('kind'))->toBe('call')
        ->and($created->json('occurred_at'))->toBe('2026-09-30T13:30:00Z')
        ->and($created->json('created_at'))->toBe('2026-10-01T12:00:00Z')
        ->and($created->json('author'))->toBe(['id' => $guardian->personId->value, 'display_name' => 'Gina Guardian'])
        ->and($created->json('updated_by'))->toBeNull();

    $list = Api::map($console->get("/api/v1/admin/people/{$person}/interactions")->assertOk()->json());
    expect(array_keys($list))->toBe(['data', 'meta'])
        ->and(array_keys(Api::rows($list['data'])[0]))->toBe(INTERACTION_KEYS)
        ->and(array_keys(Api::map($list['meta'])))->toBe(['page', 'per_page', 'total', 'last_page'])
        ->and(Api::map($list['meta'])['total'])->toBe(1);

    $edited = $console->patch("/api/v1/admin/people/{$person}/interactions/{$id}", ['body' => 'Rang about the workshop; sending details'])->assertOk();
    expect(array_keys(Api::map($edited->json())))->toBe(INTERACTION_KEYS)
        ->and($edited->json('body'))->toBe('Rang about the workshop; sending details')
        ->and($edited->json('kind'))->toBe('call')
        ->and($edited->json('updated_by.display_name'))->toBe('Gina Guardian')
        ->and($edited->json('updated_at'))->toBe('2026-10-01T12:00:00Z');

    $console->delete("/api/v1/admin/people/{$person}/interactions/{$id}")->assertNoContent();
    expect(Api::rows($console->get("/api/v1/admin/people/{$person}/interactions")->assertOk()->json('data')))->toBe([]);
});

it('defaults the kind to a note and the time to now, and accepts a UTC "Z" instant', function () {
    [$console] = Crm::signedInGuardian();
    $person = Identity::savedPerson('Ada')->id->value;

    $plain = $console->post("/api/v1/admin/people/{$person}/interactions", ['body' => 'Just a note'])->assertCreated();
    $zulu = $console->post("/api/v1/admin/people/{$person}/interactions", ['body' => 'Earlier', 'occurred_at' => '2026-09-01T10:00:00Z', 'kind' => 'meeting'])->assertCreated();
    $null = $console->post("/api/v1/admin/people/{$person}/interactions", ['body' => 'Explicit null', 'occurred_at' => null])->assertCreated();

    expect($plain->json('kind'))->toBe('note')->and($plain->json('occurred_at'))->toBe('2026-10-01T12:00:00Z')
        ->and($zulu->json('occurred_at'))->toBe('2026-09-01T10:00:00Z')
        ->and($null->json('occurred_at'))->toBe('2026-10-01T12:00:00Z');
});

it('lists newest first and pages with meta', function () {
    [$console, $guardian] = Crm::signedInGuardian();
    $by = Access::actorFor($guardian);
    $person = Identity::savedPerson('Ada')->id;
    foreach (range(1, 3) as $n) {
        Crm::interaction($by, $person, "n{$n}", "2026-09-0{$n} 10:00:00");
    }

    $second = Api::map($console->get("/api/v1/admin/people/{$person->value}/interactions?per_page=1&page=2")->assertOk()->json());

    expect(array_column(Api::rows($second['data']), 'body'))->toBe(['n2'])
        ->and(Api::map($second['meta']))->toBe(['page' => 2, 'per_page' => 1, 'total' => 3, 'last_page' => 3]);
});

it('validates the list query', function () {
    [$console] = Crm::signedInGuardian();
    $person = Identity::savedPerson('Ada')->id->value;

    foreach (['page=0', 'page=abc', 'per_page=101', 'per_page=0'] as $query) {
        expect($console->get("/api/v1/admin/people/{$person}/interactions?{$query}")->status())->toBe(422, $query);
    }
});

it('validates what is sent when recording', function () {
    [$console] = Crm::signedInGuardian();
    $person = Identity::savedPerson('Ada')->id->value;
    $url = "/api/v1/admin/people/{$person}/interactions";

    foreach ([
        'no body' => [],
        'blank body' => ['body' => '   '],
        'array body' => ['body' => ['x']],
        'unknown kind' => ['body' => 'x', 'kind' => 'carrier-pigeon'],
        'date without a zone' => ['body' => 'x', 'occurred_at' => '2026-09-01 10:00:00'],
        'date only' => ['body' => 'x', 'occurred_at' => '2026-09-01'],
        'impossible date' => ['body' => 'x', 'occurred_at' => '2026-02-31T10:00:00Z'],
        'nonsense instant' => ['body' => 'x', 'occurred_at' => 'last tuesday'],
        'number as instant' => ['body' => 'x', 'occurred_at' => 1790000000],
    ] as $label => $body) {
        expect($console->post($url, $body)->status())->toBe(422, $label);
    }

    $future = $console->post($url, ['body' => 'x', 'occurred_at' => '2026-10-02T12:00:00Z']);
    $tooLong = $console->post($url, ['body' => str_repeat('x', 5001)]);
    expect($future->status())->toBe(422)->and($future->json('code'))->toBe('invalid_contact_input')->and($future->json('errors.occurred_at'))->not->toBeNull()
        ->and($tooLong->status())->toBe(422)->and($tooLong->json('errors.body'))->not->toBeNull()
        ->and(DB::table('contact_interactions')->count())->toBe(0);
});

it('requires something to change, and refuses a blank body or a null time on edit', function () {
    [$console, $guardian] = Crm::signedInGuardian();
    $person = Identity::savedPerson('Ada')->id;
    $id = Crm::interaction(Access::actorFor($guardian), $person, 'keep')->interaction->id->value;
    $url = "/api/v1/admin/people/{$person->value}/interactions/{$id}";

    foreach (['nothing sent' => [], 'blank body' => ['body' => ''], 'null body' => ['body' => null], 'null time' => ['occurred_at' => null], 'bad kind' => ['kind' => 'x']] as $label => $body) {
        expect($console->patch($url, $body)->status())->toBe(422, $label);
    }
    expect(DB::table('contact_interactions')->value('body'))->toBe('keep');
});

it('uses stable codes: person_not_found and interaction_not_found, and a note cannot be reached through another Person', function () {
    [$console, $guardian] = Crm::signedInGuardian();
    $ada = Identity::savedPerson('Ada')->id;
    $grace = Identity::savedPerson('Grace')->id->value;
    $note = Crm::interaction(Access::actorFor($guardian), $ada, 'ada\'s')->interaction->id->value;
    $nobody = strtolower((string) Str::ulid());
    $unknownNote = strtolower((string) Str::ulid());

    $responses = [
        'list unknown person' => [$console->get("/api/v1/admin/people/{$nobody}/interactions"), 'person_not_found'],
        'create unknown person' => [$console->post("/api/v1/admin/people/{$nobody}/interactions", ['body' => 'x']), 'person_not_found'],
        'edit unknown person' => [$console->patch("/api/v1/admin/people/{$nobody}/interactions/{$note}", ['body' => 'x']), 'person_not_found'],
        'delete unknown person' => [$console->delete("/api/v1/admin/people/{$nobody}/interactions/{$note}"), 'person_not_found'],
        'edit unknown note' => [$console->patch("/api/v1/admin/people/{$ada->value}/interactions/{$unknownNote}", ['body' => 'x']), 'interaction_not_found'],
        'delete unknown note' => [$console->delete("/api/v1/admin/people/{$ada->value}/interactions/{$unknownNote}"), 'interaction_not_found'],
        'edit via other person' => [$console->patch("/api/v1/admin/people/{$grace}/interactions/{$note}", ['body' => 'hijack']), 'interaction_not_found'],
        'delete via other person' => [$console->delete("/api/v1/admin/people/{$grace}/interactions/{$note}"), 'interaction_not_found'],
    ];

    foreach ($responses as $label => [$response, $code]) {
        expect($response->status())->toBe(404, $label)->and($response->json('code'))->toBe($code, $label);
    }
    expect(DB::table('contact_interactions')->value('body'))->toBe('ada\'s');

    $console->get('/api/v1/admin/people/not-a-ulid/interactions')->assertNotFound();
    $console->patch("/api/v1/admin/people/{$ada->value}/interactions/not-a-ulid", ['body' => 'x'])->assertNotFound();
});

it('does not let a body field choose the author, the Person or the editor', function () {
    [$console, $guardian] = Crm::signedInGuardian();
    $ada = Identity::savedPerson('Ada')->id->value;
    $other = Identity::savedPerson('Other')->id->value;

    $created = $console->post("/api/v1/admin/people/{$ada}/interactions", ['body' => 'x', 'author' => $other, 'author_person_id' => $other, 'person_id' => $other, 'created_at' => '2000-01-01T00:00:00Z'])->assertCreated();

    expect($created->json('author.id'))->toBe($guardian->personId->value)
        ->and(DB::table('contact_interactions')->value('person_id'))->toBe($ada)
        ->and($created->json('created_at'))->toBe('2026-10-01T12:00:00Z');
});

it('discloses nothing about Accounts, access, Membership or security, even about an author who has all of them', function () {
    [$console, $guardian] = Crm::signedInGuardian('gina.login@example.org', 'Gina Author');
    Membership::savedGrant($guardian->personId);
    $subject = Identity::savedPerson('Ada')->id->value;

    $created = $console->post("/api/v1/admin/people/{$subject}/interactions", ['body' => 'Plain business content'])->assertCreated();
    $id = Api::string($created->json('id'));
    $responses = [
        $created,
        $console->get("/api/v1/admin/people/{$subject}/interactions"),
        $console->patch("/api/v1/admin/people/{$subject}/interactions/{$id}", ['body' => 'Edited business content']),
        $console->post("/api/v1/admin/people/{$subject}/interactions", ['body' => '']),
        $console->get('/api/v1/admin/people/'.strtolower((string) Str::ulid()).'/interactions'),
    ];

    foreach ($responses as $response) {
        $text = preg_replace('/[0-9a-z]{26}/', 'ID', strtolower((string) $response->getContent())); // a random id can spell any word
        expect($text)->not->toContain('gina.login@example.org')
            ->and($text)->not->toContain($guardian->id->value) // the Account id
            ->and($text)->not->toMatch('/account|role|capabilit|guardian|mfa|totp|recovery|invitation|session|generation|membership|grant|status|password|secret|token/');
    }
    expect((string) $responses[1]->getContent())->toContain('Gina Author'); // positive control: the author's name IS shown
});

it('keeps an ordinary Member out of every interaction route, and leaves the notes untouched', function () {
    [, $guardian] = Crm::signedInGuardian();
    $person = Identity::savedPerson('Ada')->id;
    $note = Crm::interaction(Access::actorFor($guardian), $person, 'for Guardians')->interaction->id->value;
    [$member] = Mfa::signedInMember();

    expect($member->get("/api/v1/admin/people/{$person->value}/interactions")->status())->toBe(403)
        ->and($member->post("/api/v1/admin/people/{$person->value}/interactions", ['body' => 'x'])->status())->toBe(403)
        ->and($member->patch("/api/v1/admin/people/{$person->value}/interactions/{$note}", ['body' => 'x'])->status())->toBe(403)
        ->and($member->delete("/api/v1/admin/people/{$person->value}/interactions/{$note}")->status())->toBe(403)
        ->and(DB::table('contact_interactions')->count())->toBe(1)
        ->and(DB::table('contact_interactions')->value('body'))->toBe('for Guardians');
});
