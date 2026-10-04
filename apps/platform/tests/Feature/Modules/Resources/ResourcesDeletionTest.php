<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Resources\Application\BrowseResourceLibrary;
use App\Modules\Resources\Application\CardNotFound;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\CreateCategory;
use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Application\DeleteCategory;
use App\Modules\Resources\Application\DeletePack;
use App\Modules\Resources\Application\GetManagedCard;
use App\Modules\Resources\Application\GetManagedPack;
use App\Modules\Resources\Application\GetResourcePack;
use App\Modules\Resources\Application\ListCategories;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PackNotFound;
use App\Modules\Resources\Application\PageManagedPacks;
use App\Modules\Resources\Application\PreviewPack;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishedPackRequirement;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\RenameCategory;
use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Application\ReorderCategories;
use App\Modules\Resources\Application\ReorderPacks;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UnpublishPack;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Application\UpdatePack;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\PackId;
use Illuminate\Support\Facades\DB;
use Tests\Support\Faults;
use Tests\Support\Mfa;
use Tests\Support\Resources;

/*
 * Permanent deletion and its audit (ADR 0037, decisions 55 and 58-61, and the amendment that gave it a record). Deleting a Pack or a
 * Card is permanent (no Trash, Restore or Archive) and records ONE security event, inside the same transaction, holding the actor
 * and ids and counts, never what was deleted. Nothing else in Resources records an event. Runs on MariaDB and PostgreSQL.
 */

/** Words that must appear in a Resource and never in the audit trail, or in anything about a deleted Resource. */
const MARKERS = ['Zorblax', 'Quuxmarker', 'Wibblesnort', 'Flumphwords', 'https://marker.example/Plinkth'];

/** @return array{ManagedPackView, list<CardId>} a Published Pack stuffed with the marker words */
function markedPack(): array
{
    $by = Resources::editor();
    $category = Resources::category($by, 'Zorblax category')->category->id;
    $pack = Resources::pack($by, 'Zorblax pack', $category, summary: 'Quuxmarker summary');
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $ids = [];
    foreach ([0, 1, 2] as $i) {
        $card = app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, "Zorblax card {$i}", Resources::doc('Wibblesnort body', 'Flumphwords more'), 'https://marker.example/Plinkth', 'Quuxmarker card summary');
        app(PublishCard::class)($by, $pack->pack->id, $card->card->id);
        $ids[] = $card->card->id;
    }
    app(SetCardAudiences::class)($by, $pack->pack->id, $ids[2], AudienceMode::Narrowed, [Audience::Guardian]);
    app(PublishPack::class)($by, $pack->pack->id);

    return [$pack, $ids];
}

/** @return array<string, mixed> */
function contextOf(stdClass $event): array
{
    $context = json_decode(Resources::str($event->context), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($context));

    /** @var array<string, mixed> $context */
    return $context;
}

it('deletes a Pack with every Card in it, and every row that belonged to them, permanently', function () {
    [$pack] = markedPack();
    $by = Resources::editor();
    $survivor = Resources::published($by, [Audience::Guardian], 2, 'Survivor');

    app(DeletePack::class)($by, $pack->pack->id);

    expect(DB::table('resource_packs')->where('id', $pack->pack->id->value)->count())->toBe(0)
        ->and(DB::table('resource_cards')->where('pack_id', $pack->pack->id->value)->count())->toBe(0)
        ->and(DB::table('resource_pack_audiences')->where('pack_id', $pack->pack->id->value)->count())->toBe(0)
        ->and(DB::table('resource_card_audiences')->count())->toBe(0)
        // A neighbour is untouched, in full.
        ->and(app(GetManagedPack::class)($by, $survivor->pack->id)->cardCount)->toBe(2)
        ->and(DB::table('resource_card_audiences')->count())->toBe(0)
        ->and(fn () => app(GetManagedPack::class)($by, $pack->pack->id))->toThrow(PackNotFound::class);
});

it('can delete a PUBLISHED Pack directly: it is the confirmation and the recent verification that guard it', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 2, 'Live');

    app(DeletePack::class)($by, $live->pack->id);

    expect(DB::table('resource_packs')->count())->toBe(0)->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(app(BrowseResourceLibrary::class)($by, null, null))->toBe([]);
});

it('keeps nothing of a deleted Pack: no Trash, no Restore, no Archive, no copy, anywhere in the database', function () {
    [$pack, $cards] = markedPack();
    app(DeletePack::class)(Resources::editor(), $pack->pack->id);

    // The Resources tables hold no word of it (the Category survives with its own name: it was never the Pack's)...
    expect(DB::table('resource_categories')->count())->toBe(1);
    foreach (array_diff(Resources::tables(), ['resource_categories']) as $table) {
        $dump = json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        foreach (MARKERS as $marker) {
            expect($dump)->not->toContain($marker);
        }
    }
    // ...and nowhere else either: no table of any module holds the text. (The Category survives, with its own name: it is not the Pack.)
    foreach (Resources::allTables() as $table) {
        if (in_array($table, ['resource_categories', 'migrations'], true)) {
            continue;
        }
        $dump = json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        foreach (['Wibblesnort', 'Flumphwords', 'Plinkth', 'Quuxmarker', 'Zorblax pack', 'Zorblax card'] as $marker) {
            expect($dump)->not->toContain($marker, "{$table} still holds {$marker}");
        }
    }
    expect(count($cards))->toBe(3);
});

it('records exactly ONE resource.pack_deleted for a Pack deletion, with the actor, the id and counts, and no event per Card', function () {
    [$pack] = markedPack();
    $by = Resources::editor();
    $before = Resources::eventCount();

    app(DeletePack::class)($by, $pack->pack->id);

    $events = Resources::events();
    expect(Resources::eventCount())->toBe($before + 1)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe('resource.pack_deleted')
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($by->accountId->value)
        ->and($events[0]->subject_person_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBeNull()
        ->and($events[0]->ip)->toBeNull()
        ->and($events[0]->user_agent)->toBeNull()
        ->and(contextOf($events[0]))->toBe(['pack_id' => $pack->pack->id->value, 'cards_deleted' => 3, 'files_deleted' => 0])
        ->and(DB::table('security_events')->where('type', 'resource.card_deleted')->count())->toBe(0);
});

it('records exactly ONE resource.card_deleted for a Card deletion, with the actor and ids only', function () {
    [$pack, $cards] = markedPack();
    $by = Resources::editor();

    app(DeleteCard::class)($by, $pack->pack->id, $cards[2]); // the narrowed one: its audience rows go with it

    $events = Resources::events();
    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe('resource.card_deleted')
        ->and($events[0]->actor_account_id)->toBe($by->accountId->value)
        ->and($events[0]->outcome)->toBe('success')
        ->and(contextOf($events[0]))->toBe(['card_id' => $cards[2]->value, 'pack_id' => $pack->pack->id->value, 'card_type' => 'basic'])
        ->and(DB::table('resource_cards')->where('id', $cards[2]->value)->count())->toBe(0)
        ->and(DB::table('resource_card_audiences')->where('card_id', $cards[2]->value)->count())->toBe(0)
        ->and(app(GetManagedPack::class)($by, $pack->pack->id)->cardCount)->toBe(2)
        ->and(fn () => app(GetManagedCard::class)($by, $pack->pack->id, $cards[2]))->toThrow(CardNotFound::class);
});

it('puts none of the deleted Resource in the audit trail: not a title, summary, content, address or audience', function () {
    [$pack, $cards] = markedPack();
    $by = Resources::editor();

    app(DeleteCard::class)($by, $pack->pack->id, $cards[0]);
    app(DeletePack::class)($by, $pack->pack->id);

    $everything = Mfa::auditText();
    foreach ([...MARKERS, 'guardian', 'member', 'inherit', 'narrowed', 'prosemirror', 'draft', 'published', 'Ed Editor', 'Zorblax category'] as $forbidden) {
        expect($everything)->not->toContain($forbidden, "the audit trail holds {$forbidden}");
    }
    // The context keys are EXACTLY the approved ones: a new key cannot appear without this test being changed on purpose.
    $keys = array_map(fn ($e): array => array_keys(contextOf($e)), Resources::events());
    expect($keys)->toBe([['card_id', 'pack_id', 'card_type'], ['pack_id', 'cards_deleted', 'files_deleted']]);
});

it('records no event, and deletes nothing, when the caller lacks resources.manage', function () {
    [$pack, $cards] = markedPack();
    $before = Resources::eventCount();

    expect(fn () => app(DeletePack::class)(Resources::outsider(), $pack->pack->id))->toThrow(AccessDenied::class)
        ->and(fn () => app(DeleteCard::class)(Resources::outsider(), $pack->pack->id, $cards[0]))->toThrow(AccessDenied::class);

    expect(Resources::eventCount())->toBe($before)->and(DB::table('resource_cards')->count())->toBe(3)->and(DB::table('resource_packs')->count())->toBe(1);
});

it('records no event for a Pack or Card that does not exist, or a Card that is not in that Pack: deleting what is gone is a 404, never a second event', function () {
    [$pack, $cards] = markedPack();
    $by = Resources::editor();
    $other = Resources::pack($by, 'Other');
    $before = Resources::eventCount();

    expect(fn () => app(DeletePack::class)($by, PackId::generate()))->toThrow(PackNotFound::class)
        ->and(fn () => app(DeleteCard::class)($by, PackId::generate(), $cards[0]))->toThrow(PackNotFound::class)
        ->and(fn () => app(DeleteCard::class)($by, $pack->pack->id, CardId::generate()))->toThrow(CardNotFound::class)
        ->and(fn () => app(DeleteCard::class)($by, $other->pack->id, $cards[0]))->toThrow(CardNotFound::class);

    app(DeletePack::class)($by, $other->pack->id);
    expect(fn () => app(DeletePack::class)($by, $other->pack->id))->toThrow(PackNotFound::class);

    // Exactly one event, for the one real deletion: the repeats recorded nothing.
    expect(Resources::eventCount())->toBe($before + 1)->and(DB::table('resource_cards')->count())->toBe(3);
});

it('records no event when a domain rule refuses the deletion: the last Published Card of a Published Pack stays', function () {
    $by = Resources::editor();
    $live = Resources::published($by, [Audience::Guardian], 1, 'Live');
    $before = Resources::eventCount();

    expect(fn () => app(DeleteCard::class)($by, $live->pack->id, Resources::outline($live, 0)->card->id))->toThrow(PublishedPackRequirement::class);

    expect(Resources::eventCount())->toBe($before)->and(DB::table('resource_cards')->count())->toBe(1);
});

it('rolls the deletion back when the audit event cannot be written: no event means no deletion', function () {
    [$pack, $cards] = markedPack();
    $by = Resources::editor();
    $before = Resources::eventCount();
    Faults::auditFailsAt(1);

    expect(fn () => app(DeletePack::class)($by, $pack->pack->id))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(DB::table('resource_packs')->where('id', $pack->pack->id->value)->count())->toBe(1)
        ->and(DB::table('resource_cards')->count())->toBe(3)
        ->and(DB::table('resource_card_audiences')->count())->toBe(1)
        ->and(DB::table('resource_pack_audiences')->count())->toBe(2)
        ->and(Resources::eventCount())->toBe($before);

    Faults::auditFailsAt(1);
    expect(fn () => app(DeleteCard::class)($by, $pack->pack->id, $cards[0]))->toThrow(RuntimeException::class, 'audit store unavailable');
    expect(DB::table('resource_cards')->count())->toBe(3)->and(Resources::eventCount())->toBe($before);
});

it('rolls the event back when the deletion fails after it: a failed deletion records no success event', function () {
    [$pack, $cards] = markedPack();
    $by = Resources::editor();
    $before = Resources::eventCount();

    // The deletion and its event are written, then the surrounding transaction fails: both must go together.
    expect(fn () => DB::transaction(function () use ($by, $pack, $cards) {
        app(DeleteCard::class)($by, $pack->pack->id, $cards[0]);
        app(DeletePack::class)($by, $pack->pack->id);
        throw new RuntimeException('something later failed');
    }))->toThrow(RuntimeException::class, 'something later failed');

    expect(DB::table('resource_packs')->count())->toBe(1)
        ->and(DB::table('resource_cards')->count())->toBe(3)
        ->and(Resources::eventCount())->toBe($before)
        ->and(Resources::events())->toBe([]);
});

it('records no security event for ANY other Resources action: create, edit, order, target, publish, unpublish, preview, read', function () {
    $by = Resources::editor();
    $before = Resources::eventCount();

    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;
    app(RenameCategory::class)($by, $a, 'A renamed');
    app(ReorderCategories::class)($by, [$b, $a]);
    $empty = app(CreateCategory::class)($by, 'Empty')->category->id;
    app(DeleteCategory::class)($by, $empty); // deleting an EMPTY Category is routine: it loses a name, not content
    $pack = Resources::pack($by, 'Pack', $a);
    app(UpdatePack::class)($by, $pack->pack->id, 1, ['title' => 'Pack edited', 'summary' => 'S']);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $one = Resources::card($by, $pack, 'One', 'Words one');
    $two = Resources::card($by, $pack, 'Two', 'Words two', CardType::ExternalLink, 'https://example.org');
    app(UpdateCard::class)($by, $pack->pack->id, $one->card->id, 1, ['title' => 'One edited', 'content' => Resources::doc('Changed')]);
    app(SetCardAudiences::class)($by, $pack->pack->id, $one->card->id, AudienceMode::Narrowed, [Audience::Guardian]);
    app(PublishCard::class)($by, $pack->pack->id, $one->card->id);
    app(PublishCard::class)($by, $pack->pack->id, $two->card->id);
    app(ReorderCards::class)($by, $pack->pack->id, [$two->card->id, $one->card->id]);
    app(PublishPack::class)($by, $pack->pack->id);
    app(ReorderPacks::class)($by, $a, [$pack->pack->id]);
    app(UnpublishCard::class)($by, $pack->pack->id, $two->card->id);
    app(PreviewPack::class)($by, $pack->pack->id, Audience::Member);
    app(GetResourcePack::class)($by, $pack->pack->id);
    app(BrowseResourceLibrary::class)($by, null, 'Pack');
    app(PageManagedPacks::class)($by, new ManagedPackFilter, 1, 25);
    app(ListCategories::class)($by);
    app(UnpublishPack::class)($by, $pack->pack->id);

    expect(Resources::eventCount())->toBe($before)->and(Resources::events())->toBe([]);
});

it('keeps the deletion events, and only they, when the Pack they describe is long gone', function () {
    [$pack] = markedPack();
    $by = Resources::editor();
    app(DeletePack::class)($by, $pack->pack->id);

    // The record outlives its subject: no foreign key, so it neither blocks the deletion nor dangles.
    expect(Resources::events())->toHaveCount(1)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->count())->toBe(0)
        ->and(contextOf(Resources::events()[0])['pack_id'])->toBe($pack->pack->id->value);
});
