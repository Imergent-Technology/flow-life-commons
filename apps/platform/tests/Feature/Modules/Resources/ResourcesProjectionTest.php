<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Authorizer;
use App\Modules\Resources\Application\BrowseResourceLibrary;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\DeliveredPack;
use App\Modules\Resources\Application\GetResourcePack;
use App\Modules\Resources\Application\LibraryCategory;
use App\Modules\Resources\Application\PackPreview;
use App\Modules\Resources\Application\PreviewPack;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Application\ReorderPacks;
use App\Modules\Resources\Application\ResourcePackNotFound;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UnpublishPack;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Http\ResourcesPresenter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Api;
use Tests\Support\Membership;
use Tests\Support\Resources;

/*
 * What a viewer receives, and what they never learn exists (ADR 0037, decisions 45-50): ONE projection rule behind the library,
 * a single Pack, search and preview. An invisible Card is not there: not its id, title, summary, position, count or slot, and an
 * invisible Pack answers exactly what a missing one does. Runs on MariaDB and PostgreSQL.
 */

/** @return list<string> Pack titles in a library, in library order */
function libraryTitles(?string $search = null, ?CategoryId $category = null): array
{
    $titles = [];
    foreach (app(BrowseResourceLibrary::class)(Resources::editor('viewer.guardian@example.org', 'Viewer Guardian'), $category, $search) as $entry) {
        foreach ($entry->packs as $listed) {
            $titles[] = $listed->pack->title;
        }
    }

    return $titles;
}

/** @return list<string> the Card titles of a delivered Pack, in order */
function deliveredTitles(DeliveredPack $pack): array
{
    return array_map(fn ($c): string => $c->card->title, $pack->cards);
}

/** Everything a delivered Pack would put on the wire, as one string, for asserting what must NOT be in it. */
function deliveredText(DeliveredPack $pack): string
{
    return json_encode((new ResourcesPresenter)->delivered($pack), JSON_THROW_ON_ERROR);
}

it('shows a Guardian a Published Pack with a Published Card whose audience includes guardian, and nothing else', function () {
    $by = Resources::editor();
    Resources::published($by, [Audience::Guardian], 1, 'For guardians');
    Resources::published($by, [Audience::Member], 1, 'For members');
    Resources::published($by, [Audience::Guardian, Audience::Member], 1, 'For both');
    Resources::pack($by, 'Still a draft');

    expect(libraryTitles())->toBe(['For guardians', 'For both']);
});

it('never shows a Draft Pack, whatever its Cards say', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Was live');
    app(UnpublishPack::class)($by, $live->pack->id);

    expect(libraryTitles())->toBe([])
        ->and(fn () => app(GetResourcePack::class)($by, $live->pack->id))->toThrow(ResourcePackNotFound::class);
});

it('treats Pack audiences as positive OR: a viewer who satisfies any one qualifies', function () {
    $by = Resources::editor();
    $both = Resources::published($by, [Audience::Member, Audience::Guardian], 1, 'Both');

    expect(deliveredTitles(app(GetResourcePack::class)($by, $both->pack->id)))->toBe(['Both card 1']);
});

it('leaves an unpublished Card out of everything: delivery, counts, numbering and search', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 3, 'Trio');
    app(UnpublishCard::class)($by, $live->pack->id, Resources::outline($live, 1)->card->id);

    $delivered = app(GetResourcePack::class)($by, $live->pack->id);

    expect(deliveredTitles($delivered))->toBe(['Trio card 1', 'Trio card 3'])
        ->and(array_map(fn ($c): int => $c->index, $delivered->cards))->toBe([1, 2])
        ->and(deliveredText($delivered))->not->toContain('Trio card 2')->and(deliveredText($delivered))->not->toContain(Resources::outline($live, 1)->card->id->value)
        ->and(app(BrowseResourceLibrary::class)($by, null, null)[0]->packs[0]->visibleCardCount)->toBe(2)
        ->and(libraryTitles('card 2'))->toBe([]);
});

it('hides a Card the viewer\'s audience is not in, as if it did not exist: a five-Card Pack is a four-Card Pack', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Five stored', Resources::category($by, 'Guides')->category->id);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $cards = [];
    foreach (['Alpha', 'Bravo', 'Secret Charlie', 'Delta', 'Echo'] as $title) {
        $cards[$title] = Resources::publishedCard($by, $pack, $title, "Words of {$title}")->card;
    }
    // Charlie is for Members only; the others inherit and so are for both.
    app(SetCardAudiences::class)($by, $pack->pack->id, $cards['Secret Charlie']->id, AudienceMode::Narrowed, [Audience::Member]);
    app(PublishPack::class)($by, $pack->pack->id);

    $guardian = app(GetResourcePack::class)($by, $pack->pack->id);

    expect($guardian->cards)->toHaveCount(4)
        ->and(deliveredTitles($guardian))->toBe(['Alpha', 'Bravo', 'Delta', 'Echo'])
        // Numbered 1..4 among themselves: there is no gap where the hidden Card was, and no sign it exists.
        ->and(array_map(fn ($c): int => $c->index, $guardian->cards))->toBe([1, 2, 3, 4])
        ->and(app(BrowseResourceLibrary::class)($by, null, null)[0]->packs[0]->visibleCardCount)->toBe(4);

    $text = deliveredText($guardian);
    foreach (['Secret Charlie', 'Words of Secret Charlie', $cards['Secret Charlie']->id->value] as $leak) {
        expect($text)->not->toContain($leak);
    }
    // ...and what the stored position was never leaves management: the delivered Cards carry an index and nothing like `position`.
    expect($text)->not->toContain('position')->and($text)->not->toContain('"count":5')->and(Api::map(json_decode($text, true))['card_count'])->toBe(4);

    // The Member audience, as a manager previews it, sees Charlie and the rest: five.
    $member = app(PreviewPack::class)($by, $pack->pack->id, Audience::Member);
    expect($member->pack?->cards)->toHaveCount(5);
});

it('lets a narrowed Card be seen only by the audiences it was narrowed to, within the Pack\'s own', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Mixed', Resources::category($by, 'Guides')->category->id);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $inherits = Resources::publishedCard($by, $pack, 'For both')->card;
    $guardiansOnly = Resources::publishedCard($by, $pack, 'Guardians only')->card;
    app(SetCardAudiences::class)($by, $pack->pack->id, $guardiansOnly->id, AudienceMode::Narrowed, [Audience::Guardian]);
    app(PublishPack::class)($by, $pack->pack->id);

    $titles = fn (Audience $a): array => array_map(fn ($c): string => $c->card->title, app(PreviewPack::class)($by, $pack->pack->id, $a)->pack->cards ?? []);

    // Member sees A; Guardian sees A and B (ADR 0037's worked example).
    expect($titles(Audience::Member))->toBe(['For both'])
        ->and($titles(Audience::Guardian))->toBe(['For both', 'Guardians only'])
        ->and($inherits->id->value)->not->toBe('');
});

it('omits a Pack entirely when no Card remains visible to the viewer, and answers it as it would a missing one', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Members inside', Resources::category($by, 'Guides')->category->id);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $card = Resources::publishedCard($by, $pack, 'Only member card')->card;
    app(SetCardAudiences::class)($by, $pack->pack->id, $card->id, AudienceMode::Narrowed, [Audience::Member]);
    app(PublishPack::class)($by, $pack->pack->id);

    // The Pack qualifies for a Guardian by its own audiences, but every Card is for Members: nothing remains.
    expect(libraryTitles())->toBe([])
        ->and(app(BrowseResourceLibrary::class)($by, null, null))->toBe([])
        ->and(fn () => app(GetResourcePack::class)($by, $pack->pack->id))->toThrow(ResourcePackNotFound::class);
});

it('answers every reason a Pack is not delivered with the SAME refusal: nothing says missing, draft, unpublished, not for you or empty', function () {
    $by = Resources::editor();
    $draft = Resources::pack($by, 'Draft');
    Resources::publishedCard($by, $draft, 'Card');
    $unpublished = Resources::published($by, [Audience::Guardian], 2, 'Unpublished');
    app(UnpublishPack::class)($by, $unpublished->pack->id);
    $memberOnly = Resources::published($by, [Audience::Member], 1, 'Member only');
    $emptyAfterProjection = Resources::pack($by, 'Narrowed away', Resources::category($by, 'C')->category->id);
    app(SetPackAudiences::class)($by, $emptyAfterProjection->pack->id, [Audience::Guardian, Audience::Member]);
    $only = Resources::publishedCard($by, $emptyAfterProjection, 'Hidden')->card;
    app(SetCardAudiences::class)($by, $emptyAfterProjection->pack->id, $only->id, AudienceMode::Narrowed, [Audience::Member]);
    app(PublishPack::class)($by, $emptyAfterProjection->pack->id);
    $allCardsDraft = Resources::pack($by, 'Only drafts');
    Resources::card($by, $allCardsDraft, 'Draft card');

    $refusals = [];
    foreach ([PackId::generate(), $draft->pack->id, $unpublished->pack->id, $memberOnly->pack->id, $emptyAfterProjection->pack->id, $allCardsDraft->pack->id] as $id) {
        try {
            app(GetResourcePack::class)($by, $id);
            $refusals[] = null;
        } catch (ResourcePackNotFound $e) {
            $refusals[] = [$e::class, $e->getMessage(), $e->getCode()];
        }
    }

    expect($refusals)->each->not->toBeNull()
        ->and(count(array_unique(array_map('serialize', $refusals))))->toBe(1);
});

it('lists Categories in order with only the Packs the viewer may see, and omits a Category with none', function () {
    $by = Resources::editor();
    $first = Resources::category($by, 'First')->category->id;
    $second = Resources::category($by, 'Second')->category->id;
    $third = Resources::category($by, 'Third (members only)')->category->id;
    Resources::published($by, [Audience::Guardian], 1, 'Second pack', $second);
    Resources::published($by, [Audience::Guardian], 1, 'First pack', $first);
    Resources::published($by, [Audience::Member], 1, 'Members only pack', $third);

    $library = app(BrowseResourceLibrary::class)($by, null, null);

    expect(array_map(fn (LibraryCategory $c): string => $c->category->name, $library))->toBe(['First', 'Second'])
        ->and(libraryTitles(category: $second))->toBe(['Second pack'])
        ->and(libraryTitles(category: $third))->toBe([])        // a Category with nothing visible is simply empty
        ->and(libraryTitles(category: CategoryId::generate()))->toBe([]);
});

it('orders the library by Category order, then Pack order', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Guides')->category->id;
    $a = Resources::published($by, [Audience::Guardian], 1, 'A', $category);
    $b = Resources::published($by, [Audience::Guardian], 1, 'B', $category);
    $c = Resources::published($by, [Audience::Guardian], 1, 'C', $category);
    app(ReorderPacks::class)($by, $category, [$c->pack->id, $a->pack->id, $b->pack->id]);

    expect(libraryTitles())->toBe(['C', 'A', 'B']);
});

it('searches a Pack\'s title and summary and its visible Cards\' titles and summaries, case-insensitively, returning Packs', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Guides')->category->id;
    $titled = Resources::published($by, [Audience::Guardian], 1, 'Volunteer onboarding', $category);
    $summarised = Resources::pack($by, 'Plain title', $category, summary: 'Where the Ladder lives');
    Resources::publishedCard($by, $summarised, 'Card');
    app(SetPackAudiences::class)($by, $summarised->pack->id, [Audience::Guardian]);
    app(PublishPack::class)($by, $summarised->pack->id);
    $cardTitled = Resources::pack($by, 'Other', $category);
    Resources::publishedCard($by, $cardTitled, 'Fire extinguisher locations', 'x');
    app(SetPackAudiences::class)($by, $cardTitled->pack->id, [Audience::Guardian]);
    app(PublishPack::class)($by, $cardTitled->pack->id);
    $cardSummarised = Resources::pack($by, 'Another', $category);
    app(PublishCard::class)($by, $cardSummarised->pack->id, Resources::card($by, $cardSummarised, 'Plain', 'Closing procedure steps')->card->id);
    app(SetPackAudiences::class)($by, $cardSummarised->pack->id, [Audience::Guardian]);
    app(PublishPack::class)($by, $cardSummarised->pack->id);

    expect(libraryTitles('ONBOARDING'))->toBe(['Volunteer onboarding'])
        ->and(libraryTitles('ladder'))->toBe(['Plain title'])
        ->and(libraryTitles('EXTINGUISHER'))->toBe(['Other'])
        ->and(libraryTitles('closing procedure'))->toBe(['Another'])
        ->and(libraryTitles('no such thing'))->toBe([])
        ->and(libraryTitles('  '))->toHaveCount(4)       // blank search is no search
        ->and(libraryTitles(null))->toHaveCount(4)
        ->and($titled->pack->title)->toBe('Volunteer onboarding');
});

it('folds Unicode case in search the same way on every engine', function () {
    $by = Resources::editor();
    Resources::published($by, [Audience::Guardian], 1, 'Café Étoile');

    expect(libraryTitles('café'))->toBe(['Café Étoile'])
        ->and(libraryTitles('ÉTOILE'))->toBe(['Café Étoile'])
        ->and(libraryTitles('étoile'))->toBe(['Café Étoile']);
});

it('takes % and _ in a search literally, as text', function () {
    $by = Resources::editor();
    Resources::published($by, [Audience::Guardian], 1, '100% volunteer_ready');
    Resources::published($by, [Audience::Guardian], 1, 'Plain pack');

    expect(libraryTitles('100%'))->toBe(['100% volunteer_ready'])
        ->and(libraryTitles('o_u'))->toBe([])      // as a wildcard `_` would match the "olu" of "volunteer"
        ->and(libraryTitles('%'))->toBe(['100% volunteer_ready'])
        ->and(libraryTitles('_'))->toBe(['100% volunteer_ready']);
});

it('does NOT search rich content: a word only in a Card\'s body matches nothing', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack title', Resources::category($by, 'Guides')->category->id);
    $card = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Card title', Resources::doc('The unmistakable kumquat protocol'), null, 'A short written summary');
    app(PublishCard::class)($by, $pack->pack->id, $card->card->id);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
    app(PublishPack::class)($by, $pack->pack->id);

    expect(libraryTitles('kumquat'))->toBe([])
        ->and(libraryTitles('written summary'))->toBe(['Pack title']);
});

it('lets a hidden or unpublished Card contribute NOTHING to a search: its words never make a Pack match', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Visible only', Resources::category($by, 'Guides')->category->id);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    Resources::publishedCard($by, $pack, 'Ordinary card', 'Ordinary words');
    $secret = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Zanzibar members card', Resources::doc('x'), null, 'Zanzibar summary');
    app(PublishCard::class)($by, $pack->pack->id, $secret->card->id);
    app(SetCardAudiences::class)($by, $pack->pack->id, $secret->card->id, AudienceMode::Narrowed, [Audience::Member]);
    $draft = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Quokka draft card', Resources::doc('x'), null, 'Quokka summary');
    app(PublishPack::class)($by, $pack->pack->id);

    expect(libraryTitles('Zanzibar'))->toBe([])   // narrowed away from guardians
        ->and(libraryTitles('Quokka'))->toBe([])  // a Draft Card
        ->and(libraryTitles('ordinary'))->toBe(['Visible only'])
        ->and($draft->card->state->value)->toBe('draft');

    // The same Pack is found by that word for the audience that CAN see the Card, which proves the data is there to be hidden.
    app(UnpublishCard::class)($by, $pack->pack->id, $secret->card->id);
    app(PublishCard::class)($by, $pack->pack->id, $secret->card->id);
    expect(libraryTitles('Zanzibar'))->toBe([]);
});

it('derives one Card or several from what the viewer can see, and stores no such mode', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Deck');

    expect(app(GetResourcePack::class)($by, $live->pack->id)->cards)->toHaveCount(2);
    app(UnpublishCard::class)($by, $live->pack->id, Resources::outline($live, 1)->card->id);
    expect(app(GetResourcePack::class)($by, $live->pack->id)->cards)->toHaveCount(1);

    expect(Schema::getColumnListing('resource_packs'))->not->toContain('mode', 'layout', 'single_card')
        ->and(Schema::getColumnListing('resource_cards'))->not->toContain('mode', 'layout', 'single_card', 'navigation');
});

it('delivers the Series flag as presentation only, with no completion, progress or lock anywhere', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Guides')->category->id;
    $series = Resources::pack($by, 'Series pack', $category, series: true);
    Resources::publishedCard($by, $series, 'One');
    Resources::publishedCard($by, $series, 'Two');
    app(SetPackAudiences::class)($by, $series->pack->id, [Audience::Guardian]);
    app(PublishPack::class)($by, $series->pack->id);

    $delivered = (new ResourcesPresenter)->delivered(app(GetResourcePack::class)($by, $series->pack->id));

    $cards = Api::rows($delivered['cards']);

    expect($delivered['is_series'])->toBeTrue()
        ->and(array_keys($delivered))->toBe(['id', 'title', 'summary', 'is_series', 'category', 'card_count', 'cards'])
        ->and(array_keys($cards[0]))->toBe(['id', 'index', 'type', 'title', 'summary', 'uri', 'content'])
        // Every visible Card is reachable directly: nothing is locked behind another.
        ->and(array_column($cards, 'index'))->toBe([1, 2]);
});

it('numbers the visible Cards by the stored order, which only a manager sets', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 3, 'Deck');
    $ids = array_map(fn ($c) => $c->card->id, Resources::outlines($live));
    app(ReorderCards::class)($by, $live->pack->id, [$ids[2], $ids[0], $ids[1]]);

    expect(deliveredTitles(app(GetResourcePack::class)($by, $live->pack->id)))->toBe(['Deck card 3', 'Deck card 1', 'Deck card 2']);
});

it('delivers no management or provenance data: no state, revision, audience, stored position or Person', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian, Audience::Member], 1, 'Deck');

    $text = json_encode((new ResourcesPresenter)->delivered(app(GetResourcePack::class)($by, $live->pack->id)), JSON_THROW_ON_ERROR);
    $library = json_encode((new ResourcesPresenter)->library(app(BrowseResourceLibrary::class)($by, null, null)), JSON_THROW_ON_ERROR);

    foreach (['"state"', 'revision', 'audience', 'position', 'created_by', 'updated_by', 'created_at', 'updated_at', 'display_name', 'person', $by->personId->value, $by->accountId->value, 'Ed Editor', 'guardian', 'member'] as $management) {
        expect($text)->not->toContain($management)->and($library)->not->toContain($management);
    }
});

it('previews a Draft Pack as an audience through the same projection, and changes nothing', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Not live yet', Resources::category($by, 'Guides')->category->id);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $both = Resources::publishedCard($by, $pack, 'For both')->card;
    $draftCard = Resources::card($by, $pack, 'Still a draft card');
    $memberOnly = Resources::publishedCard($by, $pack, 'Members only')->card;
    app(SetCardAudiences::class)($by, $pack->pack->id, $memberOnly->id, AudienceMode::Narrowed, [Audience::Member]);
    $before = [DB::table('resource_packs')->get()->all(), DB::table('resource_cards')->orderBy('id')->get()->all()];

    $guardian = app(PreviewPack::class)($by, $pack->pack->id, Audience::Guardian);
    $member = app(PreviewPack::class)($by, $pack->pack->id, Audience::Member);

    expect($guardian)->toBeInstanceOf(PackPreview::class)
        ->and($guardian->packState->value)->toBe('draft')
        ->and($guardian->audienceTargeted)->toBeTrue()
        ->and($guardian->visible())->toBeTrue()
        ->and(deliveredTitles($guardian->pack ?? throw new LogicException))->toBe(['For both'])
        ->and(deliveredTitles($member->pack ?? throw new LogicException))->toBe(['For both', 'Members only'])
        // A Draft Card is not previewed here (the editor's own preview shows it): Card publication is delivery's rule too.
        ->and(deliveredText($member->pack))->not->toContain('Still a draft card')->and($draftCard->card->title)->toBe('Still a draft card');

    // Preview is not publication: nothing was written, and the Pack is still a Draft the library does not show.
    expect([DB::table('resource_packs')->get()->all(), DB::table('resource_cards')->orderBy('id')->get()->all()])->toEqual($before)
        ->and(libraryTitles())->toBe([]);
});

it('previews exactly what delivery would deliver once the Pack is published, for the Guardian audience', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian, Audience::Member], 3, 'Deck');
    app(UnpublishCard::class)($by, $live->pack->id, Resources::outline($live, 2)->card->id);

    $delivered = (new ResourcesPresenter)->delivered(app(GetResourcePack::class)($by, $live->pack->id));
    $previewed = (new ResourcesPresenter)->delivered(app(PreviewPack::class)($by, $live->pack->id, Audience::Guardian)->pack ?? throw new LogicException);

    // Compared as the JSON a client receives: the same bytes, not merely equal-looking objects.
    expect(json_encode($previewed, JSON_THROW_ON_ERROR))->toBe(json_encode($delivered, JSON_THROW_ON_ERROR));
});

it('reports a preview that would show nothing as such, not as a missing Pack, with whether the audience is on the Pack', function () {
    $by = Resources::editor();
    $guardiansOnly = Resources::published($by, [Audience::Guardian], 1, 'Guardians only');

    $preview = app(PreviewPack::class)($by, $guardiansOnly->pack->id, Audience::Member);

    expect($preview->visible())->toBeFalse()->and($preview->pack)->toBeNull()
        ->and($preview->audienceTargeted)->toBeFalse()
        ->and($preview->packState->value)->toBe('published');
});

it('needs resources.manage to preview and resources.view to deliver, each asked inside the use case', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 1, 'Deck');
    $outsider = Resources::outsider();

    foreach ([
        fn () => app(PreviewPack::class)($outsider, $live->pack->id, Audience::Guardian),
        fn () => app(GetResourcePack::class)($outsider, $live->pack->id),
        fn () => app(BrowseResourceLibrary::class)($outsider, null, null),
    ] as $operation) {
        expect($operation)->toThrow(AccessDenied::class);
    }
});

it('does not let being a Member satisfy anything: an active Membership grants no Resources capability, role or audience of its own', function () {
    $by = Resources::editor();
    Resources::published($by, [Audience::Member], 1, 'For members');
    $member = Resources::outsider('mia.member@example.org', 'Mia Member');
    Membership::savedGrant($member->personId);

    expect(app(Authorizer::class)->capabilitiesOf($member))->toBe([])
        ->and(fn () => app(BrowseResourceLibrary::class)($member, null, null))->toThrow(AccessDenied::class);
});

it('serves the Guardian library only the guardian audience, never member content, even to a Guardian who is also a Member', function () {
    $by = Resources::editor();
    Resources::published($by, [Audience::Member], 1, 'Members only');
    Membership::savedGrant($by->personId); // the viewer is a Guardian AND an active Member

    expect(libraryTitles())->toBe([])
        ->and(Resources::published($by, [Audience::Guardian], 1, 'Guardian pack')->pack->title)->toBe('Guardian pack')
        ->and(libraryTitles())->toBe(['Guardian pack']);
});

it('reads a Card\'s content only when the Card is visible: a hidden Card\'s document is never loaded for delivery', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Deck');
    app(UnpublishCard::class)($by, $live->pack->id, Resources::outline($live, 1)->card->id);
    $reads = [];
    // Whatever loads a Card WITH its content is a `select *` (the outline queries name their columns and omit `content_document`).
    DB::listen(function (QueryExecuted $query) use (&$reads) {
        if (str_contains($query->sql, 'select *') && str_contains($query->sql, 'resource_cards')) {
            $reads[] = $query->bindings;
        }
    });

    app(GetResourcePack::class)($by, $live->pack->id);

    $hidden = Resources::outline($live, 1)->card->id->value;
    expect(collect($reads)->flatten()->contains($hidden))->toBeFalse()
        ->and(collect($reads)->flatten()->contains(Resources::outline($live, 0)->card->id->value))->toBeTrue(); // positive control: the visible Card's content WAS read
});

it('keeps a Card\'s edit and publication state from affecting other Cards\' numbering in a delivered Pack', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Deck');
    $draft = Resources::card($by, $live, 'Draft between');
    app(UpdateCard::class)($by, $live->pack->id, $draft->card->id, 1, ['title' => 'Still draft']);

    expect(array_map(fn ($c): int => $c->index, app(GetResourcePack::class)($by, $live->pack->id)->cards))->toBe([1, 2]);
});
