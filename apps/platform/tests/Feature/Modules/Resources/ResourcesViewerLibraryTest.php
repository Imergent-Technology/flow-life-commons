<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Resources\Application\AssetUnavailable;
use App\Modules\Resources\Application\BrowseLibrary;
use App\Modules\Resources\Application\DownloadLibraryFile;
use App\Modules\Resources\Application\LibraryFilter;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\ReadLibraryPack;
use App\Modules\Resources\Application\ReorderPacks;
use App\Modules\Resources\Application\ResourcePackNotFound;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\ViewerCategory;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PublicationFilter;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Audiences;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;
use Tests\Support\ViewerFixture;

/*
 * POLICY A, the privileged viewer (ADR 0038, R4-R9): `resources.view` reads EVERY Pack and Card, Drafts and every audience included, and
 * nothing else opens it. It is a read model of its own, not delivery: it never asks who the reader is, what they belong to or what a
 * Card's audiences are. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    ResourceFiles::fake();
});

const VIEWER = '/api/v1/admin/resource-library';

/**
 * A library with one of everything the privileged viewer must see and delivery must not: a Draft Pack, a Published Pack holding a Draft
 * Card, a Card narrowed to another audience, an empty Pack, an uncategorized Draft Pack, a Category with no Pack, and a File Card in a Draft.
 */
function viewerLibrary(Actor $by): ViewerFixture
{
    $guides = Resources::category($by, 'Guides')->category->id;
    $safety = Resources::category($by, 'Safety')->category->id;
    Resources::category($by, 'Nothing in it');

    // Published, for Guardians, with one Published and one Draft Card.
    $welcome = Resources::pack($by, 'Welcome', $guides, summary: 'Start here');
    $live = Resources::publishedCard($by, $welcome, 'Opening steps', 'Unlock the door');
    $draftCard = Resources::card($by, $welcome, 'Closing steps (draft)', 'Bolt the gate', summary: 'Not ready');
    app(SetPackAudiences::class)($by, $welcome->pack->id, [Audience::Guardian]);
    $welcome = app(PublishPack::class)($by, $welcome->pack->id);

    // Published for Members and Volunteers, with a Card narrowed to Volunteers.
    $shared = Resources::pack($by, 'Rota', $guides);
    app(SetPackAudiences::class)($by, $shared->pack->id, [Audience::Member, Audience::Volunteer]);
    $everyone = Resources::publishedCard($by, $shared, 'Rota for all', 'The rota');
    $narrowed = Resources::publishedCard($by, $shared, 'Rota for volunteers', 'Volunteer shifts');
    app(SetCardAudiences::class)($by, $shared->pack->id, $narrowed->card->id, AudienceMode::Narrowed, [Audience::Volunteer]);
    $shared = app(PublishPack::class)($by, $shared->pack->id);

    // A Draft Pack for Volunteers, a Draft Card inside it, and a File Card.
    $draft = Resources::pack($by, 'Winter plan', $safety);
    app(SetPackAudiences::class)($by, $draft->pack->id, [Audience::Volunteer]);
    $draftOnly = Resources::card($by, $draft, 'Heating', 'Turn it up');
    $file = ResourceFiles::card($by, $draft, 'Floor plan', 'plan.pdf');

    $empty = Resources::pack($by, 'Empty pack', $safety);
    $loose = Resources::pack($by, 'Loose notes', null);
    Resources::card($by, $loose, 'A note', 'Remember');

    return new ViewerFixture(
        $guides->value, $safety->value, $welcome, $live, $draftCard, $shared, $everyone, $narrowed, $draft, $draftOnly, $file, $empty, $loose,
    );
}

/** @return list<string> every Pack title in a privileged listing, group by group */
function viewerTitles(Actor $as, ?LibraryFilter $filter = null): array
{
    $titles = [];
    foreach (app(BrowseLibrary::class)($as, $filter ?? new LibraryFilter) as $group) {
        foreach ($group->packs as $listed) {
            $titles[] = $listed->pack->title;
        }
    }

    return $titles;
}

it('lists every Pack in every state, for every audience: Drafts, narrowed Cards, empty Packs and the uncategorized group last', function () {
    $by = Resources::editor();
    viewerLibrary($by);

    $groups = app(BrowseLibrary::class)($by, new LibraryFilter);

    // Categories in order, a Category with no Pack is not listed, and the uncategorized Packs form a final group with a null Category.
    expect(array_map(fn (ViewerCategory $g): ?string => $g->category?->name, $groups))->toBe(['Guides', 'Safety', null])
        ->and(viewerTitles($by))->toBe(['Welcome', 'Rota', 'Winter plan', 'Empty pack', 'Loose notes']);
});

it('reads a Draft Pack, an empty Pack and an uncategorized Pack by id, and every Card of each, Drafts included, numbered 1..n across all of them', function () {
    $by = Resources::editor();
    $f = viewerLibrary($by);
    $read = fn (ManagedPackView $p) => app(ReadLibraryPack::class)($by, $p->pack->id);

    $welcome = $read($f->welcome);
    $draft = $read($f->draft);
    $empty = $read($f->empty);
    $loose = $read($f->loose);

    expect(array_map(fn ($c): string => $c->card->title, $welcome->cards))->toBe(['Opening steps', 'Closing steps (draft)'])
        ->and(array_map(fn ($c): int => $c->index, $welcome->cards))->toBe([1, 2])
        ->and($welcome->draftCardCount)->toBe(1)
        ->and($draft->pack->state->value)->toBe('draft')
        ->and(array_map(fn ($c): string => $c->card->title, $draft->cards))->toBe(['Heating', 'Floor plan'])
        ->and($empty->cards)->toBe([])
        ->and($loose->category)->toBeNull()
        ->and($loose->cards)->toHaveCount(1);
});

it('reports each Pack\'s state and audiences and each Card\'s state, mode and EFFECTIVE audiences, as data it never applies', function () {
    $by = Resources::editor();
    $f = viewerLibrary($by);

    $shared = app(ReadLibraryPack::class)($by, $f->shared->pack->id);

    expect($shared->pack->audiences->values())->toBe(['member', 'volunteer'])
        ->and(array_map(fn ($c): array => [$c->card->title, $c->card->state->value, $c->card->audience->mode->value, $c->audiences->values()], $shared->cards))->toBe([
            ['Rota for all', 'published', 'inherit', ['member', 'volunteer']],
            ['Rota for volunteers', 'published', 'narrowed', ['volunteer']],
        ]);
});

it('orders Categories by position, Packs and Cards by (position, id), and puts uncategorized Packs last', function () {
    $by = Resources::editor();
    $first = Resources::category($by, 'First')->category->id;
    $second = Resources::category($by, 'Second')->category->id;
    $b = Resources::pack($by, 'B', $second);
    $a = Resources::pack($by, 'A', $first);
    $c = Resources::pack($by, 'C', $first);
    Resources::pack($by, 'Loose B');
    Resources::pack($by, 'Loose A');
    app(ReorderPacks::class)($by, $first, [$c->pack->id, $a->pack->id]);

    expect(viewerTitles($by))->toBe(['C', 'A', 'B', 'Loose B', 'Loose A'])
        ->and($b->pack->title)->toBe('B');
});

it('narrows by Category, by text over every Card including Drafts, by publication and by audience, and a filter never widens', function () {
    $by = Resources::editor();
    $f = viewerLibrary($by);
    $with = fn (LibraryFilter $filter): array => viewerTitles($by, $filter);

    expect($with(new LibraryFilter(category: CategoryId::fromString(Api::string($f->guides)))))->toBe(['Welcome', 'Rota'])
        // Text matches a Pack's title and summary and ALL Cards' titles and summaries, a Draft Card's included (R8).
        ->and($with(new LibraryFilter(text: 'start here')))->toBe(['Welcome'])
        ->and($with(new LibraryFilter(text: 'CLOSING STEPS')))->toBe(['Welcome'])
        ->and($with(new LibraryFilter(text: 'not ready')))->toBe(['Welcome'])
        ->and($with(new LibraryFilter(text: 'heating')))->toBe(['Winter plan'])
        ->and($with(new LibraryFilter(text: 'rota for volunteers')))->toBe(['Rota'])
        ->and($with(new LibraryFilter(text: 'no such thing')))->toBe([])
        // Rich content is never searched.
        ->and($with(new LibraryFilter(text: 'Bolt the gate')))->toBe([])
        // `published` is Published Packs; `draft` is Draft Packs AND Published Packs that hold a Draft Card.
        ->and($with(new LibraryFilter(publication: PublicationFilter::Published)))->toBe(['Welcome', 'Rota'])
        ->and($with(new LibraryFilter(publication: PublicationFilter::Draft)))->toBe(['Welcome', 'Winter plan', 'Empty pack', 'Loose notes'])
        // The audience filter is the Packs targeted at it, and only narrows.
        ->and($with(new LibraryFilter(audience: Audience::Volunteer)))->toBe(['Rota', 'Winter plan'])
        ->and($with(new LibraryFilter(audience: Audience::Guardian)))->toBe(['Welcome'])
        ->and($with(new LibraryFilter(audience: Audience::Member, publication: PublicationFilter::Draft)))->toBe([])
        ->and($with(new LibraryFilter(audience: Audience::Volunteer, publication: PublicationFilter::Draft, text: 'heating')))->toBe(['Winter plan']);
});

it('serves exactly the documented shape over HTTP, with provenance as a display name only', function () {
    [$console, $account] = Resources::signedInGuardian('vera.viewer@example.org', 'Vera Viewer');
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    $f = viewerLibrary($by);
    DB::table('people')->where('id', $by->personId->value)->update(['display_name' => 'Ed Renamed']);

    $library = Api::map($console->get(VIEWER)->assertOk()->json());
    $groups = Api::rows($library['data']);
    $welcome = Api::rows($groups[0]['packs'])[0];
    $loose = Api::rows($groups[2]['packs'])[0];

    expect(array_keys($library))->toBe(['data'])
        ->and(array_column(array_map(Api::map(...), array_map(fn ($g) => $g['category'] ?? [], $groups)), 'name'))->toBe(['Guides', 'Safety'])
        ->and($groups[2]['category'])->toBeNull()
        ->and(array_keys($welcome))->toBe(['id', 'title', 'summary', 'is_series', 'state', 'audiences', 'card_count', 'draft_card_count', 'updated_at', 'updated_by'])
        ->and($welcome['card_count'])->toBe(2)->and($welcome['draft_card_count'])->toBe(1)
        ->and($welcome['state'])->toBe('published')->and($welcome['audiences'])->toBe(['guardian'])
        ->and($welcome['updated_by'])->toBe(['display_name' => 'Ed Renamed'])
        ->and($loose['state'])->toBe('draft')->and($loose['card_count'])->toBe(1);

    $pack = Api::map($console->get(VIEWER.'/packs/'.$f->welcome->pack->id->value)->assertOk()->json());
    $cards = Api::rows($pack['cards']);

    expect(array_keys($pack))->toBe(['id', 'title', 'summary', 'is_series', 'category', 'state', 'audiences', 'card_count', 'draft_card_count', 'updated_at', 'updated_by', 'cards'])
        ->and(array_keys($cards[0]))->toBe(['id', 'index', 'type', 'title', 'summary', 'uri', 'file', 'content', 'state', 'audience_mode', 'audiences', 'updated_at', 'updated_by'])
        ->and(array_column($cards, 'state'))->toBe(['published', 'draft'])
        ->and(array_column($cards, 'index'))->toBe([1, 2])
        ->and($cards[1]['audiences'])->toBe(['guardian'])
        ->and($account->personId->value)->not->toBe('');

    // At every depth: no revision, stored position, creator, Person id or action, however deep.
    $text = (string) $console->get(VIEWER.'/packs/'.$f->welcome->pack->id->value)->getContent();
    $list = (string) $console->get(VIEWER)->getContent();
    foreach (['revision', 'position', 'created_by', 'created_at', $by->personId->value, $by->accountId->value, '"publish"', '"edit"', '"delete"', '"actions"', '"links"'] as $forbidden) {
        expect($text)->not->toContain($forbidden)->and($list)->not->toContain($forbidden);
    }
});

it('refuses an unknown filter value with 422, and a filter never stands in for the capability', function () {
    [$console] = Resources::signedInGuardian();
    viewerLibrary(Resources::editor('ed.editor@example.org', 'Ed Editor'));

    foreach (['publication=everything', 'publication=Draft', 'audience=partner', 'audience=everyone', 'category=not-a-ulid', 'q='.str_repeat('a', 201), 'publication[]=draft', 'audience[]=guardian'] as $query) {
        $console->get(VIEWER."?{$query}")->assertStatus(422);
    }
    $console->get(VIEWER.'?publication=draft&audience=volunteer')->assertOk();

    // Without resources.view every filtered call is still a 403, whatever it names.
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => $ability === 'resources.view' ? false : null);
    foreach (['', '?publication=draft', '?audience=volunteer&publication=published', '?q=anything'] as $query) {
        $console->get(VIEWER.$query)->assertForbidden();
    }
});

it('reads a Draft File Card of any audience with no signed address, and says exactly what is missing', function () {
    [$console] = Resources::signedInGuardian();
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    $f = viewerLibrary($by);
    $pack = $f->draft->pack->id->value;
    $file = $f->file->card->id->value;

    $response = $console->get(VIEWER."/packs/{$pack}/cards/{$file}/file")->assertOk();

    expect($response->streamedContent())->toBe(ResourceFiles::pdf())
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Location'))->toBeNull()
        ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment;');

    // The Card is described with the library's own path.
    $card = Api::rows(Api::map($console->get(VIEWER."/packs/{$pack}")->assertOk()->json())['cards'])[1];
    expect(Api::map($card['file'])['download_path'])->toBe(VIEWER."/packs/{$pack}/cards/{$file}/file");

    // A missing Pack, or a Card that is not in the Pack, is one answer; a Card with no file, or whose bytes are gone, is another.
    $missing = ['message' => 'There is no such resource.', 'code' => 'resource_pack_not_found'];
    expect($console->get(VIEWER.'/packs/01jaaaaaaaaaaaaaaaaaaaaaa0')->assertNotFound()->json())->toBe($missing)
        ->and($console->get(VIEWER."/packs/{$pack}/cards/01jaaaaaaaaaaaaaaaaaaaaaa0/file")->assertNotFound()->json())->toBe($missing)
        ->and($console->get(VIEWER.'/packs/'.$f->welcome->pack->id->value."/cards/{$file}/file")->assertNotFound()->json())->toBe($missing)
        ->and(Api::map($console->get(VIEWER."/packs/{$pack}/cards/".$f->draftOnly->card->id->value.'/file')->assertNotFound()->json())['code'])->toBe('asset_unavailable');
    foreach (DB::table('resource_assets')->pluck('storage_key') as $key) {
        Storage::disk('resources')->delete(Resources::str($key));
    }
    expect(Api::map($console->get(VIEWER."/packs/{$pack}/cards/{$file}/file")->assertNotFound()->json())['code'])->toBe('asset_unavailable');
});

it('is read-only: no verb but GET exists on the library, and a holder of resources.view alone changes nothing', function () {
    [$console] = Resources::signedInGuardian();
    $f = viewerLibrary(Resources::editor('ed.editor@example.org', 'Ed Editor'));
    $pack = $f->welcome->pack->id->value;
    $before = array_map(fn (string $t): int => DB::table($t)->count(), Resources::tables());
    Gate::before(fn (Authenticatable $user, string $ability): ?bool => $ability === 'resources.manage' ? false : null);

    foreach ([VIEWER, VIEWER."/packs/{$pack}", VIEWER."/packs/{$pack}/cards/".$f->live->card->id->value.'/file'] as $path) {
        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            expect($console->{$verb}($path, ['title' => 'x'])->getStatusCode())->toBe(405, "{$verb} {$path}");
        }
    }
    expect(array_map(fn (string $t): int => DB::table($t)->count(), Resources::tables()))->toBe($before);
});

it('is the viewer\'s whatever their relationships: no Guardian relationship needed to read Guardian content, and none sufficient', function () {
    $by = Resources::editor();
    viewerLibrary($by);
    $expected = ['Welcome', 'Rota', 'Winter plan', 'Empty pack', 'Loose notes'];

    // A holder of resources.view with NO relationship and NO membership reads everything, including Volunteer and Member content.
    expect(viewerTitles($by))->toBe($expected);

    // An Active Guardian, Volunteer and Member WITHOUT resources.view reads none of it here, however eligible.
    $reader = Audiences::reader(['guardian', 'volunteer', 'member']);
    expect(fn () => app(BrowseLibrary::class)(Access::actorFor($reader), new LibraryFilter))->toThrow(AccessDenied::class)
        ->and(fn () => app(ReadLibraryPack::class)(Access::actorFor($reader), PackId::generate()))->toThrow(AccessDenied::class)
        ->and(fn () => app(DownloadLibraryFile::class)(Access::actorFor($reader), PackId::generate(), CardId::generate()))->toThrow(AccessDenied::class);
    Audiences::signIn($reader)->get(VIEWER)->assertForbidden();
});

it('answers a missing Pack with the one not-found refusal and a missing file Card with the other, to a use case caller as to HTTP', function () {
    $by = Resources::editor();
    $f = viewerLibrary($by);

    expect(fn () => app(ReadLibraryPack::class)($by, PackId::generate()))->toThrow(ResourcePackNotFound::class)
        ->and(fn () => app(DownloadLibraryFile::class)($by, PackId::generate(), $f->file->card->id))->toThrow(ResourcePackNotFound::class)
        ->and(fn () => app(DownloadLibraryFile::class)($by, $f->draft->pack->id, CardId::generate()))->toThrow(ResourcePackNotFound::class)
        ->and(fn () => app(DownloadLibraryFile::class)($by, $f->draft->pack->id, $f->draftOnly->card->id))->toThrow(AssetUnavailable::class);
    $stream = app(DownloadLibraryFile::class)($by, $f->draft->pack->id, $f->file->card->id);
    expect($stream->filename)->toBe('plan.pdf');
    fclose($stream->stream);
});
