<?php

declare(strict_types=1);

use App\Modules\Resources\Application\CategoryNotEmpty;
use App\Modules\Resources\Application\CategoryNotFound;
use App\Modules\Resources\Application\CategoryView;
use App\Modules\Resources\Application\DeleteCategory;
use App\Modules\Resources\Application\DuplicateCategory;
use App\Modules\Resources\Application\ListCategories;
use App\Modules\Resources\Application\OrderMismatch;
use App\Modules\Resources\Application\RenameCategory;
use App\Modules\Resources\Application\ReorderCategories;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\InvalidResourceInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;
use Tests\Support\Resources;

/*
 * Categories (ADR 0037, decisions 6-10): global organizational metadata, a name and a manual place in the order. Not an audience
 * and not authorization. Runs on MariaDB and PostgreSQL.
 */

/**
 * @param  mixed  $ids  what a dataset closure built
 * @return list<CategoryId>
 */
function categoryIds(mixed $ids): array
{
    assert(is_array($ids));
    $out = [];
    foreach ($ids as $id) {
        assert($id instanceof CategoryId);
        $out[] = $id;
    }

    return $out;
}

/** @return list<string> the Category names in display order */
function categoryNames(): array
{
    return array_map(fn (CategoryView $c): string => $c->category->name, app(ListCategories::class)(Resources::editor()));
}

it('appends each new Category at the end of the order and lists them by position', function () {
    $by = Resources::editor();
    foreach (['Charter', 'Guides', 'Policies'] as $name) {
        Resources::category($by, $name);
    }

    expect(categoryNames())->toBe(['Charter', 'Guides', 'Policies'])
        ->and(Resources::ints(DB::table('resource_categories')->orderBy('position')->pluck('position')))->toBe([1, 2, 3]);
});

it('records who created it and when, as a Person id, and shows their name', function () {
    $by = Resources::editor();
    $view = Resources::category($by, 'Guides');

    expect($view->category->provenance->createdBy->equals($by->personId))->toBeTrue()
        ->and($view->category->provenance->updatedBy->equals($by->personId))->toBeTrue()
        ->and($view->createdBy->displayName)->toBe('Ed Editor')
        ->and($view->packCount)->toBe(0);
});

it('refuses a blank, over-long or control-character name', function (string $name) {
    expect(fn () => Resources::category(Resources::editor(), $name))->toThrow(InvalidResourceInput::class);
    expect(DB::table('resource_categories')->count())->toBe(0);
})->with(['blank' => '   ', 'empty' => '', 'too long' => str_repeat('a', 81), 'nul' => "Nul\0", 'bell' => "Bell\x07"]);

it('accepts exactly 80 characters and stores the name with its inner whitespace collapsed', function () {
    $by = Resources::editor();

    expect(mb_strlen(Resources::category($by, str_repeat('a', 80))->category->name))->toBe(80)
        ->and(Resources::category($by, "  Training   guides \n")->category->name)->toBe('Training guides')
        // Inner whitespace of any kind, a pasted newline or tab included, is one space: a name is a single line.
        ->and(Resources::category($by, "Two\nlines\tand\u{2028}tabs")->category->name)->toBe('Two lines and tabs');
});

it('treats names that differ only by case or spacing as one name, and says so', function () {
    $by = Resources::editor();
    Resources::category($by, 'Training guides');

    foreach (['training guides', 'TRAINING   GUIDES', ' Training  Guides '] as $again) {
        expect(fn () => Resources::category($by, $again))->toThrow(DuplicateCategory::class);
    }
    expect(DB::table('resource_categories')->count())->toBe(1);
});

it('lets the unique index decide when two creations of one name race, so the loser gets the same answer', function () {
    $by = Resources::editor();
    Resources::category($by, 'Guides');
    // Bypass the use case's own pre-check: plant the second row directly and ask the repository to refuse it.
    expect(fn () => DB::table('resource_categories')->insert([
        'id' => strtolower((string) Str::ulid()), 'name' => 'guides', 'name_canonical' => 'guides', 'position' => 9,
        'created_by_person_id' => $by->personId->value, 'updated_by_person_id' => $by->personId->value, 'created_at' => '2026-10-04 00:00:00', 'updated_at' => '2026-10-04 00:00:00',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('renames a Category, allows a change of case on its own name, and refuses another\'s name', function () {
    $by = Resources::editor();
    $guides = Resources::category($by, 'Guides');
    Resources::category($by, 'Policies');

    expect(app(RenameCategory::class)($by, $guides->category->id, 'Playbooks')->category->name)->toBe('Playbooks')
        ->and(app(RenameCategory::class)($by, $guides->category->id, 'PLAYBOOKS')->category->name)->toBe('PLAYBOOKS')
        ->and(fn () => app(RenameCategory::class)($by, $guides->category->id, 'policies'))->toThrow(DuplicateCategory::class)
        ->and(categoryNames())->toBe(['PLAYBOOKS', 'Policies']);
});

it('lets any manager rename it: the creator has no exclusive authority, and provenance says who last edited', function () {
    $first = Resources::editor('first.editor@example.org', 'First Editor');
    $second = Resources::editor('second.editor@example.org', 'Second Editor');
    $category = Resources::category($first, 'Guides');

    $renamed = app(RenameCategory::class)($second, $category->category->id, 'Playbooks');

    expect($renamed->category->provenance->createdBy->equals($first->personId))->toBeTrue()
        ->and($renamed->category->provenance->updatedBy->equals($second->personId))->toBeTrue()
        ->and($renamed->createdBy->displayName)->toBe('First Editor')
        ->and($renamed->updatedBy->displayName)->toBe('Second Editor');
});

it('answers category_not_found for an unknown Category', function () {
    $by = Resources::editor();
    $missing = CategoryId::generate();

    expect(fn () => app(RenameCategory::class)($by, $missing, 'X'))->toThrow(CategoryNotFound::class)
        ->and(fn () => app(DeleteCategory::class)($by, $missing))->toThrow(CategoryNotFound::class);
});

it('reorders ALL Categories from the complete ordered list, rewriting positions 1..n', function () {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;
    $c = Resources::category($by, 'C')->category->id;

    $views = app(ReorderCategories::class)($by, [$c, $a, $b]);

    expect(array_map(fn (CategoryView $v): string => $v->category->name, $views))->toBe(['C', 'A', 'B'])
        ->and(categoryNames())->toBe(['C', 'A', 'B'])
        ->and(Resources::ints(DB::table('resource_categories')->orderBy('position')->pluck('position')))->toBe([1, 2, 3]);
});

it('refuses a reorder that is not exactly the current set, and changes nothing', function (Closure $submit) {
    $by = Resources::editor();
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;
    $c = Resources::category($by, 'C')->category->id;
    $before = DB::table('resource_categories')->orderBy('id')->get()->map(fn (stdClass $r) => [$r->id, Resources::int($r->position)])->all();

    expect(fn () => app(ReorderCategories::class)($by, categoryIds($submit($a, $b, $c))))->toThrow(OrderMismatch::class);

    expect(DB::table('resource_categories')->orderBy('id')->get()->map(fn (stdClass $r) => [$r->id, Resources::int($r->position)])->all())->toBe($before);
})->with([
    'a missing id' => [fn ($a, $b, $c) => [$c, $a]],
    'an extra id' => [fn ($a, $b, $c) => [$c, $b, $a, CategoryId::generate()]],
    'a repeated id' => [fn ($a, $b, $c) => [$a, $a, $b]],
    'a repeated id instead of a missing one' => [fn ($a, $b, $c) => [$a, $b, $b]],
    'the full set plus a repeat' => [fn ($a, $b, $c) => [$a, $b, $c, $c]],
    'no ids' => [fn ($a, $b, $c) => []],
    'an unknown id in place of one' => [fn ($a, $b, $c) => [$a, $b, CategoryId::generate()]],
]);

it('does not treat ordering as editing: a reorder leaves provenance and updated_at alone', function () {
    $by = Resources::editor();
    $other = Resources::editor('other.editor@example.org', 'Other Editor');
    $a = Resources::category($by, 'A')->category->id;
    $b = Resources::category($by, 'B')->category->id;
    $before = DB::table('resource_categories')->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->updated_by_person_id, $r->updated_at])->all();

    app(ReorderCategories::class)($other, [$b, $a]);

    expect(DB::table('resource_categories')->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->updated_by_person_id, $r->updated_at])->all())->toBe($before);
});

it('orders by position and then id, a total order, with no unique position', function () {
    $by = Resources::editor();
    $x = Resources::category($by, 'X')->category->id;
    $y = Resources::category($by, 'Y')->category->id;
    // Force a tie: both at position 1. The list must still be deterministic, by id.
    DB::table('resource_categories')->update(['position' => 1]);

    $expected = collect([$x->value, $y->value])->sort()->values()->all();
    $listed = array_map(fn (CategoryView $v): string => $v->category->id->value, app(ListCategories::class)($by));

    expect($listed)->toBe($expected);
});

it('deletes an empty Category, and refuses one a Pack still holds, in any state, without reassigning anything', function () {
    $by = Resources::editor();
    $empty = Resources::category($by, 'Empty')->category->id;
    $held = Resources::category($by, 'Held')->category->id;
    $pack = Resources::pack($by, 'Draft pack', $held);

    app(DeleteCategory::class)($by, $empty);
    expect(categoryNames())->toBe(['Held']);

    expect(fn () => app(DeleteCategory::class)($by, $held))->toThrow(CategoryNotEmpty::class);
    // The Pack is still there, still in that Category: nothing was reassigned silently.
    expect(DB::table('resource_packs')->where('id', $pack->pack->id->value)->value('category_id'))->toBe($held->value);

    $published = Resources::published($by, title: 'Live');
    expect(fn () => app(DeleteCategory::class)($by, $published->category->id ?? throw new LogicException))->toThrow(CategoryNotEmpty::class);
});

it('counts the Packs that hold a Category, in any state', function () {
    $by = Resources::editor();
    $category = Resources::category($by, 'Guides')->category->id;
    Resources::pack($by, 'One', $category);
    Resources::pack($by, 'Two', $category);

    $listed = app(ListCategories::class)($by);

    expect($listed[0]->packCount)->toBe(2);
});

it('is not an audience: a Category carries no audience, role or capability field at all', function () {
    expect(Schema::getColumnListing('resource_categories'))->toBe(['id', 'name', 'name_canonical', 'position', 'created_by_person_id', 'updated_by_person_id', 'created_at', 'updated_at']);
});
