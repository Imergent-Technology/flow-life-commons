<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Resources;
use Tests\Support\Totp;

use function Pest\Laravel\postJson;

/*
 * The Resources API as a browser sees it (ADR 0037): the whole Phase 1 workflow over HTTP, the exact response shapes, the coded
 * refusals, the one answer every delivery refusal gives, rich content surviving the request pipeline byte for byte, and permanent
 * deletion behind recent verification. Runs on MariaDB and PostgreSQL.
 */

const MANAGE = '/api/v1/admin/resources';
const LIBRARY = '/api/v1/admin/resource-library';

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function body(TestResponse $response): array
{
    $json = $response->json();
    assert(is_array($json));

    /** @var array<string, mixed> $json */
    return $json;
}

/** @return array<string, mixed> */
function ofKey(mixed $value): array
{
    assert(is_array($value));

    /** @var array<string, mixed> $value */
    return $value;
}

/** Creates a Category over HTTP and returns its id. */
function apiCategory(Console $console, string $name = 'Guides'): string
{
    return Api::string(body($console->post(MANAGE.'/categories', ['name' => $name])->assertCreated())['id']);
}

/**
 * Creates a Draft Pack over HTTP and returns its management body.
 *
 * @return array<string, mixed>
 */
function apiPack(Console $console, string $title = 'A pack', ?string $category = null): array
{
    return body($console->post(MANAGE.'/packs', ['title' => $title, 'category_id' => $category])->assertCreated());
}

/**
 * Creates and publishes a basic Card over HTTP and returns its body.
 *
 * @return array<string, mixed>
 */
function apiLiveCard(Console $console, string $pack, string $title = 'A card', string $text = 'Some words'): array
{
    $card = body($console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => $title, 'content' => Resources::doc($text)])->assertCreated());
    $cardId = Api::string($card['id']);

    return body($console->post(MANAGE."/packs/{$pack}/cards/".Api::string($cardId).'/publish')->assertOk());
}

/**
 * A Published Pack over HTTP, in a fresh Category, for the given audiences.
 *
 * @param  list<string>  $audiences
 */
function apiPublished(Console $console, string $title = 'Deck', int $cards = 1, array $audiences = ['guardian']): string
{
    $pack = Api::string(apiPack($console, $title, apiCategory($console, "Category of {$title}"))['id']);
    for ($i = 1; $i <= $cards; $i++) {
        apiLiveCard($console, $pack, "{$title} card {$i}", "Words of {$title} card {$i}");
    }
    $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => $audiences])->assertOk();
    $console->post(MANAGE."/packs/{$pack}/publish")->assertOk();

    return $pack;
}

it('runs the whole Phase 1 workflow over HTTP: a Category, a Pack, Cards, audiences, publication, the library and a delivered Pack', function () {
    [$console] = Resources::signedInGuardian();

    $category = apiCategory($console, 'Training guides');
    $pack = apiPack($console, 'Opening the doors', $category);
    $packId = Api::string($pack['id']);
    $basic = body($console->post(MANAGE."/packs/{$packId}/cards", ['type' => 'basic', 'title' => 'Checklist', 'content' => Resources::doc('Unlock the front door.', 'Switch on the lights.')])->assertCreated());
    $basicId = Api::string($basic['id']);
    $link = body($console->post(MANAGE."/packs/{$packId}/cards", ['type' => 'external_link', 'title' => 'Venue map', 'uri' => 'https://example.org/map'])->assertCreated());
    $linkId = Api::string($link['id']);
    $console->post(MANAGE."/packs/{$packId}/cards/{$basicId}/publish")->assertOk();
    $console->post(MANAGE."/packs/{$packId}/cards/{$linkId}/publish")->assertOk();
    $console->put(MANAGE."/packs/{$packId}/audiences", ['audiences' => ['guardian', 'member']])->assertOk();
    $published = body($console->post(MANAGE."/packs/{$packId}/publish")->assertOk());

    expect($published['state'])->toBe('published')->and($published['card_count'])->toBe(2)->and($published['published_card_count'])->toBe(2);

    $library = body($console->get(LIBRARY)->assertOk());
    expect($library['data'])->toHaveCount(1)
        ->and(Api::rows($library['data'])[0]['category'])->toBe(['id' => $category, 'name' => 'Training guides'])
        ->and(Api::rows(Api::rows($library['data'])[0]['packs'])[0]['title'])->toBe('Opening the doors')
        ->and(Api::rows(Api::rows($library['data'])[0]['packs'])[0]['card_count'])->toBe(2);

    $delivered = body($console->get(LIBRARY."/packs/{$packId}")->assertOk());
    expect($delivered['card_count'])->toBe(2)
        ->and(array_column(Api::rows($delivered['cards']), 'title'))->toBe(['Checklist', 'Venue map'])
        ->and(array_column(Api::rows($delivered['cards']), 'index'))->toBe([1, 2])
        ->and(Api::rows($delivered['cards'])[0]['summary'])->toBe('Unlock the front door. Switch on the lights.')
        ->and(Api::rows($delivered['cards'])[1]['uri'])->toBe('https://example.org/map');
});

it('gives each response exactly its documented keys, management and delivery differing deliberately', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPublished($console, 'Deck', 2);
    $category = Api::rows(body($console->get(MANAGE.'/categories')->assertOk())['data'])[0];
    $managed = body($console->get(MANAGE."/packs/{$pack}")->assertOk());
    $card = body($console->get(MANAGE."/packs/{$pack}/cards/".Api::string(Api::rows($managed['cards'])[0]['id']))->assertOk());
    $cardId = Api::string($card['id']);
    $list = Api::rows(body($console->get(MANAGE.'/packs')->assertOk())['data'])[0];

    expect(array_keys(ofKey($category)))->toBe(['id', 'name', 'position', 'pack_count', 'created_by', 'updated_by', 'created_at', 'updated_at'])
        ->and(array_keys($managed))->toBe(['id', 'title', 'summary', 'is_series', 'category', 'position', 'state', 'revision', 'audiences', 'card_count', 'published_card_count', 'created_by', 'updated_by', 'created_at', 'updated_at', 'cards'])
        ->and(array_keys(ofKey($list)))->not->toContain('cards') // a list carries counts, not the Cards
        ->and(array_keys(Api::rows($managed['cards'])[0]))->toBe(['id', 'pack_id', 'position', 'type', 'title', 'summary_mode', 'summary', 'uri', 'audience_mode', 'audiences', 'state', 'revision', 'created_by', 'updated_by', 'created_at', 'updated_at'])
        ->and(array_keys($card))->toBe(['id', 'pack_id', 'position', 'type', 'title', 'summary_mode', 'summary', 'uri', 'audience_mode', 'audiences', 'state', 'revision', 'created_by', 'updated_by', 'created_at', 'updated_at', 'content'])
        ->and(array_keys(ofKey($card['content'])))->toBe(['format', 'version', 'document'])
        ->and(array_keys(ofKey($managed['created_by'])))->toBe(['id', 'display_name'])
        ->and(array_keys(body($console->get(LIBRARY."/packs/{$pack}")->assertOk())))->toBe(['id', 'title', 'summary', 'is_series', 'category', 'card_count', 'cards'])
        ->and(array_keys(body($console->get(MANAGE."/packs/{$pack}/preview?audience=member")->assertOk())))->toBe(['audience', 'pack_state', 'audience_targeted', 'visible', 'pack']);
});

it('puts no Account, login, role, capability, MFA or Membership data in any Resources response, at any depth', function () {
    [$console, $account] = Resources::signedInGuardian('gina.guardian@example.org', 'Gina Guardian');
    $pack = apiPublished($console, 'Deck', 1);
    $managed = body($console->get(MANAGE."/packs/{$pack}")->assertOk());
    $responses = [
        $console->get(MANAGE.'/categories'), $console->get(MANAGE.'/packs'), $console->get(MANAGE."/packs/{$pack}"),
        $console->get(MANAGE."/packs/{$pack}/cards/".Api::string(Api::rows($managed['cards'])[0]['id'])),
        $console->get(MANAGE."/packs/{$pack}/preview?audience=guardian"), $console->get(LIBRARY), $console->get(LIBRARY."/packs/{$pack}"),
    ];

    /** @var Closure(mixed): list<string> $keys */
    $keys = function (mixed $value) use (&$keys): array {
        $found = [];
        foreach (is_array($value) ? $value : [] as $key => $inner) {
            $found[] = (string) $key;
            array_push($found, ...$keys($inner));
        }

        return $found;
    };
    foreach ($responses as $response) {
        $text = $response->assertOk()->getContent();
        assert(is_string($text));
        foreach (['gina.guardian@example.org', $account->id->value, 'account', 'email', 'role', 'capabilit', 'mfa', 'membership', 'password', 'token', 'session', 'security'] as $forbidden) {
            expect(strtolower($text))->not->toContain($forbidden);
        }
        expect(array_intersect($keys($response->json()), ['account_id', 'email', 'roles', 'capabilities', 'status']))->toBe([]);
    }
});

it('names a Person by id and current display name only, so a rename shows on what they wrote', function () {
    [$console, $account] = Resources::signedInGuardian('gina.guardian@example.org', 'Gina Guardian');
    $pack = apiPack($console, 'Authored');
    $packId = Api::string($pack['id']);

    expect($pack['created_by'])->toBe(['id' => $account->personId->value, 'display_name' => 'Gina Guardian']);
    DB::table('people')->where('id', $account->personId->value)->update(['display_name' => 'Gina Renamed']);

    expect(ofKey(body($console->get(MANAGE."/packs/{$packId}")->assertOk())['created_by'])['display_name'])->toBe('Gina Renamed');
});

it('takes the creator and editor only from the session: a forged field in the body changes nothing', function () {
    [$console, $account] = Resources::signedInGuardian();
    $other = Resources::editor('other.editor@example.org', 'Other Editor');

    $pack = body($console->post(MANAGE.'/packs', [
        'title' => 'Forged?', 'created_by' => $other->personId->value, 'updated_by' => $other->personId->value, 'created_by_person_id' => $other->personId->value,
        'state' => 'published', 'revision' => 40, 'audiences' => ['member'],
    ])->assertCreated());

    expect(ofKey($pack['created_by'])['id'])->toBe($account->personId->value)
        ->and($pack['state'])->toBe('draft')->and($pack['revision'])->toBe(1)->and($pack['audiences'])->toBe([]);
});

it('keeps whitespace inside rich content exactly as sent through the request pipeline', function () {
    [$console] = Resources::signedInGuardian();
    $pack = Api::string(apiPack($console)['id']);
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' again  with  double  spaces  '],
    ]]]];

    $card = body($console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Spaces', 'content' => $document])->assertCreated());
    $cardId = Api::string($card['id']);
    $read = body($console->get(MANAGE."/packs/{$pack}/cards/{$cardId}")->assertOk());

    expect(Api::rows(Api::map(Api::map($read['content'])['document'])['content'])[0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Hello '],
        ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' again  with  double  spaces  '],
    ]);
});

it('treats an empty string as an empty string, not as a missing value, and the domain judges it', function () {
    [$console] = Resources::signedInGuardian();
    $pack = Api::string(apiPack($console)['id']);

    // An empty address on an external link is invalid_uri, not "absent".
    $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'external_link', 'title' => 'Link', 'uri' => ''])->assertStatus(422)->assertJson(['code' => 'invalid_uri']);
    // A blank summary on a Pack is simply none; a blank title is refused with the domain's message.
    expect(body($console->post(MANAGE.'/packs', ['title' => 'Fine', 'summary' => ''])->assertCreated())['summary'])->toBeNull();
    $console->post(MANAGE.'/packs', ['title' => '   '])->assertStatus(422);
});

it('does not hand Resources\' whitespace exemption to any other route', function () {
    // The exemption is scoped to the Resources management routes: elsewhere Laravel still trims and nulls empty strings.
    [$console] = Resources::signedInGuardian();
    $created = body($console->post('/api/v1/admin/discussions', ['title' => '  Padded title  ', 'body' => 'Opening words'])->assertCreated());

    expect($created['title'])->toBe('Padded title');
});

// --- Blank scalars: the whitespace exemption lets "" reach the request, so the request decides what it means --------------------

it('treats a blank Category id as "no Category" when creating or editing a Pack, and never fails', function () {
    [$console] = Resources::signedInGuardian();
    $category = apiCategory($console);

    // Creating: "" and whitespace-only both mean none, as an absent field does.
    foreach (['', '   '] as $blank) {
        expect(body($console->post(MANAGE.'/packs', ['title' => 'No category', 'category_id' => $blank])->assertCreated())['category'])->toBeNull();
    }

    // Editing: "" clears the Category of a Draft, exactly as null does.
    $draft = Api::string(apiPack($console, 'In a category', $category)['id']);
    $cleared = body($console->patch(MANAGE."/packs/{$draft}", ['revision' => 1, 'category_id' => ''])->assertOk());
    expect($cleared['category'])->toBeNull()->and($cleared['revision'])->toBe(2);

    // ...and is refused for a Published Pack exactly as null is, because clearing is what it means.
    $live = apiPublished($console, 'Live');
    $console->patch(MANAGE."/packs/{$live}", ['revision' => 1, 'category_id' => '  '])
        ->assertStatus(409)->assertJson(['code' => 'published_pack_requirement', 'requirement' => 'category']);
});

it('treats blank management filters and paging as absent: the list answers normally', function () {
    [$console] = Resources::signedInGuardian();
    apiPack($console, 'One', apiCategory($console));
    apiPack($console, 'Two');

    foreach (['?category=&audience=&state=&card_type=&q=', '?category=%20&audience=%20&state=%20&card_type=%20&q=%20', '?page=&per_page='] as $query) {
        $page = body($console->get(MANAGE.'/packs'.$query)->assertOk());
        expect($page['meta'])->toBe(['page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1], $query)->and($page['data'])->toHaveCount(2);
    }
});

it('answers 422, never 500, for a blank value where one is required', function () {
    [$console] = Resources::signedInGuardian();
    $category = apiCategory($console);
    $pack = Api::string(apiPack($console, 'Pack', $category)['id']);
    $card = Api::string(body($console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Card', 'content' => Resources::doc('x')])->assertCreated())['id']);

    foreach (['', '   '] as $blank) {
        $console->post(MANAGE.'/packs', ['title' => $blank])->assertStatus(422);
        $console->post(MANAGE.'/categories', ['name' => $blank])->assertStatus(422);
        $console->patch(MANAGE."/categories/{$category}", ['name' => $blank])->assertStatus(422);
        $console->post(MANAGE."/packs/{$pack}/cards", ['type' => $blank, 'title' => 'x'])->assertStatus(422);
        $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => $blank])->assertStatus(422);
        $console->patch(MANAGE."/packs/{$pack}", ['revision' => $blank, 'title' => 'x'])->assertStatus(422);
        $console->patch(MANAGE."/packs/{$pack}", ['revision' => 1, 'title' => $blank])->assertStatus(422)->assertJson(['code' => 'invalid_resource_input']);
        $console->patch(MANAGE."/packs/{$pack}/cards/{$card}", ['revision' => 1, 'title' => $blank])->assertStatus(422)->assertJson(['code' => 'invalid_resource_input']);
        $console->get(MANAGE."/packs/{$pack}/preview?audience=".rawurlencode($blank))->assertStatus(422);
        // Whole-list bodies: a blank is not a list.
        $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => $blank])->assertStatus(422);
        $console->put(MANAGE."/packs/{$pack}/cards/{$card}/audiences", ['mode' => $blank])->assertStatus(422);
        $console->put(MANAGE."/packs/{$pack}/cards/{$card}/audiences", ['mode' => 'narrowed', 'audiences' => $blank])->assertStatus(422);
        $console->put(MANAGE.'/categories/order', ['ids' => $blank])->assertStatus(422);
        $console->put(MANAGE."/categories/{$category}/pack-order", ['ids' => $blank])->assertStatus(422);
        $console->put(MANAGE."/packs/{$pack}/card-order", ['ids' => $blank])->assertStatus(422);
    }

    // Nothing was changed by any of them.
    expect(DB::table('resource_packs')->where('id', $pack)->value('title'))->toBe('Pack')
        ->and(DB::table('resource_categories')->where('id', $category)->value('name'))->toBe('Guides');
});

it('clears an address with a blank string and still keeps the whitespace of the document sent beside it', function () {
    [$console] = Resources::signedInGuardian();
    $pack = Api::string(apiPack($console)['id']);
    $card = body($console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Card', 'uri' => 'https://example.org/related', 'content' => Resources::doc('x')])->assertCreated());
    $cardId = Api::string($card['id']);
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => '  padded  '],
    ]]]];

    $edited = body($console->patch(MANAGE."/packs/{$pack}/cards/{$cardId}", ['revision' => 1, 'uri' => '', 'content' => $document])->assertOk());

    expect($edited['uri'])->toBeNull()
        ->and(Api::rows(Api::map(Api::map($edited['content'])['document'])['content'])[0]['content'])->toBe([
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => '  padded  '],
        ]);
});

it('exempts exactly /api/v1/admin/resources and below from trimming and empty-to-null, and nothing that merely looks like it', function () {
    $probe = static fn (Request $request) => response()->json(['v' => $request->input('v'), 't' => $request->input('t')]);
    Route::post('api/v1/admin/resources/probe', $probe);
    Route::post('api/v1/admin/resources-probe', $probe);
    Route::post('api/v1/admin/resource-library/probe', $probe);
    Route::post('api/v1/admin/people-probe', $probe);
    $sent = ['v' => '', 't' => '  padded  '];

    // The exemption applies where it is meant to (a positive control, so the others prove something)...
    expect(postJson('api/v1/admin/resources/probe', $sent)->json())->toBe(['v' => '', 't' => '  padded  ']);
    // ...and nowhere else: a lookalike prefix, the delivery routes and any other route still trim and null.
    foreach (['api/v1/admin/resources-probe', 'api/v1/admin/resource-library/probe', 'api/v1/admin/people-probe'] as $uri) {
        expect(postJson($uri, $sent)->json())->toBe(['v' => null, 't' => 'padded'], $uri);
    }
});

it('answers a stale edit 409 stale_revision with the current Pack or Card, and writes nothing', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPack($console, 'Original');
    $packId = Api::string($pack['id']);
    $console->patch(MANAGE."/packs/{$packId}", ['revision' => 1, 'title' => 'First won'])->assertOk();

    $stale = $console->patch(MANAGE."/packs/{$packId}", ['revision' => 1, 'title' => 'Second lost'])->assertStatus(409)->assertJson(['code' => 'stale_revision']);
    expect(ofKey(body($stale)['current'])['title'])->toBe('First won')->and(ofKey(body($stale)['current'])['revision'])->toBe(2)
        ->and(DB::table('resource_packs')->where('id', $packId)->value('title'))->toBe('First won');

    $card = body($console->post(MANAGE."/packs/{$packId}/cards", ['type' => 'basic', 'title' => 'Card', 'content' => Resources::doc('x')])->assertCreated());
    $cardId = Api::string($card['id']);
    $console->patch(MANAGE."/packs/{$packId}/cards/{$cardId}", ['revision' => 1, 'title' => 'Card first'])->assertOk();
    $staleCard = $console->patch(MANAGE."/packs/{$packId}/cards/{$cardId}", ['revision' => 1, 'title' => 'Card second'])->assertStatus(409)->assertJson(['code' => 'stale_revision']);

    expect(ofKey(body($staleCard)['current'])['title'])->toBe('Card first')->and(array_keys(ofKey(body($staleCard)['current'])))->toContain('content');
});

it('requires the revision on every authored edit: there is no last-write-wins', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPack($console);
    $packId = Api::string($pack['id']);
    $card = body($console->post(MANAGE."/packs/{$packId}/cards", ['type' => 'basic', 'title' => 'Card', 'content' => Resources::doc('x')])->assertCreated());
    $cardId = Api::string($card['id']);

    $console->patch(MANAGE."/packs/{$packId}", ['title' => 'No revision'])->assertStatus(422)->assertJsonValidationErrors('revision');
    $console->patch(MANAGE."/packs/{$packId}/cards/{$cardId}", ['title' => 'No revision'])->assertStatus(422)->assertJsonValidationErrors('revision');
    $console->patch(MANAGE."/packs/{$packId}", ['revision' => 0, 'title' => 'Zero'])->assertStatus(422);
});

it('answers the coded 404s, 409s and 422s the contract names', function () {
    [$console] = Resources::signedInGuardian();
    $missing = '01jaaaaaaaaaaaaaaaaaaaaaa0';
    $category = apiCategory($console, 'Guides');
    $pack = apiPack($console, 'Pack', $category);
    $packId = Api::string($pack['id']);
    $card = body($console->post(MANAGE."/packs/{$packId}/cards", ['type' => 'basic', 'title' => 'Card', 'content' => Resources::doc('x')])->assertCreated());
    $cardId = Api::string($card['id']);

    $code = fn (TestResponse $r, int $status): mixed => $r->assertStatus($status)->json('code');

    expect($code($console->patch(MANAGE."/categories/{$missing}", ['name' => 'x']), 404))->toBe('category_not_found')
        ->and($code($console->get(MANAGE."/packs/{$missing}"), 404))->toBe('pack_not_found')
        ->and($code($console->get(MANAGE."/packs/{$packId}/cards/{$missing}"), 404))->toBe('card_not_found')
        ->and($code($console->get(MANAGE."/packs/{$missing}/cards/{$cardId}"), 404))->toBe('pack_not_found')
        ->and($code($console->post(MANAGE.'/categories', ['name' => 'guides']), 409))->toBe('duplicate_category')
        ->and($code($console->delete(MANAGE."/categories/{$category}"), 409))->toBe('category_not_empty')
        ->and($code($console->put(MANAGE.'/categories/order', ['ids' => [$missing]]), 409))->toBe('order_mismatch')
        ->and($code($console->put(MANAGE."/packs/{$packId}/card-order", ['ids' => []]), 409))->toBe('order_mismatch')
        ->and($code($console->put(MANAGE."/categories/{$category}/pack-order", ['ids' => []]), 409))->toBe('order_mismatch')
        ->and($code($console->post(MANAGE."/packs/{$packId}/publish"), 409))->toBe('pack_not_publishable')
        ->and($console->post(MANAGE."/packs/{$packId}/publish")->json('unmet'))->toBe(['audience', 'published_card']);

    // With its Card published the Pack still lacks an audience, and says so.
    $console->post(MANAGE."/packs/{$packId}/cards/{$cardId}/publish")->assertOk();
    expect($code($console->post(MANAGE."/packs/{$packId}/publish"), 409))->toBe('pack_not_publishable')
        ->and($code($console->post(MANAGE.'/packs', ['title' => 'x', 'category_id' => $missing]), 422))->toBe('unknown_category');
});

it('answers card_audience_conflict, published_pack_requirement and card_not_publishable with what is wrong', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPublished($console, 'Live', 1);
    $managed = body($console->get(MANAGE."/packs/{$pack}")->assertOk());
    $onlyCard = Api::string(Api::rows($managed['cards'])[0]['id']);

    $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => []])->assertStatus(409)->assertJson(['code' => 'published_pack_requirement', 'requirement' => 'audience']);
    $console->post(MANAGE."/packs/{$pack}/cards/{$onlyCard}/unpublish")->assertStatus(409)->assertJson(['code' => 'published_pack_requirement', 'requirement' => 'published_card']);
    $console->patch(MANAGE."/packs/{$pack}", ['revision' => 1, 'category_id' => null])->assertStatus(409)->assertJson(['code' => 'published_pack_requirement', 'requirement' => 'category']);
    $console->patch(MANAGE."/packs/{$pack}/cards/{$onlyCard}", ['revision' => 1, 'content' => ['type' => 'doc', 'content' => []]])->assertStatus(409)->assertJson(['code' => 'card_not_publishable', 'unmet' => ['content']]);

    $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => ['guardian', 'member']])->assertOk();
    $console->put(MANAGE."/packs/{$pack}/cards/{$onlyCard}/audiences", ['mode' => 'narrowed', 'audiences' => ['member']])->assertOk();
    $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => ['guardian']])->assertStatus(409)->assertJson(['code' => 'card_audience_conflict', 'cards' => [$onlyCard]]);
    $console->put(MANAGE."/packs/{$pack}/cards/{$onlyCard}/audiences", ['mode' => 'narrowed', 'audiences' => ['guardian', 'member']])->assertOk();
});

it('refuses invalid content with invalid_content naming where, and an unsafe address with invalid_uri, never echoing them', function () {
    [$console] = Resources::signedInGuardian();
    $pack = Api::string(apiPack($console)['id']);

    $bad = $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Bad', 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(document.cookie)']]]]]],
    ]]])->assertStatus(422)->assertJson(['code' => 'invalid_content']);
    expect($bad->json('errors.content.0'))->toContain('content.content[0].content[0].marks[0].attrs.href')
        ->and($bad->getContent())->not->toContain('alert')->and($bad->getContent())->not->toContain('javascript');

    $link = $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'external_link', 'title' => 'Bad', 'uri' => 'javascript:alert(1)'])->assertStatus(422)->assertJson(['code' => 'invalid_uri']);
    expect($link->getContent())->not->toContain('alert');

    $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Html', 'content' => '<p>hi</p>'])->assertStatus(422)->assertJsonValidationErrors('content');
    expect(DB::table('resource_cards')->count())->toBe(0);
});

it('refuses what the closed vocabularies do not contain: no file Type, no volunteer audience, no free-form preview audience', function () {
    [$console] = Resources::signedInGuardian();
    $pack = Api::string(apiPack($console)['id']);

    $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'file', 'title' => 'A file'])->assertStatus(422)->assertJsonValidationErrors('type');
    $console->post(MANAGE."/packs/{$pack}/cards", ['type' => 'youtube', 'title' => 'Video', 'uri' => 'https://youtu.be/x'])->assertStatus(422)->assertJsonValidationErrors('type');
    $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => ['volunteer']])->assertStatus(422)->assertJsonValidationErrors('audiences.0');
    $console->put(MANAGE."/packs/{$pack}/audiences", ['audiences' => ['guardian', 'partner']])->assertStatus(422);
    $console->get(MANAGE."/packs/{$pack}/preview?audience=volunteer")->assertStatus(422)->assertJsonValidationErrors('audience');
    $console->get(MANAGE."/packs/{$pack}/preview")->assertStatus(422)->assertJsonValidationErrors('audience');
    $console->get(MANAGE."/packs/{$pack}/preview?audience=Guardian")->assertStatus(422);
    $console->get(MANAGE.'/packs?audience=everyone')->assertStatus(422);
    $console->get(MANAGE.'/packs?card_type=file')->assertStatus(422);
});

it('gives every delivery refusal the SAME body: missing, Draft, unpublished, not for you and empty are indistinguishable', function () {
    [$console] = Resources::signedInGuardian();
    $draft = Api::string(apiPack($console, 'Draft')['id']);
    apiLiveCard($console, $draft);
    $unpublished = apiPublished($console, 'Unpublished');
    $console->post(MANAGE."/packs/{$unpublished}/unpublish")->assertOk();
    $memberOnly = apiPublished($console, 'Member only', 1, ['member']);
    $narrowedAway = apiPublished($console, 'Narrowed away', 1, ['guardian', 'member']);
    $card = Api::string(Api::rows(body($console->get(MANAGE."/packs/{$narrowedAway}")->assertOk())['cards'])[0]['id']);
    $console->put(MANAGE."/packs/{$narrowedAway}/cards/{$card}/audiences", ['mode' => 'narrowed', 'audiences' => ['member']])->assertOk();

    $bodies = [];
    foreach (['01jaaaaaaaaaaaaaaaaaaaaaa0', $draft, $unpublished, $memberOnly, $narrowedAway] as $id) {
        $response = $console->get(LIBRARY."/packs/{$id}")->assertStatus(404);
        $bodies[] = $response->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) $bodies[0], true))->toBe(['message' => 'There is no such resource.', 'code' => 'resource_pack_not_found']);
});

it('previews a Draft as an audience for a manager, and delivers nothing of it through the library', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPack($console, 'Not live', apiCategory($console, 'Guides'));
    $packId = Api::string($pack['id']);
    apiLiveCard($console, Api::string($packId), 'Card', 'Preview words');
    $console->put(MANAGE."/packs/{$packId}/audiences", ['audiences' => ['member']])->assertOk();

    $preview = body($console->get(MANAGE."/packs/{$packId}/preview?audience=member")->assertOk());
    expect($preview['visible'])->toBeTrue()->and($preview['pack_state'])->toBe('draft')->and($preview['audience_targeted'])->toBeTrue()
        ->and(Api::rows(Api::map($preview['pack'])['cards'])[0]['title'])->toBe('Card');
    $guardian = body($console->get(MANAGE."/packs/{$packId}/preview?audience=guardian")->assertOk());
    expect($guardian['visible'])->toBeFalse()->and($guardian['pack'])->toBeNull()->and($guardian['audience_targeted'])->toBeFalse();

    $console->get(LIBRARY."/packs/{$packId}")->assertNotFound();
    expect(body($console->get(LIBRARY)->assertOk())['data'])->toBe([])
        ->and(DB::table('resource_packs')->where('id', $packId)->value('state'))->toBe('draft');
});

it('searches and filters the library by query string, and validates what it is given', function () {
    [$console] = Resources::signedInGuardian();
    $a = apiPublished($console, 'Opening checklist');
    apiPublished($console, 'Closing checklist');

    expect(array_column(Api::rows(Api::rows(body($console->get(LIBRARY.'?q=opening')->assertOk())['data'])[0]['packs']), 'title'))->toBe(['Opening checklist'])
        ->and(body($console->get(LIBRARY.'?q=nothing')->assertOk())['data'])->toBe([])
        ->and(body($console->get(LIBRARY.'?category=01jaaaaaaaaaaaaaaaaaaaaaa0')->assertOk())['data'])->toBe([])
        ->and($a)->not->toBe('');
    $console->get(LIBRARY.'?category=not-a-ulid')->assertStatus(422);
    $console->get(LIBRARY.'?q='.str_repeat('a', 201))->assertStatus(422);
    // Audience is not a parameter: it is ignored, never a way to see another audience's content.
    apiPublished($console, 'Members only', 1, ['member']);
    expect(array_column(Api::rows(body($console->get(LIBRARY.'?audience=member')->assertOk())['data']), 'category'))->toHaveCount(2);
});

it('pages the management list and reports the totals', function () {
    [$console] = Resources::signedInGuardian();
    foreach (['One', 'Two', 'Three'] as $title) {
        apiPack($console, $title);
    }

    $page = body($console->get(MANAGE.'/packs?per_page=2&page=2')->assertOk());

    expect($page['meta'])->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])->and($page['data'])->toHaveCount(1);
    $console->get(MANAGE.'/packs?per_page=101')->assertStatus(422);
    $console->get(MANAGE.'/packs?page=0')->assertStatus(422);
});

it('reorders over HTTP from the complete list, returning the new order', function () {
    [$console] = Resources::signedInGuardian();
    $category = apiCategory($console, 'Guides');
    $other = apiCategory($console, 'Other');
    $a = Api::string(apiPack($console, 'A', $category)['id']);
    $b = Api::string(apiPack($console, 'B', $category)['id']);

    expect(array_column(Api::rows(body($console->put(MANAGE."/categories/{$category}/pack-order", ['ids' => [$b, $a]])->assertOk())['data']), 'title'))->toBe(['B', 'A'])
        ->and(array_column(Api::rows(body($console->put(MANAGE.'/categories/order', ['ids' => [$other, $category]])->assertOk())['data']), 'name'))->toBe(['Other', 'Guides']);

    $x = body($console->post(MANAGE."/packs/{$a}/cards", ['type' => 'basic', 'title' => 'X', 'content' => Resources::doc('x')])->assertCreated());
    $y = body($console->post(MANAGE."/packs/{$a}/cards", ['type' => 'basic', 'title' => 'Y', 'content' => Resources::doc('y')])->assertCreated());
    $reordered = body($console->put(MANAGE."/packs/{$a}/card-order", ['ids' => [$y['id'], $x['id']]])->assertOk());
    expect(array_column(Api::rows($reordered['cards']), 'title'))->toBe(['Y', 'X']);

    $console->put(MANAGE.'/categories/order', ['ids' => 'not-a-list'])->assertStatus(422);
    $console->put(MANAGE.'/categories/order', ['ids' => ['not-a-ulid']])->assertStatus(422);
    $console->put(MANAGE.'/categories/order', [])->assertStatus(422);
});

it('refuses a request with no session with 401, and never reveals what exists', function () {
    $console = new Console;

    foreach ([MANAGE.'/categories', MANAGE.'/packs', LIBRARY, LIBRARY.'/packs/01jaaaaaaaaaaaaaaaaaaaaaa0'] as $path) {
        $console->get($path)->assertUnauthorized();
    }
});

it('requires recent verification to delete a Pack or a Card permanently, and a stale proof deletes nothing and records nothing', function () {
    [$console, , $factor] = Resources::signedInGuardian();
    $pack = apiPublished($console, 'Live', 2);
    $managed = body($console->get(MANAGE."/packs/{$pack}")->assertOk());
    $card = Api::string(Api::rows($managed['cards'])[0]['id']);
    $events = DB::table('security_events')->count();
    Console::advance(16 * 60); // past the 15 minutes, inside the 30-minute inactivity window
    $console->me()->assertOk();

    foreach ([MANAGE."/packs/{$pack}/cards/{$card}", MANAGE."/packs/{$pack}"] as $path) {
        $refused = $console->delete($path)->assertStatus(403);
        expect($refused->json('verification_required'))->toBeTrue();
    }
    expect(DB::table('resource_packs')->count())->toBe(1)->and(DB::table('resource_cards')->count())->toBe(2)->and(DB::table('security_events')->count())->toBe($events);

    // Routine work still needs none, on the same stale session.
    $console->patch(MANAGE."/packs/{$pack}", ['revision' => 1, 'title' => 'Routine edit'])->assertOk();
    $console->post(MANAGE."/packs/{$pack}/unpublish")->assertOk();

    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret'])])->assertNoContent();
    $console->delete(MANAGE."/packs/{$pack}/cards/{$card}")->assertNoContent();
    $console->delete(MANAGE."/packs/{$pack}")->assertNoContent();

    expect(DB::table('resource_packs')->count())->toBe(0)->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'resource.card_deleted')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'resource.pack_deleted')->count())->toBe(1);
    $console->get(MANAGE."/packs/{$pack}")->assertNotFound();
});

it('asks for the capability before the proof: a caller without resources.manage is refused plainly, never asked to re-verify, and nothing is deleted', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPublished($console, 'Live', 2);
    Console::advance(16 * 60);
    Gate::before(fn ($user, string $ability): ?bool => $ability === 'resources.manage' ? false : null);

    $response = $console->delete(MANAGE."/packs/{$pack}")->assertStatus(403);

    expect($response->json('verification_required'))->toBeNull()
        ->and(DB::table('resource_packs')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'like', 'resource.%')->count())->toBe(0);
});

it('answers 204 with no body for a deletion, and the deleted Pack then 404s everywhere, to managers and viewers alike', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPublished($console, 'Live');

    $deleted = $console->delete(MANAGE."/packs/{$pack}")->assertNoContent();

    expect($deleted->getContent())->toBe('');
    $console->get(MANAGE."/packs/{$pack}")->assertNotFound();
    $console->get(LIBRARY."/packs/{$pack}")->assertNotFound();
    $console->delete(MANAGE."/packs/{$pack}")->assertNotFound();
    $console->get(MANAGE."/packs/{$pack}/preview?audience=guardian")->assertNotFound();
});

it('refuses a deletion of the last Published Card of a Published Pack over HTTP with the requirement named, and records no event', function () {
    [$console] = Resources::signedInGuardian();
    $pack = apiPublished($console, 'Live', 1);
    $card = Api::string(Api::rows(body($console->get(MANAGE."/packs/{$pack}")->assertOk())['cards'])[0]['id']);

    $console->delete(MANAGE."/packs/{$pack}/cards/{$card}")->assertStatus(409)->assertJson(['code' => 'published_pack_requirement', 'requirement' => 'published_card']);

    expect(DB::table('resource_cards')->count())->toBe(1)->and(DB::table('security_events')->where('type', 'like', 'resource.%')->count())->toBe(0);
});

it('deletes an empty Category with no recent verification and no event: it loses a name, not content', function () {
    [$console] = Resources::signedInGuardian();
    $category = apiCategory($console, 'Empty');
    Console::advance(16 * 60);

    $console->delete(MANAGE."/categories/{$category}")->assertNoContent();

    expect(DB::table('resource_categories')->count())->toBe(0)->and(DB::table('security_events')->where('type', 'like', 'resource.%')->count())->toBe(0);
});

it('serves JSON errors with the browser security headers and no stack trace, even for a malformed id', function () {
    [$console] = Resources::signedInGuardian();
    config(['app.debug' => false]); // as production runs (security:production-check refuses anything else); the suite itself runs with debug on

    $response = $console->get(MANAGE.'/packs/not-a-ulid');

    expect($response->status())->toBe(404)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->getContent())->not->toContain('vendor/')->and((string) $response->getContent())->not->toContain('Illuminate');
});
