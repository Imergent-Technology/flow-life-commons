<?php

declare(strict_types=1);

use App\Modules\Resources\Application\CardAudienceConflict;
use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Application\GetManagedPack;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\OrderMismatch;
use App\Modules\Resources\Application\PackNotFound;
use App\Modules\Resources\Application\PackNotPublishable;
use App\Modules\Resources\Application\PageManagedPacks;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishedPackRequirement;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\ReorderPacks;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\StaleRevision;
use App\Modules\Resources\Application\UnknownCategory;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UnpublishPack;
use App\Modules\Resources\Application\UpdatePack;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PublicationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Resources;

/*
 * Resource Packs (ADR 0037, decisions 11-16 and 56-57): an empty Draft that becomes Published only when it can be, and stays
 * publishable for as long as it is Published. Runs on MariaDB and PostgreSQL.
 */

/** @return list<string> */
function packTitlesInCategory(CategoryId $category): array
{
    return array_map(
        fn (ManagedPackView $p): string => $p->pack->title,
        app(PageManagedPacks::class)(Resources::editor(), new ManagedPackFilter(category: $category), 1, 100)->packs,
    );
}

it('creates an empty Draft: no audience, no Cards, revision 1, and the creator on record', function () {
    $by = Resources::editor();
    $view = Resources::pack($by, '  Opening the doors  ', summary: 'How we open', series: true);

    expect($view->pack->state)->toBe(PublicationState::Draft)
        ->and($view->pack->title)->toBe('Opening the doors')
        ->and($view->pack->summary)->toBe('How we open')
        ->and($view->pack->isSeries)->toBeTrue()
        ->and($view->pack->revision)->toBe(1)
        ->and($view->pack->audiences->isEmpty())->toBeTrue()
        ->and($view->pack->categoryId)->toBeNull()
        ->and($view->category)->toBeNull()
        ->and($view->cardCount)->toBe(0)
        ->and($view->cards)->toBe([])
        ->and($view->createdBy->displayName)->toBe('Ed Editor');
});

it('lets a Draft Pack have no Category, and appends it to a Category\'s Packs when it has one', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Guides')->category->id;
    Resources::pack($by, 'First', $category);
    Resources::pack($by, 'Second', $category);
    Resources::pack($by, 'Loose');

    expect(packTitlesInCategory($category))->toBe(['First', 'Second'])
        ->and(Resources::ints(DB::table('resource_packs')->where('category_id', $category->value)->orderBy('position')->pluck('position')))->toBe([1, 2]);
});

it('refuses a Pack in a Category that does not exist', function () {
    expect(fn () => Resources::pack(Resources::editor(), 'X', CategoryId::generate()))->toThrow(UnknownCategory::class)
        ->and(DB::table('resource_packs')->count())->toBe(0);
});

it('refuses a blank, over-long or control-character title and an over-long summary', function (string $title, ?string $summary) {
    expect(fn () => app(CreatePack::class)(Resources::editor(), $title, $summary, false, null))->toThrow(InvalidResourceInput::class);
})->with([
    'blank title' => ['   ', null],
    'long title' => [str_repeat('a', 201), null],
    'control title' => ["A\x07title", null],
    'long summary' => ['Fine', str_repeat('a', 301)],
    'multi-line summary' => ['Fine', "two\nlines"],
]);

it('treats a blank summary as no summary, and never derives one from a Card (a Pack summary is hand-written)', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack', summary: '   ');
    Resources::publishedCard($by, $pack, 'A card', 'Words that must not become the pack summary');

    expect(app(GetManagedPack::class)($by, $pack->pack->id)->pack->summary)->toBeNull();
});

it('edits the authored fields and advances the revision, recording who last edited', function () {
    $first = Resources::editor('first.editor@example.org', 'First Editor');
    $second = Resources::editor('second.editor@example.org', 'Second Editor');
    $pack = Resources::pack($first, 'Old title');

    $edited = app(UpdatePack::class)($second, $pack->pack->id, 1, ['title' => 'New title', 'summary' => 'A summary', 'is_series' => true]);

    expect($edited->pack->title)->toBe('New title')
        ->and($edited->pack->summary)->toBe('A summary')
        ->and($edited->pack->isSeries)->toBeTrue()
        ->and($edited->pack->revision)->toBe(2)
        // Resources are organizational content: the creator keeps provenance, not authority.
        ->and($edited->createdBy->displayName)->toBe('First Editor')
        ->and($edited->updatedBy->displayName)->toBe('Second Editor');
});

it('changes only the keys it is sent, and clears a summary on null', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Keep', summary: 'Gone soon', series: true);

    $edited = app(UpdatePack::class)($by, $pack->pack->id, 1, ['summary' => null]);

    expect($edited->pack->title)->toBe('Keep')->and($edited->pack->summary)->toBeNull()->and($edited->pack->isSeries)->toBeTrue();
});

it('refuses an edit based on a stale revision, shows the current Pack, and writes nothing', function () {
    $first = Resources::editor('first.editor@example.org', 'First Editor');
    $second = Resources::editor('second.editor@example.org', 'Second Editor');
    $pack = Resources::pack($first, 'Original');
    app(UpdatePack::class)($first, $pack->pack->id, 1, ['title' => 'First editor won']);

    try {
        app(UpdatePack::class)($second, $pack->pack->id, 1, ['title' => 'Second editor lost']);
        $stale = null;
    } catch (StaleRevision $e) {
        $stale = $e;
    }

    expect($stale)->toBeInstanceOf(StaleRevision::class);
    assert($stale instanceof StaleRevision);
    $current = $stale->current;
    assert($current instanceof ManagedPackView);
    expect($current->pack->title)->toBe('First editor won')
        ->and($current->pack->revision)->toBe(2)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('title'))->toBe('First editor won');
});

it('does not move the revision for publication, audiences or ordering: only the authored fields do', function () {
    $by = Resources::editor();
    $published = Resources::published($by, [Audience::Guardian], 1, 'Live');

    expect($published->pack->revision)->toBe(1);
    app(SetPackAudiences::class)($by, $published->pack->id, [Audience::Guardian, Audience::Member]);
    app(UnpublishPack::class)($by, $published->pack->id);
    app(PublishPack::class)($by, $published->pack->id);

    expect(app(GetManagedPack::class)($by, $published->pack->id)->pack->revision)->toBe(1);
});

it('moves a Pack to the end of another Category, and clears the Category of a Draft', function () {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;
    $pack = Resources::pack($by, 'Mover', $a);
    Resources::pack($by, 'Resident', $b);

    $moved = app(UpdatePack::class)($by, $pack->pack->id, 1, ['category_id' => $b->value]);
    expect($moved->category?->name)->toBe('B')->and(packTitlesInCategory($b))->toBe(['Resident', 'Mover'])->and($moved->pack->revision)->toBe(2);

    $cleared = app(UpdatePack::class)($by, $pack->pack->id, 2, ['category_id' => null]);
    expect($cleared->category)->toBeNull()->and($cleared->pack->revision)->toBe(3);
});

it('refuses to move a Pack into a Category that does not exist, leaving it where it was', function () {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $pack = Resources::pack($by, 'Stays', $a);

    expect(fn () => app(UpdatePack::class)($by, $pack->pack->id, 1, ['category_id' => CategoryId::generate()->value]))->toThrow(UnknownCategory::class)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('category_id'))->toBe($a->value)
        ->and(Resources::int(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('revision')))->toBe(1);
});

it('names what a Draft lacks to be published, one by one and all together', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Bare');

    $unmet = function () use ($by, $pack): array {
        try {
            app(PublishPack::class)($by, $pack->pack->id);
        } catch (PackNotPublishable $e) {
            return $e->unmet;
        }

        return [];
    };

    expect($unmet())->toBe(['category', 'audience', 'published_card']);

    $category = Resources::category($by, 'Guides')->category->id;
    app(UpdatePack::class)($by, $pack->pack->id, 1, ['category_id' => $category->value]);
    expect($unmet())->toBe(['audience', 'published_card']);

    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
    expect($unmet())->toBe(['published_card']);

    // A DRAFT Card does not count: it must be Published.
    $card = Resources::card($by, $pack, 'Draft card');
    expect($unmet())->toBe(['published_card']);

    app(PublishCard::class)($by, $pack->pack->id, $card->card->id);
    expect($unmet())->toBe([])
        ->and(app(GetManagedPack::class)($by, $pack->pack->id)->pack->state)->toBe(PublicationState::Published);
});

it('publishes idempotently, and unpublishes reversibly without deleting or changing anything else', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Live');

    expect(app(PublishPack::class)($by, $live->pack->id)->pack->state)->toBe(PublicationState::Published);

    $draft = app(UnpublishPack::class)($by, $live->pack->id);
    expect($draft->pack->state)->toBe(PublicationState::Draft)
        ->and($draft->cardCount)->toBe(2)
        ->and($draft->publishedCardCount)->toBe(2) // Cards keep their own state; none is delivered while the Pack is a Draft
        ->and($draft->pack->audiences->values())->toBe(['guardian'])
        ->and(app(UnpublishPack::class)($by, $live->pack->id)->pack->state)->toBe(PublicationState::Draft); // idempotent

    expect(app(PublishPack::class)($by, $live->pack->id)->pack->state)->toBe(PublicationState::Published);
});

it('keeps a Published Pack publishable: it refuses to clear its last audience', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 1, 'Live');

    expect(fn () => app(SetPackAudiences::class)($by, $live->pack->id, []))->toThrow(PublishedPackRequirement::class);
    try {
        app(SetPackAudiences::class)($by, $live->pack->id, []);
    } catch (PublishedPackRequirement $e) {
        expect($e->requirement)->toBe('audience');
    }

    expect(app(GetManagedPack::class)($by, $live->pack->id)->pack->audiences->values())->toBe(['guardian'])
        // Changing the set to another non-empty one is fine; so is clearing it on a Draft.
        ->and(app(SetPackAudiences::class)($by, $live->pack->id, [Audience::Member])->pack->audiences->values())->toBe(['member']);

    app(UnpublishPack::class)($by, $live->pack->id);
    expect(app(SetPackAudiences::class)($by, $live->pack->id, [])->pack->audiences->isEmpty())->toBeTrue();
});

it('keeps a Published Pack publishable: it refuses to clear its Category', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 1, 'Live');

    try {
        app(UpdatePack::class)($by, $live->pack->id, 1, ['category_id' => null]);
        $refused = null;
    } catch (PublishedPackRequirement $e) {
        $refused = $e;
    }

    expect($refused?->requirement)->toBe('category')
        ->and(app(GetManagedPack::class)($by, $live->pack->id)->category)->not->toBeNull()
        ->and(app(GetManagedPack::class)($by, $live->pack->id)->pack->revision)->toBe(1);
});

it('keeps a Published Pack publishable: it refuses to unpublish or delete its LAST Published Card, and never unpublishes the Pack silently', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 1, 'Live');
    $only = Resources::outline($live, 0)->card->id;

    foreach ([
        fn () => app(UnpublishCard::class)($by, $live->pack->id, $only),
        fn () => app(DeleteCard::class)($by, $live->pack->id, $only),
    ] as $attempt) {
        try {
            $attempt();
            $requirement = null;
        } catch (PublishedPackRequirement $e) {
            $requirement = $e->requirement;
        }
        expect($requirement)->toBe('published_card');
    }

    $after = app(GetManagedPack::class)($by, $live->pack->id);
    expect($after->pack->state)->toBe(PublicationState::Published)->and($after->publishedCardCount)->toBe(1)->and($after->cardCount)->toBe(1);
});

it('lets a Published Pack lose a Published Card while another remains, and a DRAFT Card go any time', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Live');
    $draft = Resources::card($by, $live, 'Spare draft');

    app(UnpublishCard::class)($by, $live->pack->id, Resources::outline($live, 0)->card->id);
    app(DeleteCard::class)($by, $live->pack->id, $draft->card->id);

    $after = app(GetManagedPack::class)($by, $live->pack->id);
    expect($after->pack->state)->toBe(PublicationState::Published)->and($after->publishedCardCount)->toBe(1)->and($after->cardCount)->toBe(2);
});

it('sets a Pack\'s audiences as a whole, allows none for a Draft, and has no duplicate, deny or per-Person form', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Targeted');

    expect(app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Member, Audience::Guardian, Audience::Member])->pack->audiences->values())->toBe(['guardian', 'member'])
        ->and(app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian])->pack->audiences->values())->toBe(['guardian'])
        ->and(app(SetPackAudiences::class)($by, $pack->pack->id, [])->pack->audiences->values())->toBe([])
        ->and(DB::table('resource_pack_audiences')->where('pack_id', $pack->pack->id->value)->count())->toBe(0);
});

it('refuses to reduce a Pack\'s audiences below a narrowed Card, naming the Cards, and adjusts nothing', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Wide');
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $narrowed = Resources::card($by, $pack, 'Both-narrowed');
    app(SetCardAudiences::class)($by, $pack->pack->id, $narrowed->card->id, AudienceMode::Narrowed, [Audience::Guardian, Audience::Member]);
    $guardianOnly = Resources::card($by, $pack, 'Guardians only');
    app(SetCardAudiences::class)($by, $pack->pack->id, $guardianOnly->card->id, AudienceMode::Narrowed, [Audience::Guardian]);
    Resources::card($by, $pack, 'Inheriting');

    try {
        app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
        $conflict = null;
    } catch (CardAudienceConflict $e) {
        $conflict = $e;
    }

    expect($conflict)->toBeInstanceOf(CardAudienceConflict::class);
    assert($conflict instanceof CardAudienceConflict);
    // Only the Card that would be wider than its Pack is named; the Guardian-only one and the inheriting one are fine.
    expect(array_map(fn ($id): string => $id->value, $conflict->cards))->toBe([$narrowed->card->id->value])
        ->and(app(GetManagedPack::class)($by, $pack->pack->id)->pack->audiences->values())->toBe(['guardian', 'member'])
        ->and(DB::table('resource_card_audiences')->where('card_id', $narrowed->card->id->value)->count())->toBe(2);

    // Narrowing the Pack to a set that still contains every narrowed Card's is fine.
    app(SetCardAudiences::class)($by, $pack->pack->id, $narrowed->card->id, AudienceMode::Narrowed, [Audience::Guardian]);
    expect(app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian])->pack->audiences->values())->toBe(['guardian']);
});

it('answers pack_not_found for an unknown Pack from every Pack operation', function () {
    $by = Resources::editor();
    $missing = PackId::generate();

    foreach ([
        fn () => app(GetManagedPack::class)($by, $missing),
        fn () => app(UpdatePack::class)($by, $missing, 1, ['title' => 'x']),
        fn () => app(SetPackAudiences::class)($by, $missing, [Audience::Guardian]),
        fn () => app(PublishPack::class)($by, $missing),
        fn () => app(UnpublishPack::class)($by, $missing),
    ] as $operation) {
        expect($operation)->toThrow(PackNotFound::class);
    }
});

it('reorders the Packs of one Category from the complete list, and refuses anything else', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Guides')->category->id;
    $other = Resources::category($by, 'Other')->category->id;
    $a = Resources::pack($by, 'A', $category)->pack->id;
    $b = Resources::pack($by, 'B', $category)->pack->id;
    $c = Resources::pack($by, 'C', $category)->pack->id;
    $stranger = Resources::pack($by, 'Elsewhere', $other)->pack->id;

    app(ReorderPacks::class)($by, $category, [$c, $a, $b]);
    expect(packTitlesInCategory($category))->toBe(['C', 'A', 'B']);

    // A Pack of another Category is not a sibling; a missing or repeated one is not the set.
    foreach ([[$c, $a, $b, $stranger], [$c, $a], [$a, $a, $b], [$c, $a, $b, $b]] as $bad) {
        expect(fn () => app(ReorderPacks::class)($by, $category, $bad))->toThrow(OrderMismatch::class);
    }
    expect(packTitlesInCategory($category))->toBe(['C', 'A', 'B'])
        ->and(packTitlesInCategory($other))->toBe(['Elsewhere']);
});

it('lists Packs for management in Category order then Pack order, Packs with no Category last, and Drafts included', function () {
    $by = Resources::editor();
    $first = Resources::category($by, 'First')->category->id;
    $second = Resources::category($by, 'Second')->category->id;
    Resources::pack($by, 'Loose draft');
    Resources::pack($by, 'Second one', $second);
    Resources::pack($by, 'First two', $first);
    Resources::pack($by, 'First one', $first);

    $titles = array_map(fn (ManagedPackView $p): string => $p->pack->title, app(PageManagedPacks::class)($by, new ManagedPackFilter, 1, 25)->packs);

    expect($titles)->toBe(['First two', 'First one', 'Second one', 'Loose draft']);
});

it('filters the management list by Category, audience, state, Card Type and title text, and pages it', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 1, 'Opening checklist');
    $members = Resources::published($by, [Audience::Member], 1, 'Member welcome');
    $draft = Resources::pack($by, 'Draft about opening');
    Resources::card($by, $draft, 'A link', 'text', CardType::ExternalLink, 'https://example.org/x');
    $page = fn (ManagedPackFilter $f) => app(PageManagedPacks::class)($by, $f, 1, 25);
    $titles = fn (ManagedPackFilter $f): array => collect($page($f)->packs)->map(fn (ManagedPackView $p) => $p->pack->title)->sort()->values()->all();

    expect($titles(new ManagedPackFilter(state: PublicationState::Published)))->toBe(['Member welcome', 'Opening checklist'])
        ->and($titles(new ManagedPackFilter(state: PublicationState::Draft)))->toBe(['Draft about opening'])
        ->and($titles(new ManagedPackFilter(audience: Audience::Member)))->toBe(['Member welcome'])
        ->and($titles(new ManagedPackFilter(audience: Audience::Guardian)))->toBe(['Opening checklist'])
        ->and($titles(new ManagedPackFilter(cardType: CardType::ExternalLink)))->toBe(['Draft about opening'])
        ->and($titles(new ManagedPackFilter(cardType: CardType::Basic)))->toBe(['Member welcome', 'Opening checklist'])
        ->and($titles(new ManagedPackFilter(titleContains: 'OPENING')))->toBe(['Draft about opening', 'Opening checklist'])
        ->and($titles(new ManagedPackFilter(category: $live->pack->categoryId)))->toBe(['Opening checklist'])
        ->and($titles(new ManagedPackFilter(state: PublicationState::Published, audience: Audience::Member, titleContains: 'welcome')))->toBe(['Member welcome'])
        ->and($titles(new ManagedPackFilter(titleContains: '%')))->toBe([]) // a wildcard is a literal
        ->and($titles(new ManagedPackFilter(titleContains: '_')))->toBe([]);

    $paged = app(PageManagedPacks::class)($by, new ManagedPackFilter, 2, 2);
    expect($paged->total)->toBe(3)->and($paged->lastPage())->toBe(2)->and(count($paged->packs))->toBe(1);

    // per_page is bounded.
    expect(app(PageManagedPacks::class)($by, new ManagedPackFilter, 1, 5000)->perPage)->toBe(100);
    unset($members);
});

it('stores the Series flag as presentation only: a Pack has no completion, prerequisite, lock or progress field', function () {
    expect(Schema::getColumnListing('resource_packs'))->toBe([
        'id', 'category_id', 'position', 'title', 'summary', 'is_series', 'state', 'revision',
        'created_by_person_id', 'updated_by_person_id', 'created_at', 'updated_at',
    ]);
});

it('gives the management view of one Pack every Card, Drafts included, in stored order, without content', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    Resources::card($by, $pack, 'Draft one');
    Resources::publishedCard($by, $pack, 'Live two');

    $view = app(GetManagedPack::class)($by, $pack->pack->id);

    expect(array_map(fn ($c): string => $c->card->title, $view->cards ?? []))->toBe(['Draft one', 'Live two'])
        ->and($view->cardCount)->toBe(2)
        ->and($view->publishedCardCount)->toBe(1);
});
