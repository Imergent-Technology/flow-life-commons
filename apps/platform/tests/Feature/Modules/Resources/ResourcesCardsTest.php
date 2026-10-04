<?php

declare(strict_types=1);

use App\Modules\Resources\Application\CardLimitReached;
use App\Modules\Resources\Application\CardNotFound;
use App\Modules\Resources\Application\CardNotPublishable;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\GetManagedCard;
use App\Modules\Resources\Application\GetManagedPack;
use App\Modules\Resources\Application\OrderMismatch;
use App\Modules\Resources\Application\PackNotFound;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\StaleRevision;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PublicationState;
use App\Modules\Resources\Domain\SummaryMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Resources;

/*
 * Cards (ADR 0037, decisions 17-23 and 34-37): a complete resource in itself, whose Type is fixed at creation, whose content
 * is a validated document, and whose summary is derived or written. Runs on MariaDB and PostgreSQL.
 */

it('creates a Draft basic Card: its content is the resource, it needs no address, and it inherits its Pack\'s audiences', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, '  A recipe  ', 'Mix the flour and the water.');

    expect($card->card->type)->toBe(CardType::Basic)
        ->and($card->card->title)->toBe('A recipe')
        ->and($card->card->state)->toBe(PublicationState::Draft)
        ->and($card->card->externalUri)->toBeNull()
        ->and($card->card->audience->mode)->toBe(AudienceMode::Inherit)
        ->and($card->card->revision)->toBe(1)
        ->and($card->card->summary->mode)->toBe(SummaryMode::Derived)
        ->and($card->card->summary->text)->toBe('Mix the flour and the water.')
        ->and($card->card->content->format)->toBe('prosemirror')
        ->and($card->card->content->version)->toBe(1)
        ->and($card->createdBy->displayName)->toBe('Ed Editor');
});

it('creates an external link Card with a required address, and validates it', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);

    $card = Resources::card($by, $pack, 'Venue map', 'Where to park.', CardType::ExternalLink, '  HTTPS://Example.org/map?x=1  ');
    expect($card->card->type)->toBe(CardType::ExternalLink)->and($card->card->externalUri)->toBe('https://Example.org/map?x=1');

    foreach ([null, '', '   ', 'javascript:alert(1)', 'data:text/html,x', '/relative', 'ftp://example.org', 'https://user:pw@example.org', 'mailto:a@example.org'] as $bad) {
        try {
            Resources::card($by, $pack, 'Bad link', 'x', CardType::ExternalLink, $bad);
            $refused = null;
        } catch (InvalidResourceInput $e) {
            $refused = $e;
        }
        expect($refused?->problem)->toBe('invalid_uri', 'uri: '.var_export($bad, true));
    }
    expect(DB::table('resource_cards')->count())->toBe(1);
});

it('lets a basic Card carry an optional related link, and refuses an unsafe one', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);

    expect(Resources::card($by, $pack, 'With link', 'x', CardType::Basic, 'https://example.org/related')->card->externalUri)->toBe('https://example.org/related')
        ->and(Resources::card($by, $pack, 'Blank link', 'x', CardType::Basic, '   ')->card->externalUri)->toBeNull()
        ->and(fn () => Resources::card($by, $pack, 'Bad', 'x', CardType::Basic, 'javascript:alert(1)'))->toThrow(InvalidResourceInput::class);
});

it('never fetches an address: no HTTP client is touched when one is stored', function () {
    Http::fake();
    $by = Resources::editor();
    Resources::card($by, Resources::pack($by), 'Link', 'x', CardType::ExternalLink, 'https://example.org/slow-or-hostile');

    Http::assertNothingSent();
});

it('validates content against the profile and refuses what is outside it, storing nothing', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);

    foreach ([
        ['type' => 'doc', 'content' => [['type' => 'script', 'content' => [['type' => 'text', 'text' => 'x']]]]],
        ['type' => 'doc', 'content' => '<p>html</p>'],
        ['type' => 'paragraph'],
    ] as $bad) {
        try {
            app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Bad', $bad, null, null);
            $refused = null;
        } catch (InvalidResourceInput $e) {
            $refused = $e;
        }
        expect($refused?->problem)->toBe('invalid_content');
    }
    expect(DB::table('resource_cards')->count())->toBe(0);
});

it('stores content in canonical form with no HTML, and takes an absent document as an empty one', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);

    $card = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Empty', null, null, null);
    expect($card->card->content->json)->toBe('{"type":"doc","content":[]}');

    $stored = Resources::card($by, $pack, 'Words', 'Plain <b>text</b> & more');
    expect(DB::table('resource_cards')->where('id', $stored->card->id->value)->value('content_document'))
        ->toBe('{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Plain <b>text</b> & more"}]}]}')
        // Text is text: nothing was escaped, converted or turned into markup.
        ->and(DB::table('resource_cards')->where('id', $stored->card->id->value)->value('content_format'))->toBe('prosemirror');
});

it('derives the summary from the content and keeps it in step as the content changes', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, 'Card', 'First version of the words');

    $edited = app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['content' => Resources::doc('Second', 'version  of the   words')]);

    expect($edited->card->summary->mode)->toBe(SummaryMode::Derived)
        ->and($edited->card->summary->text)->toBe('Second version of the words')
        ->and($edited->card->revision)->toBe(2);
});

it('truncates a derived summary at the configured length, at a word boundary, with an ellipsis', function () {
    config(['resources.summary.derived_length' => 40]);
    $by = Resources::editor();
    $card = Resources::card($by, Resources::pack($by), 'Long', 'The quick brown fox jumps over the lazy dog and then keeps on running far away');

    expect($card->card->summary->text)->toBe('The quick brown fox jumps over the lazy…')
        ->and(mb_strlen($card->card->summary->text))->toBeLessThanOrEqual(41);
});

it('lets a written summary stand: content changes never overwrite a custom summary', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, 'Card', 'Original words', summary: 'Written by hand');

    expect($card->card->summary->mode)->toBe(SummaryMode::Custom)->and($card->card->summary->text)->toBe('Written by hand');

    $edited = app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['content' => Resources::doc('Entirely new words')]);

    expect($edited->card->summary->mode)->toBe(SummaryMode::Custom)->and($edited->card->summary->text)->toBe('Written by hand');
});

it('returns a custom summary to automatic on request, recomputing at once, and can switch automatic to custom keeping the text', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, 'Card', 'Derived words', summary: 'Custom words');

    $back = app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['summary_mode' => 'derived']);
    expect($back->card->summary->mode)->toBe(SummaryMode::Derived)->and($back->card->summary->text)->toBe('Derived words');

    // Switching to custom with no text keeps the stored text as the custom one, so a later content change leaves it.
    $custom = app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 2, ['summary_mode' => 'custom']);
    expect($custom->card->summary->mode)->toBe(SummaryMode::Custom)->and($custom->card->summary->text)->toBe('Derived words');
    $after = app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 3, ['content' => Resources::doc('Different')]);
    expect($after->card->summary->text)->toBe('Derived words');
});

it('refuses a blank, multi-line or over-long custom summary', function (string $summary) {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, 'Card', 'Words');

    expect(fn () => app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['summary' => $summary]))->toThrow(InvalidResourceInput::class);
    expect(Resources::int(DB::table('resource_cards')->where('id', $card->card->id->value)->value('revision')))->toBe(1);
})->with(['blank' => '   ', 'multi-line' => "a\nb", 'long' => str_repeat('a', 301)]);

it('edits the title, content and address, advancing the revision, and never changes the Type', function () {
    $by = Resources::editor();
    $other = Resources::editor('other.editor@example.org', 'Other Editor');
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, 'Link', 'Words', CardType::ExternalLink, 'https://example.org/a');

    $edited = app(UpdateCard::class)($other, $pack->pack->id, $card->card->id, 1, [
        'title' => 'Renamed', 'uri' => 'https://example.org/b', 'type' => 'basic', 'state' => 'published', 'audience_mode' => 'narrowed', 'revision' => 99,
    ]);

    expect($edited->card->title)->toBe('Renamed')
        ->and($edited->card->externalUri)->toBe('https://example.org/b')
        ->and($edited->card->type)->toBe(CardType::ExternalLink) // a Card's Type never changes
        ->and($edited->card->state)->toBe(PublicationState::Draft) // publication is its own operation
        ->and($edited->card->revision)->toBe(2)
        ->and($edited->createdBy->displayName)->toBe('Ed Editor')
        ->and($edited->updatedBy->displayName)->toBe('Other Editor');
});

it('refuses to clear the address of an external link, and may clear a basic Card\'s', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $link = Resources::card($by, $pack, 'Link', 'x', CardType::ExternalLink, 'https://example.org');
    $basic = Resources::card($by, $pack, 'Basic', 'x', CardType::Basic, 'https://example.org');

    expect(fn () => app(UpdateCard::class)($by, $pack->pack->id, $link->card->id, 1, ['uri' => null]))->toThrow(InvalidResourceInput::class)
        ->and(app(UpdateCard::class)($by, $pack->pack->id, $basic->card->id, 1, ['uri' => null])->card->externalUri)->toBeNull();
});

it('refuses an edit based on a stale revision, shows the current Card, and writes nothing (no lost update)', function () {
    $first = Resources::editor('first.editor@example.org', 'First Editor');
    $second = Resources::editor('second.editor@example.org', 'Second Editor');
    $pack = Resources::pack($first);
    $card = Resources::card($first, $pack, 'Original', 'Original words');
    app(UpdateCard::class)($first, $pack->pack->id, $card->card->id, 1, ['title' => 'First won', 'content' => Resources::doc('First words')]);

    try {
        app(UpdateCard::class)($second, $pack->pack->id, $card->card->id, 1, ['title' => 'Second lost', 'content' => Resources::doc('Second words')]);
        $stale = null;
    } catch (StaleRevision $e) {
        $stale = $e;
    }

    expect($stale)->toBeInstanceOf(StaleRevision::class);
    assert($stale instanceof StaleRevision);
    expect($stale->current->card->title ?? null)->toBe('First won')
        ->and($stale->current->card->revision ?? null)->toBe(2)
        ->and(DB::table('resource_cards')->where('id', $card->card->id->value)->value('title'))->toBe('First won')
        ->and(app(GetManagedCard::class)($first, $pack->pack->id, $card->card->id)->card->content->plainText())->toBe('First words');
});

it('publishes a Card by Type: basic needs text, an external link needs an address, and a title always', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $empty = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Empty', null, null, null);
    $spaces = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Spaces', Resources::doc('   '), null, null);
    $link = app(CreateCard::class)($by, $pack->pack->id, CardType::ExternalLink, 'Link', null, 'https://example.org', null);

    foreach ([$empty, $spaces] as $card) {
        try {
            app(PublishCard::class)($by, $pack->pack->id, $card->card->id);
            $unmet = null;
        } catch (CardNotPublishable $e) {
            $unmet = $e->unmet;
        }
        expect($unmet)->toBe(['content']);
    }

    // An external link is publishable on its address alone, with no content: its title, summary and content are ordinary fields.
    expect(app(PublishCard::class)($by, $pack->pack->id, $link->card->id)->card->state)->toBe(PublicationState::Published);
});

it('publishes and unpublishes a Card independently of its Pack, idempotently and reversibly, keeping it for managers', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $card = Resources::card($by, $pack, 'Card', 'Words');

    expect(app(PublishCard::class)($by, $pack->pack->id, $card->card->id)->card->state)->toBe(PublicationState::Published)
        ->and(app(PublishCard::class)($by, $pack->pack->id, $card->card->id)->card->state)->toBe(PublicationState::Published)
        ->and(app(GetManagedPack::class)($by, $pack->pack->id)->pack->state)->toBe(PublicationState::Draft); // the Pack is untouched

    $back = app(UnpublishCard::class)($by, $pack->pack->id, $card->card->id);
    expect($back->card->state)->toBe(PublicationState::Draft)
        ->and(app(UnpublishCard::class)($by, $pack->pack->id, $card->card->id)->card->state)->toBe(PublicationState::Draft)
        // An unpublished Card is kept, ordered and editable: it is still in the Pack's contents.
        ->and(app(GetManagedPack::class)($by, $pack->pack->id)->cardCount)->toBe(1)
        ->and(app(GetManagedCard::class)($by, $pack->pack->id, $card->card->id)->card->content->plainText())->toBe('Words');
});

it('keeps a Published Card publishable: an edit that would empty its content or address is refused and changes nothing', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Live');
    $card = Resources::outline($live, 0)->card;

    $unmet = null;
    try {
        app(UpdateCard::class)($by, $live->pack->id, $card->id, $card->revision, ['content' => ['type' => 'doc', 'content' => []]]);
    } catch (CardNotPublishable $e) {
        $unmet = $e->unmet;
    }

    expect($unmet)->toBe(['content'])
        ->and(app(GetManagedCard::class)($by, $live->pack->id, $card->id)->card->content->plainText())->toBe('Words of Live card 1')
        ->and(app(GetManagedCard::class)($by, $live->pack->id, $card->id)->card->revision)->toBe($card->revision)
        // The same edit to a DRAFT Card is fine: a Draft may be empty.
        ->and(app(UnpublishCard::class)($by, $live->pack->id, $card->id)->card->state)->toBe(PublicationState::Draft)
        ->and(app(UpdateCard::class)($by, $live->pack->id, $card->id, $card->revision, ['content' => ['type' => 'doc', 'content' => []]])->card->content->plainText())->toBe('');
});

it('narrows a Card to a subset of its Pack\'s audiences, and returns it to inheriting', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $card = Resources::card($by, $pack, 'Card', 'Words');

    $narrowed = app(SetCardAudiences::class)($by, $pack->pack->id, $card->card->id, AudienceMode::Narrowed, [Audience::Guardian]);
    expect($narrowed->card->audience->mode)->toBe(AudienceMode::Narrowed)
        ->and($narrowed->card->audience->set->values())->toBe(['guardian'])
        ->and(DB::table('resource_card_audiences')->where('card_id', $card->card->id->value)->pluck('audience')->all())->toBe(['guardian']);

    $inherit = app(SetCardAudiences::class)($by, $pack->pack->id, $card->card->id, AudienceMode::Inherit, [Audience::Member]);
    expect($inherit->card->audience->mode)->toBe(AudienceMode::Inherit)
        ->and($inherit->card->audience->set->isEmpty())->toBeTrue()
        ->and(DB::table('resource_card_audiences')->where('card_id', $card->card->id->value)->count())->toBe(0);
});

it('NEVER lets a Card be broader than its Pack: narrowing to more, to something else, to nothing, or on a Pack with none is refused', function (array $pack, array $card) {
    $by = Resources::editor();
    $p = Resources::pack($by);
    app(SetPackAudiences::class)($by, $p->pack->id, Resources::audiences($pack));
    $c = Resources::card($by, $p, 'Card', 'Words');

    try {
        app(SetCardAudiences::class)($by, $p->pack->id, $c->card->id, AudienceMode::Narrowed, Resources::audiences($card));
        $refused = null;
    } catch (InvalidResourceInput $e) {
        $refused = $e;
    }

    expect($refused?->problem)->toBe('card_audience_not_subset')
        ->and(DB::table('resource_cards')->where('id', $c->card->id->value)->value('audience_mode'))->toBe('inherit')
        ->and(DB::table('resource_card_audiences')->count())->toBe(0);
})->with([
    'member on a guardian pack' => [[Audience::Guardian], [Audience::Member]],
    'both on a guardian pack' => [[Audience::Guardian], [Audience::Guardian, Audience::Member]],
    'anything on a pack with none' => [[], [Audience::Guardian]],
    'nothing at all' => [[Audience::Guardian, Audience::Member], []],
]);

it('keeps a narrowed Card narrowed when its Pack\'s audiences grow: a narrowing is fixed, not live', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
    $card = Resources::card($by, $pack, 'Card', 'Words');
    app(SetCardAudiences::class)($by, $pack->pack->id, $card->card->id, AudienceMode::Narrowed, [Audience::Guardian]);

    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);

    expect(app(GetManagedCard::class)($by, $pack->pack->id, $card->card->id)->card->audience->set->values())->toBe(['guardian']);
});

it('appends each new Card to the end of the Pack and reorders ALL its Cards, Drafts included, from the complete list', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    $a = Resources::card($by, $pack, 'A')->card->id;
    $b = Resources::publishedCard($by, $pack, 'B')->card->id;
    $c = Resources::card($by, $pack, 'C')->card->id;
    $titles = fn (): array => array_map(fn ($o): string => $o->card->title, app(GetManagedPack::class)($by, $pack->pack->id)->cards ?? []);

    expect($titles())->toBe(['A', 'B', 'C']);

    app(ReorderCards::class)($by, $pack->pack->id, [$c, $a, $b]);
    expect($titles())->toBe(['C', 'A', 'B']);

    foreach ([[$c, $a], [$c, $a, $b, CardId::generate()], [$a, $a, $b], [$c, $a, $b, $b], []] as $bad) {
        expect(fn () => app(ReorderCards::class)($by, $pack->pack->id, $bad))->toThrow(OrderMismatch::class);
    }
    expect($titles())->toBe(['C', 'A', 'B']);
});

it('does not treat ordering a Card as editing it: the revision and provenance do not move', function () {
    $by = Resources::editor();
    $other = Resources::editor('other.editor@example.org', 'Other Editor');
    $pack = Resources::pack($by);
    $a = Resources::card($by, $pack, 'A')->card->id;
    $b = Resources::card($by, $pack, 'B')->card->id;

    app(ReorderCards::class)($other, $pack->pack->id, [$b, $a]);

    $row = Resources::row('resource_cards', $a->value);
    expect(Resources::int($row->revision))->toBe(1)->and($row->updated_by_person_id)->toBe($by->personId->value);
});

it('refuses a 101st Card in a Pack', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by);
    for ($i = 1; $i <= 100; $i++) {
        Resources::card($by, $pack, "Card {$i}");
    }

    expect(fn () => Resources::card($by, $pack, 'One too many'))->toThrow(CardLimitReached::class)
        ->and(DB::table('resource_cards')->where('pack_id', $pack->pack->id->value)->count())->toBe(100);
});

it('does not find a Card through another Pack, or in a Pack that does not exist', function () {
    $by = Resources::editor();
    $one = Resources::pack($by, 'One');
    $two = Resources::pack($by, 'Two');
    $card = Resources::card($by, $one);

    foreach ([
        fn () => app(GetManagedCard::class)($by, $two->pack->id, $card->card->id),
        fn () => app(UpdateCard::class)($by, $two->pack->id, $card->card->id, 1, ['title' => 'x']),
        fn () => app(PublishCard::class)($by, $two->pack->id, $card->card->id),
        fn () => app(UnpublishCard::class)($by, $two->pack->id, $card->card->id),
        fn () => app(SetCardAudiences::class)($by, $two->pack->id, $card->card->id, AudienceMode::Inherit, []),
        fn () => app(GetManagedCard::class)($by, $one->pack->id, CardId::generate()),
    ] as $operation) {
        expect($operation)->toThrow(CardNotFound::class);
    }
    foreach ([
        fn () => app(GetManagedCard::class)($by, PackId::generate(), $card->card->id),
        fn () => app(CreateCard::class)($by, PackId::generate(), CardType::Basic, 'x', null, null, null),
        fn () => app(ReorderCards::class)($by, PackId::generate(), []),
    ] as $operation) {
        expect($operation)->toThrow(PackNotFound::class);
    }
});

it('has no file Type yet: the Type is a closed set of two, held as a string', function () {
    expect(array_map(fn (CardType $t): string => $t->value, CardType::cases()))->toBe(['basic', 'external_link'])
        ->and(CardType::tryFrom('file'))->toBeNull()
        ->and(Schema::hasTable('resource_assets'))->toBeFalse()
        ->and(Schema::hasColumn('resource_cards', 'asset_id'))->toBeFalse();
});
