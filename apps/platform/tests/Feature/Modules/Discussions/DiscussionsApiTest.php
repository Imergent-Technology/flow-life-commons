<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Discussions;
use Tests\Support\Mfa;

/*
 * Guardian Discussions on the wire (ADR 0035): the exact response shapes, the stable error codes and their order, what a
 * client cannot forge, and what must never appear in any response. Use-case rules are DiscussionsTest; capability layers are
 * DiscussionsAccessControlTest. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

const DISCUSSION_KEYS = ['id', 'title', 'state', 'creator', 'message_count', 'last_activity_at', 'created_at', 'resolved_at', 'resolved_by'];
const LIVE_MESSAGE_KEYS = ['id', 'sequence', 'author', 'created_at', 'removed', 'body', 'edited_at', 'edited_by'];
const TOMBSTONE_KEYS = ['id', 'sequence', 'author', 'created_at', 'removed', 'removed_at'];
const PERSON_KEYS = ['id', 'display_name'];

/** @return list<string> */
function keysOf(mixed $value): array
{
    return array_keys(Api::map($value));
}

/**
 * Two signed-in Guardians: the Console of each, then the id of each one's Person.
 *
 * @return array{Console, Console, string, string}
 */
function twoGuardians(): array
{
    [$gina, $ginaAccount] = Discussions::signedInGuardian('gina.guardian@example.org', 'Gina Guardian');
    [$kai, $kaiAccount] = Discussions::signedInGuardian('kai.guardian@example.org', 'Kai Guardian');

    return [$gina, $kai, $ginaAccount->personId->value, $kaiAccount->personId->value];
}

it('runs the whole life of a discussion over HTTP, with the documented shapes', function () {
    [$console, $account] = Discussions::signedInGuardian();

    $started = $console->post('/api/v1/admin/discussions', ['title' => 'Where do we meet?', 'body' => 'Thoughts on the venue'])->assertCreated();
    $id = Api::string($started->json('id'));
    expect(keysOf($started->json()))->toBe(DISCUSSION_KEYS)
        ->and($started->json('title'))->toBe('Where do we meet?')->and($started->json('state'))->toBe('open')
        ->and($started->json('message_count'))->toBe(1)
        ->and($started->json('creator'))->toBe(['id' => $account->personId->value, 'display_name' => 'Gina Guardian'])
        ->and($started->json('last_activity_at'))->toBe('2026-10-01T12:00:00Z')->and($started->json('resolved_at'))->toBeNull()->and($started->json('resolved_by'))->toBeNull();

    $reply = $console->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'A reply'])->assertCreated();
    $replyId = Api::string($reply->json('id'));
    expect(keysOf($reply->json()))->toBe(LIVE_MESSAGE_KEYS)->and(keysOf($reply->json('author')))->toBe(PERSON_KEYS)
        ->and($reply->json('sequence'))->toBe(2)->and($reply->json('removed'))->toBeFalse()->and($reply->json('edited_at'))->toBeNull()->and($reply->json('edited_by'))->toBeNull();

    $messages = $console->get("/api/v1/admin/discussions/{$id}/messages")->assertOk();
    expect(keysOf($messages->json()))->toBe(['data', 'meta'])->and(keysOf($messages->json('meta')))->toBe(['page', 'per_page', 'total', 'last_page'])
        ->and(array_map(fn ($m) => Api::map($m)['sequence'], Api::rows($messages->json('data'))))->toBe([1, 2]);

    Carbon::setTestNow('2026-10-01 12:30:00');
    $edited = $console->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => 'A better reply'])->assertOk();
    expect(keysOf($edited->json()))->toBe(LIVE_MESSAGE_KEYS)->and($edited->json('body'))->toBe('A better reply')
        ->and($edited->json('edited_at'))->toBe('2026-10-01T12:30:00Z')->and($edited->json('edited_by'))->toBe(['id' => $account->personId->value, 'display_name' => 'Gina Guardian']);

    $retitled = $console->patch("/api/v1/admin/discussions/{$id}", ['title' => 'Where shall we meet?'])->assertOk();
    expect(keysOf($retitled->json()))->toBe(DISCUSSION_KEYS)->and($retitled->json('title'))->toBe('Where shall we meet?');

    $resolved = $console->post("/api/v1/admin/discussions/{$id}/resolve")->assertOk();
    expect(keysOf($resolved->json()))->toBe(DISCUSSION_KEYS)->and($resolved->json('state'))->toBe('resolved')
        ->and($resolved->json('resolved_at'))->toBe('2026-10-01T12:30:00Z')->and($resolved->json('resolved_by.display_name'))->toBe('Gina Guardian');

    $console->post("/api/v1/admin/discussions/{$id}/reopen")->assertOk()->assertJson(['state' => 'open', 'resolved_at' => null, 'resolved_by' => null]);

    $gone = $console->delete("/api/v1/admin/discussions/{$id}/messages/{$replyId}")->assertOk();
    expect(keysOf($gone->json()))->toBe(TOMBSTONE_KEYS)->and($gone->json('removed'))->toBeTrue()->and($gone->json('removed_at'))->toBe('2026-10-01T12:30:00Z');

    $show = $console->get("/api/v1/admin/discussions/{$id}")->assertOk();
    expect(keysOf($show->json()))->toBe(DISCUSSION_KEYS)->and($show->json('message_count'))->toBe(2);

    $list = $console->get('/api/v1/admin/discussions')->assertOk();
    expect(keysOf($list->json()))->toBe(['data', 'meta'])->and(keysOf($list->json('meta')))->toBe(['page', 'per_page', 'total', 'last_page'])
        ->and(keysOf(Api::rows($list->json('data'))[0]))->toBe(DISCUSSION_KEYS)->and(Api::map($list->json('meta'))['total'])->toBe(1);
});

it('never lets the removed text reach any response, and gives a tombstone no body key at all', function () {
    [$console, $kai] = twoGuardians();
    $id = Api::string($console->post('/api/v1/admin/discussions', ['title' => 'Topic', 'body' => 'Opening'])->json('id'));
    $replyId = Api::string($console->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'WITHDRAWN-PHRASE-4412'])->json('id'));
    $console->delete("/api/v1/admin/discussions/{$id}/messages/{$replyId}")->assertOk();

    $responses = [
        $console->get("/api/v1/admin/discussions/{$id}/messages"),
        $console->get("/api/v1/admin/discussions/{$id}"),
        $console->get('/api/v1/admin/discussions'),
        $console->get('/api/v1/admin/discussions?q=withdrawn'),
        $console->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => 'Trying to resurrect it']),   // 409
        $console->delete("/api/v1/admin/discussions/{$id}/messages/{$replyId}"),                                          // harmless repeat
        $kai->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => 'Not mine']),                        // 403
        $kai->delete("/api/v1/admin/discussions/{$id}/messages/{$replyId}"),                                               // 403
    ];
    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('WITHDRAWN-PHRASE')->and($response->getContent())->not->toContain('resurrect');
    }

    $tombstone = Api::rows($responses[0]->json('data'))[1];
    expect(array_key_exists('body', $tombstone))->toBeFalse()->and(keysOf($tombstone))->toBe(TOMBSTONE_KEYS)
        ->and(array_key_exists('edited_at', $tombstone))->toBeFalse();
});

it('answers with stable codes, in the documented order', function () {
    [$gina, $kai] = twoGuardians();
    $id = Api::string($gina->post('/api/v1/admin/discussions', ['title' => 'Topic', 'body' => 'Opening'])->json('id'));
    $replyId = Api::string($gina->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Gina replies'])->json('id'));
    $ghost = strtolower((string) Str::ulid());

    // 404: a well-formed id that names nothing, with its own code per resource.
    $gina->get("/api/v1/admin/discussions/{$ghost}")->assertNotFound()->assertJson(['code' => 'discussion_not_found']);
    $gina->get("/api/v1/admin/discussions/{$ghost}/messages")->assertNotFound()->assertJson(['code' => 'discussion_not_found']);
    $gina->post("/api/v1/admin/discussions/{$ghost}/messages", ['body' => 'x'])->assertNotFound()->assertJson(['code' => 'discussion_not_found']);
    $gina->patch("/api/v1/admin/discussions/{$ghost}", ['title' => 'x'])->assertNotFound()->assertJson(['code' => 'discussion_not_found']);
    $gina->post("/api/v1/admin/discussions/{$ghost}/resolve")->assertNotFound()->assertJson(['code' => 'discussion_not_found']);
    $gina->post("/api/v1/admin/discussions/{$ghost}/reopen")->assertNotFound()->assertJson(['code' => 'discussion_not_found']);
    $gina->patch("/api/v1/admin/discussions/{$id}/messages/{$ghost}", ['body' => 'x'])->assertNotFound()->assertJson(['code' => 'message_not_found']);
    $gina->delete("/api/v1/admin/discussions/{$id}/messages/{$ghost}")->assertNotFound()->assertJson(['code' => 'message_not_found']);

    // 403 not_author comes AFTER existence and BEFORE the text is judged, and is coded (unlike the capability refusal).
    $kai->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => "bad\x07"])->assertForbidden()->assertJson(['code' => 'not_author']);
    $kai->delete("/api/v1/admin/discussions/{$id}/messages/{$replyId}")->assertForbidden()->assertJson(['code' => 'not_author']);
    $kai->patch("/api/v1/admin/discussions/{$id}", ['title' => 'Kai renames it'])->assertForbidden()->assertJson(['code' => 'not_author']);

    // 422: the domain's rule, with the field and a code; ordinary shape validation has no code.
    $bad = $gina->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => "bad\x07"])->assertUnprocessable();
    expect($bad->json('code'))->toBe('invalid_discussion_input')->and(array_keys(Api::map($bad->json('errors'))))->toBe(['body']);
    $gina->post('/api/v1/admin/discussions', ['title' => "two\nlines", 'body' => 'x'])->assertUnprocessable()->assertJson(['code' => 'invalid_discussion_input'])->assertJsonPath('errors.title.0', 'A title is a single line and may not contain control characters.');
    $shape = $gina->post('/api/v1/admin/discussions', ['title' => 'No body'])->assertUnprocessable();
    expect($shape->json('code'))->toBeNull()->and(array_keys(Api::map($shape->json('errors'))))->toBe(['body']);
    $gina->post("/api/v1/admin/discussions/{$id}/messages", ['body' => '   '])->assertUnprocessable(); // blank is refused

    // 409: a resolved discussion takes no replies; a removed message takes no edits. The order is existence, state, then text.
    $gina->post("/api/v1/admin/discussions/{$id}/resolve")->assertOk();
    $gina->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Too late'])->assertConflict()->assertJson(['code' => 'discussion_resolved']);
    $gina->post("/api/v1/admin/discussions/{$id}/messages", ['body' => "bad\x07"])->assertConflict()->assertJson(['code' => 'discussion_resolved']);
    $gina->delete("/api/v1/admin/discussions/{$id}/messages/{$replyId}")->assertOk();
    $gina->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => "bad\x07"])->assertConflict()->assertJson(['code' => 'message_removed']);

    // A malformed id never reaches a controller.
    $gina->get('/api/v1/admin/discussions/not-an-id')->assertNotFound();
    expect(DB::table('discussion_messages')->count())->toBe(2);
});

it('takes the author from the session and ignores any author, editor or resolver a request names', function () {
    [$gina, $kai, $ginaPerson, $kaiPerson] = twoGuardians();
    $forged = ['author_person_id' => $kaiPerson, 'author' => ['id' => $kaiPerson], 'edited_by_person_id' => $kaiPerson, 'person_id' => $kaiPerson, 'resolved_by_person_id' => $kaiPerson, 'created_at' => '2001-01-01T00:00:00Z', 'sequence' => 99];

    $started = $gina->post('/api/v1/admin/discussions', ['title' => 'Forgery', 'body' => 'Opening', ...$forged])->assertCreated();
    $id = Api::string($started->json('id'));
    $reply = $gina->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Reply', ...$forged])->assertCreated();
    $edited = $gina->patch("/api/v1/admin/discussions/{$id}/messages/".Api::string($reply->json('id')), ['body' => 'Edited', ...$forged])->assertOk();
    $resolved = $gina->post("/api/v1/admin/discussions/{$id}/resolve", $forged)->assertOk();

    expect($started->json('creator.id'))->toBe($ginaPerson)
        ->and($reply->json('author.id'))->toBe($ginaPerson)->and($reply->json('sequence'))->toBe(2)->and($reply->json('created_at'))->toBe(Carbon::now()->toIso8601ZuluString()) // the server's clock, not the forged 2001
        ->and($edited->json('author.id'))->toBe($ginaPerson)->and($edited->json('edited_by.id'))->toBe($ginaPerson)
        ->and($resolved->json('resolved_by.id'))->toBe($ginaPerson)
        ->and(DB::table('discussion_messages')->where('author_person_id', $kaiPerson)->count())->toBe(0);
});

it('exposes a Person\'s id and display name and nothing of any Account, at every depth of every response', function () {
    [$gina, $kai] = twoGuardians();
    $accounts = DB::table('accounts')->get(['id', 'email', 'person_id']);
    expect($accounts)->toHaveCount(2);

    $id = Api::string($gina->post('/api/v1/admin/discussions', ['title' => 'Disclosure', 'body' => 'Opening'])->json('id'));
    $replyId = Api::string($kai->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Kai replies'])->json('id'));
    $kai->patch("/api/v1/admin/discussions/{$id}/messages/{$replyId}", ['body' => 'Kai edits'])->assertOk();
    $gina->post("/api/v1/admin/discussions/{$id}/resolve")->assertOk();

    $bodies = [];
    foreach ([
        $gina->get('/api/v1/admin/discussions'), $gina->get("/api/v1/admin/discussions/{$id}"), $gina->get("/api/v1/admin/discussions/{$id}/messages"),
        $kai->get('/api/v1/admin/discussions?state=resolved'), $kai->patch("/api/v1/admin/discussions/{$id}/messages/".Api::string(DB::table('discussion_messages')->where('sequence', 1)->value('id')), ['body' => 'x']),
        $kai->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'late']),
    ] as $response) {
        $bodies[] = Api::string($response->getContent());
        // Every object that names a Person carries exactly an id and a display name.
        $walk = function (mixed $node) use (&$walk): void {
            if (is_array($node)) {
                foreach (['author', 'creator', 'edited_by', 'resolved_by'] as $key) {
                    if (isset($node[$key])) {
                        expect(array_keys(Api::map($node[$key])))->toBe(PERSON_KEYS);
                    }
                }
                array_map($walk, $node);
            }
        };
        $walk($response->json());
    }

    foreach ($accounts as $account) {
        foreach ($bodies as $body) {
            expect($body)->not->toContain(Api::string($account->email))->and($body)->not->toContain(Api::string($account->id));
        }
    }
    foreach ($bodies as $body) {
        foreach (['email', 'password', 'role', 'capabilit', 'mfa', 'totp', 'membership', 'account', 'security'] as $forbidden) {
            expect(strtolower($body))->not->toContain($forbidden);
        }
    }
});

it('lists, filters, searches and pages over HTTP, and refuses a malformed query', function () {
    [$console] = Discussions::signedInGuardian();
    foreach (['Alpha plan', 'Beta plan', 'Gamma'] as $i => $title) {
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00')->addMinutes($i));
        $id = Api::string($console->post('/api/v1/admin/discussions', ['title' => $title, 'body' => 'x'])->json('id'));
        if ($title === 'Beta plan') {
            $console->post("/api/v1/admin/discussions/{$id}/resolve")->assertOk();
        }
    }
    $titles = fn (string $query) => array_map(fn ($d) => Api::map($d)['title'], Api::rows($console->get("/api/v1/admin/discussions{$query}")->assertOk()->json('data')));

    expect($titles(''))->toBe(['Gamma', 'Beta plan', 'Alpha plan'])
        ->and($titles('?state=open'))->toBe(['Gamma', 'Alpha plan'])
        ->and($titles('?state=resolved'))->toBe(['Beta plan'])
        ->and($titles('?q=PLAN'))->toBe(['Beta plan', 'Alpha plan'])
        ->and($titles('?q=plan&state=open'))->toBe(['Alpha plan'])
        ->and($titles('?per_page=1&page=2'))->toBe(['Beta plan'])
        ->and($console->get('/api/v1/admin/discussions?per_page=1')->json('meta'))->toBe(['page' => 1, 'per_page' => 1, 'total' => 3, 'last_page' => 3]);

    foreach (['?state=archived', '?per_page=101', '?per_page=0', '?page=0', '?page=x'] as $bad) {
        $console->get("/api/v1/admin/discussions{$bad}")->assertUnprocessable();
    }
});

it('refuses someone who is not signed in, on every route, without saying whether anything exists', function () {
    $guest = new Console;
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $id = $started->discussion->id->value;
    $message = Api::string(DB::table('discussion_messages')->value('id'));

    foreach ([
        ['GET', '/api/v1/admin/discussions'], ['POST', '/api/v1/admin/discussions'], ['GET', "/api/v1/admin/discussions/{$id}"], ['PATCH', "/api/v1/admin/discussions/{$id}"],
        ['POST', "/api/v1/admin/discussions/{$id}/resolve"], ['POST', "/api/v1/admin/discussions/{$id}/reopen"],
        ['GET', "/api/v1/admin/discussions/{$id}/messages"], ['POST', "/api/v1/admin/discussions/{$id}/messages"],
        ['PATCH', "/api/v1/admin/discussions/{$id}/messages/{$message}"], ['DELETE', "/api/v1/admin/discussions/{$id}/messages/{$message}"],
    ] as [$verb, $path]) {
        $response = match ($verb) {
            default => throw new InvalidArgumentException($verb),
            'GET' => $guest->get($path), 'POST' => $guest->post($path, ['title' => 'x', 'body' => 'x']), 'PATCH' => $guest->patch($path, ['title' => 'x', 'body' => 'x']), 'DELETE' => $guest->delete($path),
        };
        expect($response->status())->toBe(401, "{$verb} {$path}");
    }
});

it('serves responses that match the documented schemas, property for property', function () {
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    $schemas = Api::map(Api::map(Api::map($spec)['components'])['schemas']);
    $properties = fn (string $name): array => array_keys(Api::map(Api::map($schemas[$name])['properties']));
    $required = fn (string $name): array => Api::strings(Api::map($schemas[$name])['required']);

    [$console] = Discussions::signedInGuardian();
    $id = Api::string($console->post('/api/v1/admin/discussions', ['title' => 'Topic', 'body' => 'Opening'])->json('id'));
    $reply = $console->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Reply'])->assertCreated();
    $edited = $console->patch("/api/v1/admin/discussions/{$id}/messages/".Api::string($reply->json('id')), ['body' => 'Edited'])->assertOk();
    $tombstone = $console->delete("/api/v1/admin/discussions/{$id}/messages/".Api::string($reply->json('id')))->assertOk();
    $resolved = $console->post("/api/v1/admin/discussions/{$id}/resolve")->assertOk();

    expect(keysOf($resolved->json()))->toEqualCanonicalizing($properties('Discussion'))->and($required('Discussion'))->toEqualCanonicalizing($properties('Discussion'))
        ->and(keysOf($edited->json()))->toEqualCanonicalizing($properties('LiveMessage'))->and($required('LiveMessage'))->toEqualCanonicalizing($properties('LiveMessage'))
        ->and(keysOf($tombstone->json()))->toEqualCanonicalizing($properties('RemovedMessage'))->and($required('RemovedMessage'))->toEqualCanonicalizing($properties('RemovedMessage'))
        ->and(keysOf($resolved->json('creator')))->toEqualCanonicalizing($properties('DiscussionPerson'))
        ->and(keysOf($console->get('/api/v1/admin/discussions')->json()))->toEqualCanonicalizing($properties('DiscussionPage'))
        ->and(keysOf($console->get("/api/v1/admin/discussions/{$id}/messages")->json('meta')))->toEqualCanonicalizing(array_keys(Api::map(Api::map(Api::map(Api::map($schemas['MessagePage'])['properties'])['meta'])['properties'])));
});

it('lets a Member (no Console access) see nothing of any of it', function () {
    $dee = Discussions::participant();
    Discussions::start($dee, 'Guardians only');
    [$member] = Mfa::signedInMember();

    foreach (['/api/v1/admin/discussions', '/api/v1/admin/discussions/'.strtolower((string) Str::ulid())] as $path) {
        $response = $member->get($path);
        expect($response->status())->toBe(403)->and($response->getContent())->not->toContain('Guardians only');
    }
});
