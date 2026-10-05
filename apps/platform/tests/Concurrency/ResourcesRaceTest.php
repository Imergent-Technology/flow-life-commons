<?php

declare(strict_types=1);

use App\Modules\Resources\Application\CardAudienceConflict;
use App\Modules\Resources\Application\CardNotFound;
use App\Modules\Resources\Application\CardNotPublishable;
use App\Modules\Resources\Application\CategoryNotEmpty;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\CreateCategory;
use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Application\DeleteCategory;
use App\Modules\Resources\Application\DeletePack;
use App\Modules\Resources\Application\DuplicateCategory;
use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\OrderMismatch;
use App\Modules\Resources\Application\PackNotFound;
use App\Modules\Resources\Application\PackNotPublishable;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishedPackRequirement;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Application\ReorderCategories;
use App\Modules\Resources\Application\ReplaceCardFile;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\StaleRevision;
use App\Modules\Resources\Application\UnknownCategory;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Application\UpdatePack;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Shared\Domain\Actor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Race;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;
use Tests\Support\ResourcesPauses;

/*
 * Resources' write integrity under REAL concurrency, across two PHP processes and two database connections (method:
 * Tests\Support\Race, ADR 0037 decisions 56 and 57). The first process does its work inside an open transaction and stops before
 * committing; a second process then runs a competing operation, and the test checks that it WAITED and that the committed state is
 * right. Every scenario asserts both halves (it blocked, and the state is right), since either alone can pass for the wrong reason.
 *
 * What each one proves is that the structural invariant holds whichever commits first: a Published Pack always keeps a Published
 * Card; a Card is never broader than its Pack; a reorder is of exactly the set that exists; a Card is never Published on content an
 * edit emptied meanwhile; and an edit based on an old revision never overwrites a newer one.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
    ResourceFiles::fake(); // one store for both processes (Race::start hands the worker its root)
});

afterEach(function () {
    Race::clean();
});

/** @return array<string, string> the worker's identification of the acting Guardian */
function resourcesActor(Actor $by): array
{
    return ['actor_account' => $by->accountId->value, 'actor_person' => $by->personId->value];
}

/** @return array{ManagedPackView, ManagedCardView} a Draft Pack ready to publish: a Category, the guardian and member audiences, one Published Card */
function readyDraftPack(Actor $by): array
{
    $category = Resources::category($by, 'Guides')->category->id;
    $pack = Resources::pack($by, 'Pack', $category);
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian, Audience::Member]);
    $card = Resources::publishedCard($by, $pack, 'Only card', 'Only words');

    return [$pack, $card];
}

/** @return list<string> the Card titles of a Pack in stored order */
function storedCardTitles(string $pack): array
{
    return Resources::strings(DB::table('resource_cards')->where('pack_id', $pack)->orderBy('position')->orderBy('id')->pluck('title'));
}

it('serialises publishing a Pack against deleting its last Published Card: whichever commits first, a Published Pack keeps a Published Card', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    // The publication commits first; the deletion then finds a Published Pack and its last Published Card, and is refused.
    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            DB::transaction(function () use ($by, $pack, $pause) {
                app(PublishPack::class)($by, $pack->pack->id);
                $pause();
            });
        },
        null, 'resources_delete_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value],
    );

    expect($race['blocked'])->toBeTrue('the deletion did not wait for the publication to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PublishedPackRequirement::class)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('state'))->toBe('published')
        ->and(DB::table('resource_cards')->where('pack_id', $pack->pack->id->value)->where('state', 'published')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'like', 'resource.%')->count())->toBe(0);
});

it('serialises deleting the last Published Card against publishing its Pack: the deletion commits first and the publication is refused', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    $race = Race::against(
        fn () => app(DeleteCard::class)($by, $pack->pack->id, $card->card->id),
        'resource.card_deleted', 'resources_publish_pack', [...resourcesActor($by), 'pack' => $pack->pack->id->value],
    );

    expect($race['blocked'])->toBeTrue('the publication did not wait for the deletion to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PackNotPublishable::class)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('state'))->toBe('draft')
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'resource.card_deleted')->count())->toBe(1);
});

it('serialises unpublishing the last Published Card against publishing the Pack: the Pack never ends Published with no Published Card', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            DB::transaction(function () use ($by, $pack, $card, $pause) {
                app(UnpublishCard::class)($by, $pack->pack->id, $card->card->id);
                $pause();
            });
        },
        null, 'resources_publish_pack', [...resourcesActor($by), 'pack' => $pack->pack->id->value],
    );

    expect($race['blocked'])->toBeTrue('the publication did not wait for the unpublishing to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PackNotPublishable::class)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('state'))->toBe('draft')
        ->and(DB::table('resource_cards')->where('id', $card->card->id->value)->value('state'))->toBe('draft');
});

it('serialises publishing the Pack against unpublishing its last Published Card: the unpublishing commits second and is refused', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            DB::transaction(function () use ($by, $pack, $pause) {
                app(PublishPack::class)($by, $pack->pack->id);
                $pause();
            });
        },
        null, 'resources_unpublish_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value],
    );

    expect($race['blocked'])->toBeTrue('the unpublishing did not wait for the publication to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PublishedPackRequirement::class)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('state'))->toBe('published')
        ->and(DB::table('resource_cards')->where('id', $card->card->id->value)->value('state'))->toBe('published');
});

it('serialises reducing a Pack\'s audiences against narrowing a Card: a Card is never left broader than its Pack', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    // The Pack's reduction commits first; narrowing to Members, which the Pack no longer has, is then refused.
    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            DB::transaction(function () use ($by, $pack, $pause) {
                app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
                $pause();
            });
        },
        null, 'resources_set_card_audiences', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value, 'mode' => 'narrowed', 'audiences' => 'member'],
    );

    expect($race['blocked'])->toBeTrue('the narrowing did not wait for the Pack\'s reduction to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(InvalidResourceInput::class)
        ->and(DB::table('resource_pack_audiences')->where('pack_id', $pack->pack->id->value)->pluck('audience')->all())->toBe(['guardian'])
        ->and(DB::table('resource_cards')->where('id', $card->card->id->value)->value('audience_mode'))->toBe('inherit')
        ->and(DB::table('resource_card_audiences')->count())->toBe(0);
});

it('serialises narrowing a Card against reducing its Pack\'s audiences: the narrowing commits first and the reduction is refused', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            DB::transaction(function () use ($by, $pack, $card, $pause) {
                app(SetCardAudiences::class)($by, $pack->pack->id, $card->card->id, AudienceMode::Narrowed, [Audience::Member]);
                $pause();
            });
        },
        null, 'resources_set_pack_audiences', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'audiences' => 'guardian'],
    );

    expect($race['blocked'])->toBeTrue('the Pack\'s reduction did not wait for the narrowing to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CardAudienceConflict::class)
        ->and(DB::table('resource_pack_audiences')->where('pack_id', $pack->pack->id->value)->orderBy('audience')->pluck('audience')->all())->toBe(['guardian', 'member'])
        ->and(DB::table('resource_card_audiences')->where('card_id', $card->card->id->value)->pluck('audience')->all())->toBe(['member']);
});

it('serialises reordering Cards against creating one: the creation waits, then lands at the end of the new order', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $one = Resources::card($by, $pack, 'One')->card->id;
    $two = Resources::card($by, $pack, 'Two')->card->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $one, $two) {
            DB::transaction(function () use ($by, $pack, $one, $two, $pause) {
                app(ReorderCards::class)($by, $pack->pack->id, [$two, $one]);
                $pause();
            });
        },
        null, 'resources_create_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'title' => 'Three', 'text' => 'x'],
    );

    expect($race['blocked'])->toBeTrue('the creation did not wait for the reorder to commit')
        ->and($race['exit'])->toBe(0)
        ->and(storedCardTitles($pack->pack->id->value))->toBe(['Two', 'One', 'Three']);
});

it('serialises creating a Card against reordering: the reorder of the old list is refused as order_mismatch, never half-applied', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $one = Resources::card($by, $pack, 'One')->card->id;
    $two = Resources::card($by, $pack, 'Two')->card->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            DB::transaction(function () use ($by, $pack, $pause) {
                app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Three', Resources::doc('x'), null, null);
                $pause();
            });
        },
        null, 'resources_reorder_cards', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'ids' => $two->value.','.$one->value],
    );

    expect($race['blocked'])->toBeTrue('the reorder did not wait for the creation to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(OrderMismatch::class)
        ->and(storedCardTitles($pack->pack->id->value))->toBe(['One', 'Two', 'Three']);
});

it('serialises reordering Categories against creating one: the creation waits on the set\'s lock', function () {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $a, $b) {
            DB::transaction(function () use ($by, $a, $b, $pause) {
                app(ReorderCategories::class)($by, [$b, $a]);
                $pause();
            });
        },
        null, 'resources_create_category', [...resourcesActor($by), 'name' => 'C'],
    );

    expect($race['blocked'])->toBeTrue('the creation did not wait for the reorder to commit')
        ->and($race['exit'])->toBe(0)
        ->and(Resources::strings(DB::table('resource_categories')->orderBy('position')->orderBy('id')->pluck('name')))->toBe(['B', 'A', 'C']);
});

it('serialises creating a Category against reordering: the reorder of the old list is refused as order_mismatch', function () {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;

    $race = Race::against(
        function (Closure $pause) use ($by) {
            DB::transaction(function () use ($by, $pause) {
                app(CreateCategory::class)($by, 'C');
                $pause();
            });
        },
        null, 'resources_reorder_categories', [...resourcesActor($by), 'ids' => $b->value.','.$a->value],
    );

    expect($race['blocked'])->toBeTrue('the reorder did not wait for the creation to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(OrderMismatch::class)
        ->and(Resources::strings(DB::table('resource_categories')->orderBy('position')->orderBy('id')->pluck('name')))->toBe(['A', 'B', 'C']);
});

it('lets the unique index decide two creations of one Category name at once: the second waits, then is refused as a duplicate', function () {
    $by = Resources::editor();

    $race = Race::against(
        function (Closure $pause) use ($by) {
            DB::transaction(function () use ($by, $pause) {
                app(CreateCategory::class)($by, 'Guides');
                $pause();
            });
        },
        null, 'resources_create_category', [...resourcesActor($by), 'name' => 'guides'],
    );

    expect($race['blocked'])->toBeTrue('the second creation did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(DuplicateCategory::class)
        ->and(DB::table('resource_categories')->count())->toBe(1);
});

it('never loses an update: two Pack edits based on one revision, the second waits and is refused as stale', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Original');

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            DB::transaction(function () use ($by, $pack, $pause) {
                app(UpdatePack::class)($by, $pack->pack->id, 1, ['title' => 'First wins']);
                $pause();
            });
        },
        null, 'resources_update_pack', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'revision' => '1', 'title' => 'Second loses'],
    );

    expect($race['blocked'])->toBeTrue('the second edit did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(StaleRevision::class)
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('title'))->toBe('First wins')
        ->and(Resources::int(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('revision')))->toBe(2);
});

it('never loses an update: two Card edits based on one revision, the second waits and is refused as stale', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $card = Resources::card($by, $pack, 'Original', 'Original words');

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            DB::transaction(function () use ($by, $pack, $card, $pause) {
                app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['title' => 'First wins', 'content' => Resources::doc('First words')]);
                $pause();
            });
        },
        null, 'resources_update_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value, 'revision' => '1', 'title' => 'Second loses'],
    );

    $row = Resources::row('resource_cards', $card->card->id->value);
    expect($race['blocked'])->toBeTrue('the second edit did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(StaleRevision::class)
        ->and($row->title)->toBe('First wins')
        ->and(Resources::int($row->revision))->toBe(2)
        ->and($row->summary_text)->toBe('First words');
});

it('never publishes a Card on content an edit has emptied: the edit, judged as a Published Card\'s, is refused', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $card = Resources::card($by, $pack, 'Card', 'Real words');

    // Publication commits first (it holds the Pack's lock and the Card's row). The edit, which read the Card as a Draft, waits, finds
    // it Published when its conditional write fails, and is refused because an emptied Published Card is no longer publishable.
    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            DB::transaction(function () use ($by, $pack, $card, $pause) {
                app(PublishCard::class)($by, $pack->pack->id, $card->card->id);
                $pause();
            });
        },
        null, 'resources_update_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value, 'revision' => '1', 'text' => ''],
    );

    $row = Resources::row('resource_cards', $card->card->id->value);
    expect($race['blocked'])->toBeTrue('the edit did not wait for the publication to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CardNotPublishable::class)
        ->and($row->state)->toBe('published')
        ->and($row->summary_text)->toBe('Real words')
        ->and(Resources::int($row->revision))->toBe(1);
});

it('lets an edit that commits first stand, and refuses to publish the Card it emptied', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $card = Resources::card($by, $pack, 'Card', 'Real words');

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            DB::transaction(function () use ($by, $pack, $card, $pause) {
                app(UpdateCard::class)($by, $pack->pack->id, $card->card->id, 1, ['content' => ['type' => 'doc', 'content' => []]]);
                $pause();
            });
        },
        null, 'resources_publish_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value],
    );

    expect($race['blocked'])->toBeTrue('the publication did not wait for the edit to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CardNotPublishable::class)
        ->and(DB::table('resource_cards')->where('id', $card->card->id->value)->value('state'))->toBe('draft');
});

it('serialises deleting a Pack against publishing it: the publication of a deleted Pack is refused as not found, and one event was recorded', function () {
    $by = Resources::editor();
    [$pack] = readyDraftPack($by);

    $race = Race::against(
        fn () => app(DeletePack::class)($by, $pack->pack->id),
        'resource.pack_deleted', 'resources_publish_pack', [...resourcesActor($by), 'pack' => $pack->pack->id->value],
    );

    expect($race['blocked'])->toBeTrue('the publication did not wait for the deletion to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PackNotFound::class)
        ->and(DB::table('resource_packs')->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'resource.pack_deleted')->count())->toBe(1);
});

it('records exactly ONE event when two people delete the same Card at once: the second waits and finds it gone', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $card = Resources::card($by, $pack, 'Doomed')->card->id;
    Resources::card($by, $pack, 'Survivor');

    $race = Race::against(
        fn () => app(DeleteCard::class)($by, $pack->pack->id, $card),
        'resource.card_deleted', 'resources_delete_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->value],
    );

    expect($race['blocked'])->toBeTrue('the second deletion did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CardNotFound::class)
        ->and(DB::table('security_events')->where('type', 'resource.card_deleted')->count())->toBe(1)
        ->and(storedCardTitles($pack->pack->id->value))->toBe(['Survivor']);
});

it('serialises deleting a Category against putting a Pack in it: the Pack\'s creation is refused, never orphaned', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Going away')->category->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $category) {
            DB::transaction(function () use ($by, $category, $pause) {
                app(DeleteCategory::class)($by, $category);
                $pause();
            });
        },
        null, 'resources_create_pack', [...resourcesActor($by), 'title' => 'Latecomer', 'category' => $category->value],
    );

    expect($race['blocked'])->toBeTrue('the Pack\'s creation did not wait for the deletion to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(UnknownCategory::class)
        ->and(DB::table('resource_categories')->count())->toBe(0)
        ->and(DB::table('resource_packs')->count())->toBe(0);
});

it('serialises putting a Pack into a Category against deleting it: the deletion is refused as not empty', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Busy')->category->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $category) {
            DB::transaction(function () use ($by, $category, $pause) {
                app(CreatePack::class)($by, 'Resident', null, false, $category);
                $pause();
            });
        },
        null, 'resources_delete_category', [...resourcesActor($by), 'category' => $category->value],
    );

    expect($race['blocked'])->toBeTrue('the deletion did not wait for the Pack\'s creation to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CategoryNotEmpty::class)
        ->and(DB::table('resource_categories')->count())->toBe(1)
        ->and(DB::table('resource_packs')->count())->toBe(1);
});

it('serialises moving a Pack into a Category against deleting it, and clearing its Category against publishing it', function () {
    $by = Resources::editor();
    $target = Resources::category($by, 'Target')->category->id;
    $loose = Resources::pack($by, 'Mover');

    $moving = Race::against(
        function (Closure $pause) use ($by, $loose, $target) {
            DB::transaction(function () use ($by, $loose, $target, $pause) {
                app(UpdatePack::class)($by, $loose->pack->id, 1, ['category_id' => $target->value]);
                $pause();
            });
        },
        null, 'resources_delete_category', [...resourcesActor($by), 'category' => $target->value],
    );
    expect($moving['blocked'])->toBeTrue('the deletion did not wait for the move to commit')
        ->and($moving['class'])->toBe(CategoryNotEmpty::class)
        ->and(DB::table('resource_packs')->where('id', $loose->pack->id->value)->value('category_id'))->toBe($target->value);

    // Clearing a Draft's Category races a publication of that same Pack: whichever commits first, a Published Pack keeps its Category.
    $by2 = Resources::editor('second.editor@example.org', 'Second Editor');
    app(SetPackAudiences::class)($by, $loose->pack->id, [Audience::Guardian]);
    Resources::publishedCard($by, $loose, 'Card', 'Words');
    $publishing = Race::against(
        function (Closure $pause) use ($by, $loose) {
            DB::transaction(function () use ($by, $loose, $pause) {
                app(PublishPack::class)($by, $loose->pack->id);
                $pause();
            });
        },
        null, 'resources_move_pack', [...resourcesActor($by2), 'pack' => $loose->pack->id->value, 'revision' => '2', 'category' => ''],
    );

    expect($publishing['blocked'])->toBeTrue('the Category change did not wait for the publication to commit')
        ->and($publishing['exit'])->toBe(2)
        ->and($publishing['class'])->toBe(PublishedPackRequirement::class)
        ->and(DB::table('resource_packs')->where('id', $loose->pack->id->value)->value('state'))->toBe('published')
        ->and(DB::table('resource_packs')->where('id', $loose->pack->id->value)->value('category_id'))->toBe($target->value);
});

it('leaves a Published Pack publishable and every Card within its Pack after any of the above: the invariants hold in the committed data', function () {
    $by = Resources::editor();
    [$pack] = readyDraftPack($by);
    app(PublishPack::class)($by, $pack->pack->id);
    Resources::publishedCard($by, $pack, 'Second', 'More words');

    // The standing invariants, stated as queries over what is actually stored (so a future change that breaks one fails here).
    $publishedWithoutCategory = DB::table('resource_packs')->where('state', 'published')->whereNull('category_id')->count();
    $publishedWithoutAudience = DB::table('resource_packs as p')->where('p.state', 'published')
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('resource_pack_audiences as a')->whereColumn('a.pack_id', 'p.id'))->count();
    $publishedWithoutPublishedCard = DB::table('resource_packs as p')->where('p.state', 'published')
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('resource_cards as c')->whereColumn('c.pack_id', 'p.id')->where('c.state', 'published'))->count();
    $narrowedWiderThanPack = DB::table('resource_card_audiences as ca')
        ->join('resource_cards as c', 'c.id', '=', 'ca.card_id')
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('resource_pack_audiences as pa')->whereColumn('pa.pack_id', 'c.pack_id')->whereColumn('pa.audience', 'ca.audience'))->count();

    expect([$publishedWithoutCategory, $publishedWithoutAudience, $publishedWithoutPublishedCard, $narrowedWiderThanPack])->toBe([0, 0, 0, 0]);
});

// --- The window between "I looked" and "I changed": where an EXPLICIT lock is the only protection ----------------------------------
//
// The scenarios above pause after the use case has written, and a write takes its own row lock, which can hide a missing explicit
// lock. These pause (tests/Support/ResourcesPauses) right after a read, before any write, so only a lock the use case took
// explicitly can make the competing process wait.

it('holds the Pack\'s lock from the moment publication looks at its Cards: a Card deleted in that window cannot slip past the check', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            ResourcesPauses::afterCard('outlinesOf', $pause);
            app(PublishPack::class)($by, $pack->pack->id);
        },
        null, 'resources_delete_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value],
    );

    expect($race['blocked'])->toBeTrue('the deletion was not held back while publication was deciding from the Cards it had read')
        ->and($race['class'])->toBe(PublishedPackRequirement::class)
        // The committed data satisfies the invariant: a Published Pack with a Published Card.
        ->and(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('state'))->toBe('published')
        ->and(DB::table('resource_cards')->where('pack_id', $pack->pack->id->value)->where('state', 'published')->count())->toBe(1);
});

it('holds the Pack\'s lock from the moment a reorder looks at its Cards: a Card created in that window waits for the new order', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $one = Resources::card($by, $pack, 'One')->card->id;
    $two = Resources::card($by, $pack, 'Two')->card->id;

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $one, $two) {
            ResourcesPauses::afterCard('outlinesOf', $pause);
            app(ReorderCards::class)($by, $pack->pack->id, [$two, $one]);
        },
        null, 'resources_create_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'title' => 'Three', 'text' => 'x'],
    );

    expect($race['blocked'])->toBeTrue('the creation was not held back while the reorder was deciding from the Cards it had read')
        ->and($race['exit'])->toBe(0)
        ->and(storedCardTitles($pack->pack->id->value))->toBe(['Two', 'One', 'Three']);
});

it('holds the Pack\'s lock from the moment a creation reads the next position: two creations never take the same place', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    Resources::card($by, $pack, 'One');

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            ResourcesPauses::afterCard('nextPositionIn', $pause);
            app(CreateCard::class)($by, $pack->pack->id, CardType::Basic, 'Two', Resources::doc('x'), null, null);
        },
        null, 'resources_create_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'title' => 'Three', 'text' => 'x'],
    );

    expect($race['blocked'])->toBeTrue('the second creation was not held back while the first had read the next position')
        ->and($race['exit'])->toBe(0)
        ->and(storedCardTitles($pack->pack->id->value))->toBe(['One', 'Two', 'Three'])
        ->and(Resources::ints(DB::table('resource_cards')->where('pack_id', $pack->pack->id->value)->orderBy('position')->pluck('position')))->toBe([1, 2, 3]);
});

it('holds the Categories\' lock from the moment a creation reads the next position: a reorder waits and then sees the new Category', function () {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;

    $race = Race::against(
        function (Closure $pause) use ($by) {
            ResourcesPauses::afterCategory('nextPosition', $pause);
            app(CreateCategory::class)($by, 'C');
        },
        null, 'resources_reorder_categories', [...resourcesActor($by), 'ids' => $b->value.','.$a->value],
    );

    expect($race['blocked'])->toBeTrue('the reorder was not held back while the creation had read the next position')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(OrderMismatch::class)
        ->and(Resources::strings(DB::table('resource_categories')->orderBy('position')->orderBy('id')->pluck('name')))->toBe(['A', 'B', 'C']);
});

it('holds the Card\'s row from the moment publication reads it: an edit that empties it in that window waits, and is then judged as a Published Card\'s', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Pack');
    $card = Resources::card($by, $pack, 'Card', 'Real words');

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            ResourcesPauses::afterCard('lock', $pause);
            app(PublishCard::class)($by, $pack->pack->id, $card->card->id);
        },
        null, 'resources_update_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value, 'revision' => '1', 'text' => ''],
    );

    $row = Resources::row('resource_cards', $card->card->id->value);
    expect($race['blocked'])->toBeTrue('the edit was not held back while publication had read the Card')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CardNotPublishable::class)
        ->and($row->state)->toBe('published')
        ->and($row->summary_text)->toBe('Real words');
});

it('holds the Pack\'s lock from the moment its audiences are read for a reduction: a Card narrowed in that window waits', function () {
    $by = Resources::editor();
    [$pack, $card] = readyDraftPack($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            ResourcesPauses::afterCard('outlinesOf', $pause);
            app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Guardian]);
        },
        null, 'resources_set_card_audiences', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value, 'mode' => 'narrowed', 'audiences' => 'member'],
    );

    expect($race['blocked'])->toBeTrue('the narrowing was not held back while the reduction had read the Cards')
        ->and($race['class'])->toBe(InvalidResourceInput::class)
        ->and(DB::table('resource_card_audiences')->count())->toBe(0)
        ->and(DB::table('resource_pack_audiences')->where('pack_id', $pack->pack->id->value)->pluck('audience')->all())->toBe(['guardian']);
});

it('holds the Pack\'s lock while its Cards are being deleted: a Card created in between waits, and then finds the Pack gone instead of tripping the foreign key', function () {
    $by = Resources::editor();
    $pack = Resources::pack($by, 'Doomed');
    Resources::card($by, $pack, 'One');

    $race = Race::against(
        function (Closure $pause) use ($by, $pack) {
            ResourcesPauses::afterCard('deleteAllOf', $pause); // the Cards are gone, the Pack row is not yet
            app(DeletePack::class)($by, $pack->pack->id);
        },
        null, 'resources_create_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'title' => 'Latecomer', 'text' => 'x'],
    );

    expect($race['blocked'])->toBeTrue('the creation was not held back while the Pack was being deleted')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PackNotFound::class)
        ->and(DB::table('resource_packs')->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'resource.pack_deleted')->count())->toBe(1);
});

/**
 * The reverse of the race above: a Card is CREATED while the Pack is being DELETED. The creation holds the Pack's lock and commits
 * a new Card while the deletion waits for that lock. The deletion's first read (finding the Pack, to know its Category) is a plain
 * read, which on MariaDB (InnoDB REPEATABLE READ) fixes the transaction's snapshot BEFORE it waits; a plain read of the Card ids
 * after the lock would use that old snapshot and miss the late Card, while the DELETE that follows (a current read) removes it
 * anyway. The deletion must therefore read the Card ids with a locking, current read.
 *
 * @return array{race: array{blocked: bool, exit: int|null, class: string|null}, packId: string, cardsDeleted: mixed}
 */
function racePackDeletionAgainstCardCreation(bool $narrowed): array
{
    $by = Resources::editor();
    [$pack] = readyDraftPack($by); // one Published Card already there; audiences guardian and member

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $narrowed) {
            DB::transaction(function () use ($by, $pack, $narrowed, $pause) {
                $late = Resources::card($by, $pack, 'Latecomer', 'Late words');
                if ($narrowed) {
                    app(SetCardAudiences::class)($by, $pack->pack->id, $late->card->id, AudienceMode::Narrowed, [Audience::Guardian]);
                }
                $pause(); // the Pack's lock is held, the late Card is written and not committed: the deletion starts now and waits
            });
        },
        null, 'resources_delete_pack', [...resourcesActor($by), 'pack' => $pack->pack->id->value],
    );

    $events = Resources::events();
    $context = $events === [] ? [] : json_decode(Resources::str($events[0]->context), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($context));

    return ['race' => $race, 'packId' => $pack->pack->id->value, 'cardsDeleted' => $context['cards_deleted'] ?? null];
}

it('deletes a Card created while the Pack deletion waited for its lock, and counts it: cards_deleted is exactly the Cards deleted', function () {
    ['race' => $race, 'packId' => $packId, 'cardsDeleted' => $cardsDeleted] = racePackDeletionAgainstCardCreation(narrowed: false);

    expect($race['blocked'])->toBeTrue('the deletion did not wait for the creation to commit')
        ->and($race['exit'])->toBe(0, 'the deletion failed: '.($race['class'] ?? '?'))
        ->and(DB::table('resource_packs')->where('id', $packId)->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(Resources::events())->toHaveCount(1)
        ->and($cardsDeleted)->toBe(2, 'the event under-counted the Cards the deletion removed');
});

it('removes the audience rows of a narrowed Card created while the Pack deletion waited: the deletion succeeds and nothing is left behind', function () {
    ['race' => $race, 'packId' => $packId, 'cardsDeleted' => $cardsDeleted] = racePackDeletionAgainstCardCreation(narrowed: true);

    expect($race['blocked'])->toBeTrue('the deletion did not wait for the creation to commit')
        // Without the current read the late Card's id is missing from the cleanup list, its audience row survives, and deleting the
        // Card hits the RESTRICT foreign key: the deletion fails instead of succeeding.
        ->and($race['exit'])->toBe(0, 'the deletion failed: '.($race['class'] ?? '?'))
        ->and(DB::table('resource_packs')->where('id', $packId)->count())->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('resource_card_audiences')->count())->toBe(0)
        ->and(DB::table('resource_pack_audiences')->count())->toBe(0)
        ->and(Resources::events())->toHaveCount(1)
        ->and($cardsDeleted)->toBe(2, 'the event under-counted the Cards the deletion removed');
});

// --- Managed files (WP3) ---------------------------------------------------------------------------------------------------------
//
// A file is written before its transaction and removed only after one commits, and the asset a Card points at is read under the
// Pack's lock (and the Card's row) by a current read. Whatever order these commit in, the end state has exactly one asset row per File
// Card, every stored file is referenced (nothing leaks), and nothing referenced was removed. Replacement does not use the revision
// (ADR 0037, decision 56): two replacements serialise and the later one wins.

/** @return array{ManagedPackView, ManagedCardView} a Draft Pack holding one Draft File Card */
function filePackForRace(Actor $by): array
{
    $pack = Resources::pack($by, 'Files');

    return [$pack, ResourceFiles::card($by, $pack, 'The file', 'first.pdf', ResourceFiles::pdf('first'))];
}

/** @return array<string, string> the worker's arguments to replace a Card's file with `$bytes` named `$name` */
function replaceArguments(Actor $by, ManagedPackView $pack, ManagedCardView $card, string $name, string $bytes): array
{
    return [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value, 'name' => $name, 'bytes' => base64_encode($bytes)];
}

it('serialises two replacements of one file: the later wins, and neither the first file nor the middle one is left behind', function () {
    $by = Resources::editor();
    [$pack, $card] = filePackForRace($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            ResourcesPauses::afterCard('replaceAsset', $pause); // the swap is written, not committed
            app(ReplaceCardFile::class)($by, $pack->pack->id, $card->card->id, ResourceFiles::incoming('second.pdf', ResourceFiles::pdf('second')));
        },
        null, 'resources_replace_file', replaceArguments($by, $pack, $card, 'third.pdf', ResourceFiles::pdf('third')),
    );

    $asset = DB::table('resource_assets')->first();
    expect($race['blocked'])->toBeTrue('the second replacement did not wait for the first to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('resource_assets')->count())->toBe(1)
        ->and(Resources::str($asset?->original_filename))->toBe('third.pdf')
        ->and(Resources::str(DB::table('resource_cards')->value('asset_id')))->toBe(Resources::str($asset?->id))
        ->and(ResourceFiles::stored())->toBe([Resources::str($asset?->storage_key)])
        ->and(ResourceFiles::storedBytes(Resources::str($asset?->storage_key)))->toBe(ResourceFiles::pdf('third'))
        ->and(DB::table('security_events')->count())->toBe(0);
});

it('serialises deleting a File Card against replacing its file: the replacement finds it gone and removes the file it wrote', function () {
    $by = Resources::editor();
    [$pack, $card] = filePackForRace($by);

    $race = Race::against(
        fn () => app(DeleteCard::class)($by, $pack->pack->id, $card->card->id),
        'resource.card_deleted', 'resources_replace_file', replaceArguments($by, $pack, $card, 'late.pdf', ResourceFiles::pdf('late')),
    );

    expect($race['blocked'])->toBeTrue('the replacement did not wait for the deletion to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CardNotFound::class)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([])
        ->and(DB::table('security_events')->where('type', 'resource.card_deleted')->count())->toBe(1);
});

it('serialises replacing a file against deleting its Card: the deletion removes the NEW asset and its file, leaving nothing', function () {
    $by = Resources::editor();
    [$pack, $card] = filePackForRace($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            ResourcesPauses::afterCard('replaceAsset', $pause);
            app(ReplaceCardFile::class)($by, $pack->pack->id, $card->card->id, ResourceFiles::incoming('second.pdf', ResourceFiles::pdf('second')));
        },
        null, 'resources_delete_card', [...resourcesActor($by), 'pack' => $pack->pack->id->value, 'card' => $card->card->id->value],
    );

    $events = DB::table('security_events')->where('type', 'resource.card_deleted')->get()->all();
    expect($race['blocked'])->toBeTrue('the deletion did not wait for the replacement to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('resource_cards')->count())->toBe(0)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([])
        ->and($events)->toHaveCount(1)
        ->and(json_decode(Resources::str($events[0]->context), true))->toBe(['card_id' => $card->card->id->value, 'pack_id' => $pack->pack->id->value, 'card_type' => 'file']);
});

it('serialises replacing a file against deleting its Pack: the deletion counts and removes the NEW asset, even on MariaDB\'s snapshot', function () {
    $by = Resources::editor();
    [$pack, $card] = filePackForRace($by);

    $race = Race::against(
        function (Closure $pause) use ($by, $pack, $card) {
            ResourcesPauses::afterCard('replaceAsset', $pause);
            app(ReplaceCardFile::class)($by, $pack->pack->id, $card->card->id, ResourceFiles::incoming('second.pdf', ResourceFiles::pdf('second')));
        },
        null, 'resources_delete_pack', [...resourcesActor($by), 'pack' => $pack->pack->id->value],
    );

    $event = DB::table('security_events')->where('type', 'resource.pack_deleted')->first();
    expect($race['blocked'])->toBeTrue('the Pack deletion did not wait for the replacement to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('resource_packs')->count())->toBe(0)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([])
        ->and(json_decode(Resources::str($event?->context), true))->toBe(['pack_id' => $pack->pack->id->value, 'cards_deleted' => 1, 'files_deleted' => 1]);
});

it('serialises deleting a Pack against replacing a file in it: the replacement finds the Pack gone and removes the file it wrote', function () {
    $by = Resources::editor();
    [$pack, $card] = filePackForRace($by);

    $race = Race::against(
        fn () => app(DeletePack::class)($by, $pack->pack->id),
        'resource.pack_deleted', 'resources_replace_file', replaceArguments($by, $pack, $card, 'late.pdf', ResourceFiles::pdf('late')),
    );

    expect($race['blocked'])->toBeTrue('the replacement did not wait for the Pack deletion to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(PackNotFound::class)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([])
        ->and(json_decode(Resources::str(DB::table('security_events')->where('type', 'resource.pack_deleted')->value('context')), true))
        ->toBe(['pack_id' => $pack->pack->id->value, 'cards_deleted' => 1, 'files_deleted' => 1]);
});
