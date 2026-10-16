<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Api;
use Tests\Support\Audiences;
use Tests\Support\Console;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;

/*
 * G6 WP2-05. What a CORRUPT stored value in Resources can and cannot do.
 *
 * Resources parses a stored `state` or `audience_mode` strictly. A value the code does not know (a manual edit, a mismatched restore, a
 * collation quirk) makes the read fail, which the HTTP layer answers with a 500, while an unknown audience KEY is dropped and matches
 * nobody. The owner accepted that fail-closed behaviour for G6 and deferred any tolerant parsing, so this test pins the SECURITY
 * invariant and deliberately NOT the status: a corrupt value may give a hidden answer or a controlled error, today a 404 or a 500, and
 * a later change to either is allowed. What must hold on both engines (MariaDB compares `state = 'published'` case-insensitively and
 * ignoring trailing spaces, PostgreSQL does not, so the same row is hidden by SQL on one and by parsing on the other):
 *
 * 1. A reader never receives content their audience does not entitle them to: no Draft, no other audience's Card, nothing at all for
 *    a Person with no relationship. A corrupt value never widens that.
 * 2. A value the code cannot read is never treated as Published or as Inherit: nobody reads the item through the audience routes.
 * 3. The capability gate holds: a Console participant without resources.view or resources.manage is refused the library and
 *    management routes, whatever is stored.
 * 4. No response, to anyone, carries where a file is stored.
 *
 * Preserved elsewhere: ordinary missing, unpublished and wrong-audience content gives one uniform 404 (ResourcesFilesApiTest).
 * The corruption edge differs from it by design and by the audit's finding (WP2-05): a corrupt Pack opened by an ineligible reader
 * may answer 500 where a missing Pack answers 404.
 */

const CORRUPT_MANAGE = '/api/v1/admin/resources';
const CORRUPT_LIBRARY = '/api/v1/admin/resource-library';
const CORRUPT_MINE = '/api/v1/admin/my-resources';

/** Text that appears only in the named item, so a response either carries the item or it does not. */
const CORRUPT_PACK = 'MARKER-PACK-LIVE';
const CORRUPT_TEXT = 'MARKER-CARD-TEXT';
const CORRUPT_FILE = 'MARKER-FILE-BYTES';
const CORRUPT_NARROWED = 'MARKER-CARD-NARROWED';
const CORRUPT_DRAFT_CARD = 'MARKER-CARD-DRAFT';
const CORRUPT_DRAFT_PACK = 'MARKER-PACK-DRAFT';

beforeEach(function () {
    ResourceFiles::fake();
});

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function corruptBody(TestResponse $response): array
{
    $json = $response->json();
    assert(is_array($json));

    /** @var array<string, mixed> $json */
    return $json;
}

/**
 * Everything the reader-facing and management routes serve, built through the real use cases over HTTP: a live Pack for Guardians and
 * Members (a text Card, a File Card, a Card narrowed to Members, a Draft Card) and a Draft Pack for Guardians that holds two published Cards.
 *
 * @return array{pack: string, text: string, file: string, narrowed: string, draftCard: string, draftPack: string, draftPackText: string, draftPackFile: string}
 */
function corruptFixture(Console $guardian): array
{
    $category = Api::string(corruptBody($guardian->post(CORRUPT_MANAGE.'/categories', ['name' => 'Corruption'])->assertCreated())['id']);
    $newPack = fn (string $title): string => Api::string(corruptBody($guardian->post(CORRUPT_MANAGE.'/packs', ['title' => $title, 'category_id' => $category])->assertCreated())['id']);
    $text = fn (string $pack, string $marker): string => Api::string(corruptBody($guardian->post(CORRUPT_MANAGE."/packs/{$pack}/cards", [
        'type' => 'basic', 'title' => "Card {$marker}", 'content' => Resources::doc("Words {$marker}"),
    ])->assertCreated())['id']);
    $file = fn (string $pack, string $marker): string => Api::string(corruptBody($guardian->multipart(CORRUPT_MANAGE."/packs/{$pack}/cards", [
        'type' => 'file', 'title' => "File {$marker}", 'file' => ResourceFiles::upload('guide.pdf', ResourceFiles::pdf($marker)),
    ])->assertCreated())['id']);
    $publish = function (string $pack, string $card) use ($guardian): void {
        $guardian->post(CORRUPT_MANAGE."/packs/{$pack}/cards/{$card}/publish")->assertOk();
    };

    $live = $newPack('Pack '.CORRUPT_PACK);
    $liveText = $text($live, CORRUPT_TEXT);
    $liveFile = $file($live, CORRUPT_FILE);
    $narrowed = $text($live, CORRUPT_NARROWED);
    $draftCard = $text($live, CORRUPT_DRAFT_CARD);
    foreach ([$liveText, $liveFile, $narrowed] as $card) {
        $publish($live, $card);
    }
    $guardian->put(CORRUPT_MANAGE."/packs/{$live}/audiences", ['audiences' => ['guardian', 'member']])->assertOk();
    $guardian->put(CORRUPT_MANAGE."/packs/{$live}/cards/{$narrowed}/audiences", ['mode' => 'narrowed', 'audiences' => ['member']])->assertOk();
    $guardian->post(CORRUPT_MANAGE."/packs/{$live}/publish")->assertOk();

    $draft = $newPack('Pack '.CORRUPT_DRAFT_PACK);
    $draftText = $text($draft, CORRUPT_DRAFT_PACK.' words');
    $draftFile = $file($draft, CORRUPT_DRAFT_PACK.' bytes');
    $publish($draft, $draftText);
    $publish($draft, $draftFile);
    $guardian->put(CORRUPT_MANAGE."/packs/{$draft}/audiences", ['audiences' => ['guardian', 'member']])->assertOk();

    return [
        'pack' => $live, 'text' => $liveText, 'file' => $liveFile, 'narrowed' => $narrowed, 'draftCard' => $draftCard,
        'draftPack' => $draft, 'draftPackText' => $draftText, 'draftPackFile' => $draftFile,
    ];
}

/**
 * What a stored value is changed to, and which markers NO reader may then receive through the audience routes (beyond what each
 * reader's audience already withholds from them).
 *
 * @return array<string, array{apply: Closure(array<string, string>): mixed, hides: list<string>}>
 */
function corruptCases(): array
{
    $pack = fn (string $key, string $state): Closure => fn (array $ids) => DB::table('resource_packs')->where('id', $ids[$key])->update(['state' => $state]);
    $card = fn (string $key, string $column, string $value): Closure => fn (array $ids) => DB::table('resource_cards')->where('id', $ids[$key])->update([$column => $value]);
    $liveItems = [CORRUPT_PACK, CORRUPT_TEXT, CORRUPT_FILE, CORRUPT_NARROWED];

    return [
        'a Draft Pack whose state reads PUBLISHED' => ['apply' => $pack('draftPack', 'PUBLISHED'), 'hides' => [CORRUPT_DRAFT_PACK]],
        'a Draft Pack whose state reads "published " with a trailing space' => ['apply' => $pack('draftPack', 'published '), 'hides' => [CORRUPT_DRAFT_PACK]],
        'a Draft Pack whose state reads archived' => ['apply' => $pack('draftPack', 'archived'), 'hides' => [CORRUPT_DRAFT_PACK]],
        'a Draft Pack whose state is empty' => ['apply' => $pack('draftPack', ''), 'hides' => [CORRUPT_DRAFT_PACK]],
        'a Published Pack whose state reads Published' => ['apply' => $pack('pack', 'Published'), 'hides' => $liveItems],
        'a Published Pack whose state is unknown' => ['apply' => $pack('pack', 'retired'), 'hides' => $liveItems],
        'a Draft Card whose state reads PUBLISHED' => ['apply' => $card('draftCard', 'state', 'PUBLISHED'), 'hides' => [CORRUPT_DRAFT_CARD]],
        'a Draft Card whose state reads "published " with a trailing space' => ['apply' => $card('draftCard', 'state', 'published '), 'hides' => [CORRUPT_DRAFT_CARD]],
        'a Published Card whose state is unknown' => ['apply' => $card('text', 'state', 'retired'), 'hides' => [CORRUPT_TEXT]],
        'a Published File Card whose state is unknown' => ['apply' => $card('file', 'state', 'retired'), 'hides' => [CORRUPT_FILE]],
        'a narrowed Card whose audience mode reads NARROWED' => ['apply' => $card('narrowed', 'audience_mode', 'NARROWED'), 'hides' => [CORRUPT_NARROWED]],
        'a narrowed Card whose audience mode reads "inherit " with a trailing space' => ['apply' => $card('narrowed', 'audience_mode', 'inherit '), 'hides' => [CORRUPT_NARROWED]],
        'a narrowed Card whose audience mode is unknown' => ['apply' => $card('narrowed', 'audience_mode', 'everyone'), 'hides' => [CORRUPT_NARROWED]],
        'an inheriting Card whose audience mode is unknown' => ['apply' => $card('text', 'audience_mode', 'everyone'), 'hides' => [CORRUPT_TEXT]],
        'a narrowed Card that has lost its audience rows' => [
            'apply' => fn (array $ids) => DB::table('resource_card_audiences')->where('card_id', $ids['narrowed'])->delete(),
            'hides' => [CORRUPT_NARROWED],
        ],
        'a Pack whose audience keys are all unknown' => [
            'apply' => function (array $ids): void {
                DB::table('resource_pack_audiences')->where('pack_id', $ids['pack'])->delete();
                DB::table('resource_pack_audiences')->insert(['pack_id' => $ids['pack'], 'audience' => 'everyone']);
            },
            'hides' => $liveItems,
        ],
        'a Pack with an extra unknown audience key beside its own' => [
            'apply' => fn (array $ids) => DB::table('resource_pack_audiences')->insert(['pack_id' => $ids['pack'], 'audience' => 'public']),
            'hides' => [],
        ],
        'a Pack whose audience key is written in the wrong case' => [
            'apply' => fn (array $ids) => DB::table('resource_pack_audiences')->where('pack_id', $ids['pack'])->update(['audience' => DB::raw('UPPER(audience)')]),
            'hides' => $liveItems,
        ],
    ];
}

/**
 * Whatever a response sent, as one string to search: the body (streamed or not) and every header.
 *
 * @param  TestResponse<Response>  $response
 */
function corruptText(TestResponse $response): string
{
    $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();

    return $body."\n".$response->headers;
}

it('never lets a corrupt stored Resources value reveal, widen or deliver content, on any reading policy', function (string $case) {
    [$guardian] = Resources::signedInGuardian();
    $ids = corruptFixture($guardian);
    $storageKeys = array_map(Resources::str(...), DB::table('resource_assets')->pluck('storage_key')->all());
    expect($storageKeys)->not->toBe([]);

    $corruption = corruptCases()[$case];
    $corruption['apply']($ids);

    // Each reader: their own Console, the markers their audience must withhold from them, and that nobody may receive for this corruption.
    $everything = [CORRUPT_PACK, CORRUPT_TEXT, CORRUPT_FILE, CORRUPT_NARROWED, CORRUPT_DRAFT_CARD, CORRUPT_DRAFT_PACK];
    $readers = [
        'a Person with no relationship' => [Audiences::signIn(Audiences::reader([])), $everything],
        'a Guardian' => [Audiences::signIn(Audiences::reader(['guardian'])), [CORRUPT_NARROWED, CORRUPT_DRAFT_CARD, CORRUPT_DRAFT_PACK, ...$corruption['hides']]],
        'a Member' => [Audiences::signIn(Audiences::reader(['member'])), [CORRUPT_DRAFT_CARD, CORRUPT_DRAFT_PACK, ...$corruption['hides']]],
    ];
    $audienceRoutes = [
        CORRUPT_MINE,
        CORRUPT_MINE."/packs/{$ids['pack']}",
        CORRUPT_MINE."/packs/{$ids['draftPack']}",
        CORRUPT_MINE."/packs/{$ids['pack']}/cards/{$ids['file']}/file",
        CORRUPT_MINE."/packs/{$ids['draftPack']}/cards/{$ids['draftPackFile']}/file",
        CORRUPT_MINE."/packs/{$ids['pack']}/cards/{$ids['narrowed']}/file",
    ];
    $gatedRoutes = [
        CORRUPT_LIBRARY, CORRUPT_LIBRARY."/packs/{$ids['pack']}", CORRUPT_LIBRARY."/packs/{$ids['pack']}/cards/{$ids['file']}/file",
        CORRUPT_MANAGE.'/packs', CORRUPT_MANAGE."/packs/{$ids['pack']}", CORRUPT_MANAGE."/packs/{$ids['pack']}/cards/{$ids['file']}/file",
    ];

    $seen = [];
    foreach ($readers as $who => [$console, $withheld]) {
        foreach ($audienceRoutes as $route) {
            $response = $console->get($route);
            $text = corruptText($response);
            $seen[] = $text;
            expect($response->getStatusCode())->toBeLessThan(600)->toBeGreaterThanOrEqual(200)
                ->and($response->isRedirection())->toBeFalse("{$who} was redirected from {$route}");
            foreach ($withheld as $marker) {
                expect(str_contains($text, $marker))->toBeFalse("{$case}: {$who} received {$marker} from {$route} (status {$response->getStatusCode()})");
            }
        }
    }

    // The capability gate: a Console participant who holds neither Resources capability is refused, whatever is stored.
    $participant = $readers['a Guardian'][0];
    foreach ($gatedRoutes as $route) {
        $response = $participant->get($route);
        $seen[] = corruptText($response);
        expect($response->getStatusCode())->toBe(403, "{$case}: {$route}");
    }

    // The privileged Guardian may see anything, or get a controlled error; what they never get is where a file is kept.
    foreach (array_merge([CORRUPT_LIBRARY], array_map(fn (string $route): string => $route, $gatedRoutes)) as $route) {
        $response = $guardian->get($route);
        $seen[] = corruptText($response);
        expect($response->isRedirection())->toBeFalse($route);
    }

    foreach ($seen as $text) {
        foreach ($storageKeys as $key) {
            expect(str_contains($text, $key))->toBeFalse("{$case}: a response named a stored file's key");
        }
    }
})->with(array_keys(corruptCases()));

it('does not widen or reveal across the corrupt values the audit probed, and keeps the ordinary answers uniform', function () {
    // Sanity for the fixture: with nothing corrupt, the audience routes serve exactly what the audiences entitle each reader to.
    [$guardian] = Resources::signedInGuardian();
    $ids = corruptFixture($guardian);
    $guardianReader = Audiences::signIn(Audiences::reader(['guardian']));
    $memberReader = Audiences::signIn(Audiences::reader(['member']));
    $outsider = Audiences::signIn(Audiences::reader([]));
    $pack = CORRUPT_MINE."/packs/{$ids['pack']}";
    $narrowedFile = CORRUPT_MINE."/packs/{$ids['pack']}/cards/{$ids['narrowed']}/file";
    $draftPackFile = CORRUPT_MINE."/packs/{$ids['draftPack']}/cards/{$ids['draftPackFile']}/file";

    $guardianSees = corruptText($guardianReader->get($pack)->assertOk());
    $memberSees = corruptText($memberReader->get($pack)->assertOk());
    $sees = fn (string $text, string $marker): bool => str_contains($text, $marker);
    expect([$sees($guardianSees, CORRUPT_TEXT), $sees($guardianSees, CORRUPT_FILE), $sees($guardianSees, CORRUPT_NARROWED), $sees($guardianSees, CORRUPT_DRAFT_CARD)])
        ->toBe([true, true, false, false])
        ->and([$sees($memberSees, CORRUPT_TEXT), $sees($memberSees, CORRUPT_NARROWED), $sees($memberSees, CORRUPT_DRAFT_CARD)])
        ->toBe([true, true, false]);

    // One answer for every ordinary reason content is not the reader's.
    $answers = [
        $outsider->get($pack)->assertNotFound()->getContent(),
        $guardianReader->get($narrowedFile)->assertNotFound()->getContent(),
        $guardianReader->get(CORRUPT_MINE."/packs/{$ids['draftPack']}")->assertNotFound()->getContent(),
        $guardianReader->get($draftPackFile)->assertNotFound()->getContent(),
        $guardianReader->get(CORRUPT_MINE.'/packs/01jaaaaaaaaaaaaaaaaaaaaaa0')->assertNotFound()->getContent(),
    ];
    expect(array_unique($answers))->toHaveCount(1);
});
