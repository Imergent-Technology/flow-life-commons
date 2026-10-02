<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Discussions;
use Tests\Support\Mfa;

/*
 * Who may use the Discussions API (ADR 0035), at each layer on its own. The role catalog has no role that holds
 * `discussions.view` without `.participate` (a Guardian holds both, deliberately), so, as in CRM's tests, ONE layer is moved
 * at a time through the platform's own seam, the Laravel Gate the route `can:` middleware asks, keeping the other real.
 * Ownership (editing or removing someone else's words) is a separate rule from capability and is covered in
 * DiscussionsTest and DiscussionsApiTest. Runs on MariaDB and PostgreSQL.
 */

/** Makes the Gate answer `$answer` for these abilities only; every other ability still asks the real Authorizer. */
function discussionGate(bool $answer, string ...$abilities): void
{
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => in_array($ability, $abilities, true) ? $answer : null);
}

/** @return list<array{string, string, array<string, mixed>}> every operation: method, path, a valid body */
function discussionOperations(string $discussion, string $message): array
{
    return [
        ['GET', '/api/v1/admin/discussions', []],
        ['POST', '/api/v1/admin/discussions', ['title' => 'A new one', 'body' => 'Opening']],
        ['GET', "/api/v1/admin/discussions/{$discussion}", []],
        ['PATCH', "/api/v1/admin/discussions/{$discussion}", ['title' => 'Retitled']],
        ['GET', "/api/v1/admin/discussions/{$discussion}/messages", []],
        ['POST', "/api/v1/admin/discussions/{$discussion}/messages", ['body' => 'A reply']],
        ['PATCH', "/api/v1/admin/discussions/{$discussion}/messages/{$message}", ['body' => 'Edited']],
        ['POST', "/api/v1/admin/discussions/{$discussion}/resolve", []],
        ['POST', "/api/v1/admin/discussions/{$discussion}/reopen", []],
        ['DELETE', "/api/v1/admin/discussions/{$discussion}/messages/{$message}", []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function discussionCall(Console $console, string $verb, string $path, array $body): TestResponse
{
    return match ($verb) {
        default => throw new InvalidArgumentException($verb),
        'GET' => $console->get($path),
        'POST' => $console->post($path, $body),
        'PATCH' => $console->patch($path, $body),
        'DELETE' => $console->delete($path),
    };
}

/**
 * A discussion and one reply, both written by the signed-in Guardian's own Person, so every operation is one they own.
 *
 * @return array{string, string}
 */
function ownedDiscussion(string $guardianEmail = 'gina.guardian@example.org'): array
{
    $author = Discussions::participant($guardianEmail);
    $started = Discussions::start($author, 'Existing topic', 'Existing opening');
    $reply = Discussions::reply($author, $started, 'Existing reply');

    return [$started->discussion->id->value, $reply->message->id->value];
}

it('refuses every operation to a signed-in Account without Console access, before the capability is even asked', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started);
    [$member] = Mfa::signedInMember();

    foreach (discussionOperations($started->discussion->id->value, $reply->message->id->value) as [$verb, $path, $body]) {
        $response = discussionCall($member, $verb, $path, $body);
        expect($response->status())->toBe(403, "{$verb} {$path}")->and($response->json('verification_required'))->toBeNull();
    }
    expect(DB::table('discussions')->count())->toBe(1)->and(DB::table('discussion_messages')->count())->toBe(2)
        ->and(DB::table('discussion_messages')->whereNull('body')->count())->toBe(0);
});

it('lets a Guardian, who holds both capabilities, read AND take part', function () {
    [$console] = Discussions::signedInGuardian();

    $id = Api::string($console->post('/api/v1/admin/discussions', ['title' => 'Mine', 'body' => 'Opening'])->assertCreated()->json('id'));
    $console->get('/api/v1/admin/discussions')->assertOk();
    $console->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Reply'])->assertCreated();
    $console->get("/api/v1/admin/discussions/{$id}/messages")->assertOk();
});

it('lets a platform administrator do the same, holding every capability', function () {
    [$console] = Mfa::signedInAdmin();

    $id = Api::string($console->post('/api/v1/admin/discussions', ['title' => 'Admin topic', 'body' => 'Opening'])->assertCreated()->json('id'));
    $console->get("/api/v1/admin/discussions/{$id}")->assertOk();
});

it('asks for no fresh proof: discussion mutations succeed long after sign-in, where an authority-bearing one is refused', function () {
    [$console] = Mfa::signedInAdmin();
    [$id] = [Api::string($console->post('/api/v1/admin/discussions', ['title' => 'Topic', 'body' => 'Opening'])->json('id'))];
    $reply = Api::string($console->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Reply'])->json('id'));
    $console->advanceWhileActive(20 * 60); // beyond the 15-minute step-up window, session still alive

    // Control: the window really has closed. A Membership mutation (it grants entitlement) asks for the proof.
    $stale = $console->post('/api/v1/admin/members', ['display_name' => 'X', 'starts_at' => '2026-10-01T00:00:00Z', 'open_ended' => true, 'ends_at' => null, 'source' => 'operator']);
    expect($stale->status())->toBe(403)->and($stale->json('verification_required'))->toBeTrue();

    // Every discussion mutation does not.
    $console->post('/api/v1/admin/discussions', ['title' => 'Second', 'body' => 'Opening'])->assertCreated();
    $console->post("/api/v1/admin/discussions/{$id}/messages", ['body' => 'Another'])->assertCreated();
    $console->patch("/api/v1/admin/discussions/{$id}/messages/{$reply}", ['body' => 'Edited late'])->assertOk();
    $console->patch("/api/v1/admin/discussions/{$id}", ['title' => 'Retitled late'])->assertOk();
    $console->post("/api/v1/admin/discussions/{$id}/resolve")->assertOk();
    $console->post("/api/v1/admin/discussions/{$id}/reopen")->assertOk();
    $console->delete("/api/v1/admin/discussions/{$id}/messages/{$reply}")->assertOk();

    expect(DB::table('discussions')->where('id', $id)->value('title'))->toBe('Retitled late');
});

it('does not widen the exemption: an operator mutation by the same Account still needs the proof after a discussion mutation', function () {
    [$console] = Mfa::signedInAdmin();
    $console->advanceWhileActive(20 * 60);

    $console->post('/api/v1/admin/discussions', ['title' => 'Topic', 'body' => 'Opening'])->assertCreated();
    $disable = $console->post('/api/v1/admin/accounts/'.strtolower((string) Str::ulid()).'/disable');

    expect($disable->status())->toBe(403)->and($disable->json('verification_required'))->toBeTrue();
});

it('stops a mutation at the HTTP layer alone: participate refused at the route, although the use case would allow it', function () {
    [$console] = Discussions::signedInGuardian();
    [$discussion, $message] = ownedDiscussion();
    discussionGate(false, 'discussions.participate'); // view stays real (granted), participate is refused at the route only

    foreach (discussionOperations($discussion, $message) as [$verb, $path, $body]) {
        $response = discussionCall($console, $verb, $path, $body);
        if ($verb === 'GET') {
            expect($response->status())->toBe(200, "{$verb} {$path}"); // a view-only operator still reads
        } else {
            expect($response->status())->toBe(403, "{$verb} {$path}")->and($response->json('verification_required'))->toBeNull();
        }
    }
    expect(DB::table('discussions')->count())->toBe(1)->and(DB::table('discussion_messages')->count())->toBe(2)
        ->and(DB::table('discussion_messages')->where('id', $message)->value('body'))->toBe('Existing reply')
        ->and(DB::table('discussions')->value('state'))->toBe('open');
});

it('stops a read at the HTTP layer alone, and does not make reading a precondition of writing', function () {
    [$console] = Discussions::signedInGuardian();
    [$discussion, $message] = ownedDiscussion();
    discussionGate(false, 'discussions.view'); // participate stays real, view is refused at the route only

    foreach (discussionOperations($discussion, $message) as [$verb, $path, $body]) {
        $response = discussionCall($console, $verb, $path, $body);
        if ($verb === 'GET') {
            expect($response->status())->toBe(403, "{$verb} {$path}");
        } else {
            // No hidden participate -> view dependency: every mutation completes (the response is what was written, never a re-read).
            expect($response->status())->toBeIn([200, 201], "{$verb} {$path}");
        }
    }
});

it('stops every operation at the Application layer alone: the route lets a signed-in Account through, the use case refuses', function () {
    $dee = Discussions::participant();
    $started = Discussions::start($dee);
    $reply = Discussions::reply($dee, $started);
    [$member] = Mfa::signedInMember('plain@example.org');
    discussionGate(true, 'console.access', 'discussions.view', 'discussions.participate'); // every route-level check passes; the Account holds nothing

    foreach (discussionOperations($started->discussion->id->value, $reply->message->id->value) as [$verb, $path, $body]) {
        $response = discussionCall($member, $verb, $path, $body);
        expect($response->status())->toBe(403, "{$verb} {$path}");
    }
    expect(DB::table('discussions')->count())->toBe(1)->and(DB::table('discussion_messages')->count())->toBe(2)
        ->and(DB::table('discussion_messages')->where('id', $reply->message->id->value)->value('body'))->toBe('A reply')
        ->and(DB::table('discussions')->value('state'))->toBe('open');
});

it('keeps ownership a separate rule from the capability: a route-level grant never lets anyone change another Person\'s words', function () {
    [$console] = Discussions::signedInGuardian();
    $kai = Discussions::participant('kai.participant@example.org', 'Kai Participant');
    $started = Discussions::start($kai, 'Kai\'s topic', 'Kai wrote this');
    $reply = Discussions::reply($kai, $started, 'And Kai wrote this');
    discussionGate(true, 'discussions.view', 'discussions.participate'); // the capability is beyond doubt; ownership is what is tested

    $id = $started->discussion->id->value;
    $console->patch("/api/v1/admin/discussions/{$id}/messages/{$reply->message->id->value}", ['body' => 'Rewritten'])->assertForbidden()->assertJson(['code' => 'not_author']);
    $console->delete("/api/v1/admin/discussions/{$id}/messages/{$reply->message->id->value}")->assertForbidden()->assertJson(['code' => 'not_author']);
    $console->patch("/api/v1/admin/discussions/{$id}", ['title' => 'Stolen'])->assertForbidden()->assertJson(['code' => 'not_author']);

    expect(DB::table('discussion_messages')->where('id', $reply->message->id->value)->value('body'))->toBe('And Kai wrote this')
        ->and(DB::table('discussions')->where('id', $id)->value('title'))->toBe('Kai\'s topic');
});
