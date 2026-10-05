<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;

/*
 * Managed files over HTTP, as the Console will use them (ADR 0037, decisions 63-66; WP3): a File Card created from a multipart form,
 * a file replaced, and a file downloaded under management (Drafts included) or from the library (only what the projection shows).
 * Pinned here: the coded refusals and that none of them is a 500; that the client's claimed type changes nothing; the exact download
 * headers and bytes; one answer for every reason a library file is not the viewer's; and that no response, route or header names
 * where a file is stored. Runs on MariaDB and PostgreSQL.
 */

const FILES_MANAGE = '/api/v1/admin/resources';
const FILES_LIBRARY = '/api/v1/admin/resource-library';

beforeEach(function () {
    ResourceFiles::fake();
});

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function filesBody(TestResponse $response): array
{
    $json = $response->json();
    assert(is_array($json));

    /** @var array<string, mixed> $json */
    return $json;
}

/** @return array<string, mixed> */
function filesRow(mixed $value): array
{
    assert(is_array($value));

    /** @var array<string, mixed> $value */
    return $value;
}

/** A Category and a Draft Pack in it, over HTTP; returns the Pack's id. */
function filesPack(Console $console, string $title = 'Handbook'): string
{
    $category = Api::string(filesBody($console->post(FILES_MANAGE.'/categories', ['name' => "Category for {$title}"])->assertCreated())['id']);

    return Api::string(filesBody($console->post(FILES_MANAGE.'/packs', ['title' => $title, 'category_id' => $category])->assertCreated())['id']);
}

/**
 * Creates a File Card from a multipart form.
 *
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Response>
 */
function filesCreate(Console $console, string $pack, UploadedFile|string|null $file, array $extra = []): TestResponse
{
    $fields = ['type' => 'file', 'title' => 'The guide', ...$extra];
    if ($file !== null) {
        $fields['file'] = $file;
    }

    return $console->multipart(FILES_MANAGE."/packs/{$pack}/cards", $fields);
}

/**
 * A Published File Card in a Published Pack (guardian, unless told otherwise), over HTTP.
 *
 * @param  list<string>  $audiences
 * @return array{string, string} the Pack's and the Card's ids
 */
function filesLive(Console $console, string $title = 'Handbook', string $name = 'Guide.pdf', ?string $bytes = null, array $audiences = ['guardian']): array
{
    $pack = filesPack($console, $title);
    $card = Api::string(filesBody(filesCreate($console, $pack, ResourceFiles::upload($name, $bytes ?? ResourceFiles::pdf()))->assertCreated())['id']);
    $console->post(FILES_MANAGE."/packs/{$pack}/cards/{$card}/publish")->assertOk();
    $console->put(FILES_MANAGE."/packs/{$pack}/audiences", ['audiences' => $audiences])->assertOk();
    $console->post(FILES_MANAGE."/packs/{$pack}/publish")->assertOk();

    return [$pack, $card];
}

/** @param  TestResponse<Response>  $response */
function streamed(TestResponse $response): string
{
    return $response->streamedContent();
}

// --- Creation and replacement -------------------------------------------------------------------------------------------------

it('creates a File Card from a multipart form, ignores the type the browser claimed, and describes the file without saying where it is', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);

    $card = filesBody(filesCreate($console, $pack, ResourceFiles::upload('Opening Guide.pdf', ResourceFiles::pdf(), claimedType: 'text/html'), [
        'content' => json_encode(Resources::doc('Read before opening'), JSON_THROW_ON_ERROR),
    ])->assertCreated());
    $file = filesRow($card['file']);
    $key = Resources::str(DB::table('resource_assets')->value('storage_key'));

    expect($card['type'])->toBe('file')
        ->and($card['state'])->toBe('draft')
        ->and($card['uri'])->toBeNull()
        ->and(filesRow($card['content'])['document'])->toBe(Resources::doc('Read before opening'))
        ->and(array_keys($file))->toBe(['name', 'media_type', 'byte_size', 'sha256', 'uploaded_by', 'uploaded_at', 'available', 'download_path'])
        ->and($file['name'])->toBe('Opening Guide.pdf')
        ->and($file['media_type'])->toBe('application/pdf') // detected; the claimed text/html counted for nothing
        ->and($file['byte_size'])->toBe(strlen(ResourceFiles::pdf()))
        ->and($file['sha256'])->toBe(hash('sha256', ResourceFiles::pdf()))
        ->and($file['available'])->toBeTrue()
        ->and($file['download_path'])->toBe(FILES_MANAGE."/packs/{$pack}/cards/".Api::string($card['id']).'/file');

    $everything = json_encode([$card, filesBody($console->get(FILES_MANAGE."/packs/{$pack}")->assertOk())], JSON_THROW_ON_ERROR);
    expect(str_contains($everything, $key))->toBeFalse()
        ->and(str_contains($everything, 'storage'))->toBeFalse()
        ->and(str_contains($everything, 'private'))->toBeFalse();
});

it('lists a File Card in its Pack with its file\'s name, type and size only', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);
    filesCreate($console, $pack, ResourceFiles::upload('map.png', ResourceFiles::png()))->assertCreated();
    $console->post(FILES_MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'Words', 'content' => Resources::doc('x')])->assertCreated();

    $cards = Api::rows(filesBody($console->get(FILES_MANAGE."/packs/{$pack}")->assertOk())['cards']);

    expect($cards[0]['file'])->toBe(['name' => 'map.png', 'media_type' => 'image/png', 'byte_size' => strlen(ResourceFiles::png())])
        ->and($cards[1]['file'])->toBeNull();
});

it('answers 422, never 500, for a file part that is missing, not a file, repeated, failed, or sent with another Type, and writes nothing', function (Closure $send) {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);

    $response = $send($console, $pack);
    assert($response instanceof TestResponse);

    expect($response->status())->toBe(422)
        ->and(filesBody($response)['errors'] ?? null)->toHaveKey('file')
        ->and(ResourceFiles::stored())->toBe([])
        ->and(DB::table('resource_cards')->count())->toBe(0);
})->with([
    'no file part' => [fn (Console $c, string $p) => filesCreate($c, $p, null)],
    'a text field named file' => [fn (Console $c, string $p) => filesCreate($c, $p, 'guide.pdf')],
    'two file parts' => [fn (Console $c, string $p) => $c->multipart(FILES_MANAGE."/packs/{$p}/cards", ['type' => 'file', 'title' => 'x', 'file' => [ResourceFiles::upload('a.pdf', ResourceFiles::pdf()), ResourceFiles::upload('b.pdf', ResourceFiles::pdf())]])],
    'a partial upload' => [fn (Console $c, string $p) => filesCreate($c, $p, ResourceFiles::upload('a.pdf', ResourceFiles::pdf(), error: UPLOAD_ERR_PARTIAL))],
    'no temporary directory' => [fn (Console $c, string $p) => filesCreate($c, $p, ResourceFiles::upload('a.pdf', ResourceFiles::pdf(), error: UPLOAD_ERR_NO_TMP_DIR))],
    'a file on a basic Card' => [fn (Console $c, string $p) => $c->multipart(FILES_MANAGE."/packs/{$p}/cards", ['type' => 'basic', 'title' => 'x', 'file' => ResourceFiles::upload('a.pdf', ResourceFiles::pdf())])],
    'a File Card from a JSON body' => [fn (Console $c, string $p) => $c->post(FILES_MANAGE."/packs/{$p}/cards", ['type' => 'file', 'title' => 'x'])],
]);

it('answers 413 file_too_large for a file over the application\'s limit, and the same for one PHP refused for its size', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);
    config(['resources.assets.max_bytes' => 1024 * 1024]);
    $big = str_repeat("a line of perfectly ordinary text\n", 40000);

    $over = filesCreate($console, $pack, ResourceFiles::upload('big.txt', $big))->assertStatus(413);
    $php = filesCreate($console, $pack, ResourceFiles::upload('big.pdf', ResourceFiles::pdf(), error: UPLOAD_ERR_INI_SIZE))->assertStatus(413);

    expect(filesBody($over))->toBe(['message' => 'That file is too large: the limit is 1 MB.', 'code' => 'file_too_large', 'max_bytes' => 1048576])
        ->and(filesBody($php))->toBe(filesBody($over))
        ->and(ResourceFiles::stored())->toBe([]);
});

it('answers 413 file_too_large when the whole request is over PHP\'s post_max_size', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);

    $response = $console->multipart(FILES_MANAGE."/packs/{$pack}/cards", ['type' => 'file', 'title' => 'x'], ['Content-Length' => (string) (512 * 1024 * 1024)]);

    expect($response->status())->toBe(413)->and(filesBody($response)['code'])->toBe('file_too_large');
});

it('answers 422 file_type_not_allowed, naming what is allowed and never echoing the file', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);

    $response = filesCreate($console, $pack, ResourceFiles::upload('Zorblax.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png'))->assertStatus(422);

    expect(filesBody($response)['code'])->toBe('file_type_not_allowed')
        ->and(filesRow(filesBody($response)['errors'])['file'])->toHaveCount(1)
        ->and((string) $response->getContent())->toContain('.pdf')
        ->and(str_contains((string) $response->getContent(), 'Zorblax'))->toBeFalse()
        ->and(str_contains((string) $response->getContent(), 'svg'))->toBeFalse()
        ->and(str_contains((string) $response->getContent(), 'script'))->toBeFalse()
        ->and(ResourceFiles::stored())->toBe([]);
});

it('answers 422 invalid_content for description text that is not a document, and treats a blank one as none', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);

    expect(filesBody(filesCreate($console, $pack, ResourceFiles::upload('a.pdf', ResourceFiles::pdf()), ['content' => '{not json'])->assertStatus(422))['code'])->toBe('invalid_content')
        ->and(filesBody(filesCreate($console, $pack, ResourceFiles::upload('a.pdf', ResourceFiles::pdf()), ['content' => '"a string"'])->assertStatus(422))['code'])->toBe('invalid_content')
        ->and(ResourceFiles::stored())->toBe([])
        ->and(filesRow(filesBody(filesCreate($console, $pack, ResourceFiles::upload('a.pdf', ResourceFiles::pdf()), ['content' => '  '])->assertCreated())['content'])['document'])
        ->toBe(['type' => 'doc', 'content' => []]);
});

it('replaces a file from a multipart form, with no revision, keeping the Card', function () {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console);
    $before = filesBody($console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}")->assertOk());

    $after = filesBody($console->multipart(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file", ['file' => ResourceFiles::upload('Map.png', ResourceFiles::png())])->assertOk());

    expect($after['id'])->toBe($card)
        ->and($after['revision'])->toBe($before['revision'])
        ->and($after['state'])->toBe('published')
        ->and(filesRow($after['file'])['name'])->toBe('Map.png')
        ->and(filesRow($after['file'])['media_type'])->toBe('image/png')
        ->and(ResourceFiles::stored())->toHaveCount(1)
        ->and(streamed($console->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file")->assertOk()))->toBe(ResourceFiles::png());
});

it('refuses to replace with no file, onto a basic Card, or through the wrong Pack', function () {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console);
    $basic = Api::string(filesBody($console->post(FILES_MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'W', 'content' => Resources::doc('x')])->assertCreated())['id']);
    $other = filesPack($console, 'Other');

    $console->multipart(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file", [])->assertStatus(422);
    expect(filesBody($console->multipart(FILES_MANAGE."/packs/{$pack}/cards/{$basic}/file", ['file' => ResourceFiles::upload('a.pdf', ResourceFiles::pdf())])->assertStatus(422))['code'])->toBe('invalid_resource_input')
        ->and(filesBody($console->multipart(FILES_MANAGE."/packs/{$other}/cards/{$card}/file", ['file' => ResourceFiles::upload('a.pdf', ResourceFiles::pdf())])->assertNotFound())['code'])->toBe('card_not_found')
        ->and(ResourceFiles::stored())->toHaveCount(1);
});

// --- Downloads ------------------------------------------------------------------------------------------------------------------

it('streams a file to management, Draft included, with its stored type, an attachment disposition and no caching', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);
    $card = Api::string(filesBody(filesCreate($console, $pack, ResourceFiles::upload('Guide.pdf', ResourceFiles::pdf(), 'text/html'))->assertCreated())['id']);

    $response = $console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file")->assertOk();

    expect(streamed($response))->toBe(ResourceFiles::pdf())
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen(ResourceFiles::pdf()))
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=Guide.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain("frame-ancestors 'none'");
});

it('serves inline only a PDF or a raster image that asked for it, and every other file as an attachment', function (string $name, string $bytes, string $disposition) {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);
    $card = Api::string(filesBody(filesCreate($console, $pack, ResourceFiles::upload($name, $bytes))->assertCreated())['id']);

    $default = $console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file")->assertOk();
    $asked = $console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file?disposition=inline")->assertOk();

    expect((string) $default->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and((string) $asked->headers->get('Content-Disposition'))->toStartWith("{$disposition};");
})->with([
    'pdf' => ['a.pdf', ResourceFiles::pdf(), 'inline'],
    'png' => ['a.png', ResourceFiles::png(), 'inline'],
    'jpeg' => ['a.jpg', ResourceFiles::jpeg(), 'inline'],
    'webp' => ['a.webp', ResourceFiles::webp(), 'inline'],
    'gif' => ['a.gif', ResourceFiles::gif(), 'inline'],
    'text' => ['a.txt', "notes\n", 'attachment'],
    'csv' => ['a.csv', "a,b\n1,2\n", 'attachment'],
    'docx' => ['a.docx', ResourceFiles::office('word/document.xml'), 'attachment'],
]);

it('refuses a disposition it does not know', function () {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console);

    $console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file?disposition=render")->assertStatus(422);
    $console->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file?disposition=render")->assertStatus(422);
});

it('offers a file under its sanitised name, encoded per RFC 6266, and no name can add or break a header', function (string $uploaded, string $kept) {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console, 'Names', $uploaded);

    $response = $console->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file")->assertOk();
    $disposition = (string) $response->headers->get('Content-Disposition');

    // RFC 6266: the exact name as UTF-8 in `filename*` whenever the plain-ASCII `filename` cannot carry it as it is.
    $offered = preg_match("/filename\\*=utf-8''([^;]+)/", $disposition, $m) === 1
        ? rawurldecode($m[1])
        : (preg_match('/filename="((?:[^"\\\\]|\\\\.)*)"/', $disposition, $q) === 1 ? stripslashes($q[1]) : (string) preg_replace('/^.*filename=/', '', $disposition));

    expect($offered)->toBe($kept)
        ->and(preg_match('/[\r\n\x00]/', $disposition))->toBe(0)
        // One disposition, one ASCII `filename` (a token or a quoted string), at most one `filename*`, and nothing after.
        ->and(preg_match('/\Aattachment; filename=("([^"\\\\]|\\\\.)*"|[\x21-\x7E]+)(; filename\*=utf-8\'\'[\x21-\x7E]+)?\z/', $disposition))->toBe(1)
        ->and($response->headers->has('X-Injected'))->toBeFalse()
        ->and(collect($response->headers->getCookies())->map(fn ($c) => $c->getName())->all())->not->toContain('pwned');
})->with([
    'accents, quotes and emoji' => ['Café "menu" 🍰.pdf', 'Café "menu" 🍰.pdf'],
    'CRLF and a header' => ["evil\r\nX-Injected: 1\r\nSet-Cookie: pwned=1\r\n.pdf", 'evil X-Injected: 1 Set-Cookie: pwned=1 .pdf'],
    'a path' => ['../../etc/notes.pdf', 'notes.pdf'],
    'percent and backslash-free' => ['100% done.pdf', '100% done.pdf'],
]);

it('delivers a visible File Card\'s file to the library, describing it there with the library\'s own path and nothing management sees', function () {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console, 'Handbook', 'Guide.pdf');

    $delivered = Api::rows(filesBody($console->get(FILES_LIBRARY."/packs/{$pack}")->assertOk())['cards'])[0];
    $download = $console->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file")->assertOk();

    expect($delivered['file'])->toBe([
        'name' => 'Guide.pdf', 'media_type' => 'application/pdf', 'byte_size' => strlen(ResourceFiles::pdf()),
        'download_path' => FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file",
    ])
        ->and(array_keys($delivered))->toBe(['id', 'index', 'type', 'title', 'summary', 'uri', 'file', 'content'])
        ->and(streamed($download))->toBe(ResourceFiles::pdf())
        ->and($download->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store');
});

it('gives every reason a library file is not the viewer\'s the SAME answer, whatever exists', function () {
    [$console] = Resources::signedInGuardian();
    // A visible Pack with a visible basic Card, so the Pack itself is the viewer's, and File Cards around it that are not.
    [$visible, $visibleFile] = filesLive($console, 'Visible');
    $draftFile = Api::string(filesBody(filesCreate($console, $visible, ResourceFiles::upload('draft.pdf', ResourceFiles::pdf('draft')))->assertCreated())['id']);
    $narrowed = Api::string(filesBody(filesCreate($console, $visible, ResourceFiles::upload('narrow.pdf', ResourceFiles::pdf('narrow')))->assertCreated())['id']);
    $console->put(FILES_MANAGE."/packs/{$visible}/audiences", ['audiences' => ['guardian', 'member']])->assertOk();
    $console->post(FILES_MANAGE."/packs/{$visible}/cards/{$narrowed}/publish")->assertOk();
    $console->put(FILES_MANAGE."/packs/{$visible}/cards/{$narrowed}/audiences", ['mode' => 'narrowed', 'audiences' => ['member']])->assertOk();
    $basic = Api::string(filesBody($console->post(FILES_MANAGE."/packs/{$visible}/cards", ['type' => 'basic', 'title' => 'Words', 'content' => Resources::doc('x')])->assertCreated())['id']);
    $console->post(FILES_MANAGE."/packs/{$visible}/cards/{$basic}/publish")->assertOk();
    [$memberOnly, $memberFile] = filesLive($console, 'Members', 'm.pdf', null, ['member']);
    [$unpublished, $unpublishedFile] = filesLive($console, 'Withdrawn');
    $console->post(FILES_MANAGE."/packs/{$unpublished}/unpublish")->assertOk();
    $draftPack = filesPack($console, 'Never published');
    $inDraftPack = Api::string(filesBody(filesCreate($console, $draftPack, ResourceFiles::upload('d.pdf', ResourceFiles::pdf()))->assertCreated())['id']);
    $console->post(FILES_MANAGE."/packs/{$draftPack}/cards/{$inDraftPack}/publish")->assertOk();
    [$otherPack, $otherFile] = filesLive($console, 'Another');

    $cases = [
        'a Pack that does not exist' => ['01jaaaaaaaaaaaaaaaaaaaaaa0', $visibleFile],
        'a Card that does not exist' => [$visible, '01jaaaaaaaaaaaaaaaaaaaaaa0'],
        'a Draft File Card in a visible Pack' => [$visible, $draftFile],
        'a File Card narrowed to another audience' => [$visible, $narrowed],
        'a visible Card with no file' => [$visible, $basic],
        'a member-only Pack' => [$memberOnly, $memberFile],
        'an unpublished Pack' => [$unpublished, $unpublishedFile],
        'a Draft Pack' => [$draftPack, $inDraftPack],
        'a visible File Card asked for through another visible Pack' => [$visible, $otherFile],
    ];
    $bodies = [];
    foreach ($cases as $why => [$pack, $card]) {
        $response = $console->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file");
        expect($response->status())->toBe(404, $why);
        $bodies[$why] = (string) $response->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode(array_values($bodies)[0], true))->toBe(['message' => 'There is no such resource.', 'code' => 'resource_pack_not_found'])
        // The same Cards are management's to see, Drafts and all.
        ->and($console->get(FILES_MANAGE."/packs/{$visible}/cards/{$draftFile}/file")->getStatusCode())->toBe(200)
        ->and($console->get(FILES_MANAGE."/packs/{$draftPack}/cards/{$inDraftPack}/file")->getStatusCode())->toBe(200)
        ->and($console->get(FILES_LIBRARY."/packs/{$otherPack}/cards/{$otherFile}/file")->getStatusCode())->toBe(200);
});

it('answers asset_unavailable, never 500, for a file on record that is missing, and only once the Card is known to be the caller\'s', function () {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console);
    $hidden = filesPack($console, 'Hidden');
    $hiddenCard = Api::string(filesBody(filesCreate($console, $hidden, ResourceFiles::upload('h.pdf', ResourceFiles::pdf()))->assertCreated())['id']);
    foreach (DB::table('resource_assets')->pluck('storage_key') as $key) {
        Storage::disk('resources')->delete(Resources::str($key));
    }

    expect(filesBody($console->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file")->assertNotFound())['code'])->toBe('asset_unavailable')
        ->and(filesBody($console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file")->assertNotFound())['code'])->toBe('asset_unavailable')
        ->and(filesBody($console->get(FILES_LIBRARY."/packs/{$hidden}/cards/{$hiddenCard}/file")->assertNotFound())['code'])->toBe('resource_pack_not_found')
        ->and(filesRow(filesBody($console->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}")->assertOk())['file'])['available'])->toBeFalse();
});

it('answers asset_unavailable to management asking for the file of a Card that has none', function () {
    [$console] = Resources::signedInGuardian();
    $pack = filesPack($console);
    $basic = Api::string(filesBody($console->post(FILES_MANAGE."/packs/{$pack}/cards", ['type' => 'basic', 'title' => 'W', 'content' => Resources::doc('x')])->assertCreated())['id']);

    expect(filesBody($console->get(FILES_MANAGE."/packs/{$pack}/cards/{$basic}/file")->assertNotFound())['code'])->toBe('asset_unavailable');
});

// --- No way around the Card ---------------------------------------------------------------------------------------------------------

it('has no route that takes an asset id or storage key, and none that serves a disk: knowing a key opens nothing', function () {
    [$console] = Resources::signedInGuardian();
    filesLive($console);
    $key = Resources::str(DB::table('resource_assets')->value('storage_key'));

    $parameters = [];
    $uris = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uris[] = $route->uri();
        if (str_starts_with($route->uri(), 'api/v1/admin/resource')) {
            array_push($parameters, ...$route->parameterNames());
        }
    }

    expect(array_values(array_unique(Api::strings($parameters))))->toEqualCanonicalizing(['category', 'pack', 'card'])
        ->and(array_filter($uris, fn (string $uri): bool => str_starts_with($uri, 'storage')))->toBe([])
        ->and($console->get("/storage/resources/{$key}")->getStatusCode())->toBe(404)
        ->and($console->get("/storage/app/private/resources/{$key}")->getStatusCode())->toBe(404)
        ->and($console->get(FILES_LIBRARY."/files/{$key}")->getStatusCode())->toBe(404)
        ->and($console->get(FILES_MANAGE."/files/{$key}")->getStatusCode())->toBe(404);
});

it('refuses every file route to a session without a sign-in, and reveals nothing', function () {
    [$console] = Resources::signedInGuardian();
    [$pack, $card] = filesLive($console);
    $stranger = new Console;

    expect($stranger->get(FILES_LIBRARY."/packs/{$pack}/cards/{$card}/file")->status())->toBe(401)
        ->and($stranger->get(FILES_MANAGE."/packs/{$pack}/cards/{$card}/file")->status())->toBe(401);
});

it('configures the real store as a private disk under storage/, outside the web root: no URL, never served, failing loudly, following no link', function () {
    $disk = config()->array('filesystems.disks.resources');
    $root = is_string($disk['root'] ?? null) ? $disk['root'] : '';

    expect($disk)->toBe([
        'driver' => 'local', 'root' => storage_path('app/private/resources'), 'visibility' => 'private', 'directory_visibility' => 'private',
        'links' => 'skip', 'throw' => true, 'report' => false,
    ])
        ->and(str_starts_with($root, public_path()))->toBeFalse()
        // The stock local disk's root contains the store, so it must not be served either (it would answer signed /storage URLs).
        ->and(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
});
