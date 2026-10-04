<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Resources;

/*
 * What the Resources schema itself guarantees, whatever the application does: the invariants that must hold even if a use case
 * forgot to check (ADR 0037). Runs on MariaDB and PostgreSQL (`./flow test backend --pgsql`).
 */

/**
 * @param  Closure(): mixed  $statement
 * @param  class-string<Throwable>  $exception
 */
function resourcesSchemaRefuses(Closure $statement, string $exception): void
{
    // A savepoint, so a statement the database refuses cannot poison PostgreSQL's surrounding (test) transaction.
    expect(fn (): mixed => DB::transaction($statement))->toThrow($exception);
}

/**
 * A table's columns as the schema builder reports them, narrowed to what these tests read: the engine's type name, lower-cased, and the default.
 *
 * @return array<string, array{type_name: string, default: string|null}>
 */
function schemaColumns(string $table): array
{
    $columns = [];
    foreach (Schema::getColumns($table) as $column) {
        assert(is_array($column) && is_string($column['name'] ?? null) && is_string($column['type_name'] ?? null));
        $default = $column['default'] ?? null;
        $columns[$column['name']] = ['type_name' => strtolower($column['type_name']), 'default' => is_string($default) ? $default : null];
    }

    return $columns;
}

/**
 * A table's foreign keys, narrowed to the table each points at, its delete rule and its local columns.
 *
 * @return list<array{foreign_table: string, on_delete: string, columns: list<string>}>
 */
function schemaForeignKeys(string $table): array
{
    $keys = [];
    foreach (Schema::getForeignKeys($table) as $key) {
        assert(is_array($key) && is_string($key['foreign_table'] ?? null) && is_string($key['on_delete'] ?? null) && is_array($key['columns'] ?? null));
        $keys[] = ['foreign_table' => $key['foreign_table'], 'on_delete' => strtolower($key['on_delete']), 'columns' => array_values(array_map(fn (mixed $c): string => Resources::str($c), $key['columns']))];
    }

    return $keys;
}

function ulid(): string
{
    return strtolower((string) Str::ulid());
}

/** @return array<string, mixed> */
function schemaCategory(string $id, string $name = 'Guides', int $position = 1): array
{
    return ['id' => $id, 'name' => $name, 'name_canonical' => mb_strtolower($name), 'position' => $position, 'created_by_person_id' => ulid(), 'updated_by_person_id' => ulid(), 'created_at' => '2026-10-04 12:00:00', 'updated_at' => '2026-10-04 12:00:00'];
}

/** @return array<string, mixed> */
function schemaPack(string $id, ?string $category = null, int $position = 1): array
{
    return ['id' => $id, 'category_id' => $category, 'position' => $position, 'title' => 'T', 'summary' => null, 'is_series' => false, 'state' => 'draft', 'revision' => 1, 'created_by_person_id' => ulid(), 'updated_by_person_id' => ulid(), 'created_at' => '2026-10-04 12:00:00', 'updated_at' => '2026-10-04 12:00:00'];
}

/** @return array<string, mixed> */
function schemaCard(string $id, string $pack, int $position = 1): array
{
    return [
        'id' => $id, 'pack_id' => $pack, 'position' => $position, 'type' => 'basic', 'title' => 'T', 'summary_mode' => 'derived', 'summary_text' => '',
        'content_format' => 'prosemirror', 'content_version' => 1, 'content_document' => '{"type":"doc","content":[]}', 'external_uri' => null,
        'audience_mode' => 'inherit', 'state' => 'draft', 'revision' => 1, 'created_by_person_id' => ulid(), 'updated_by_person_id' => ulid(),
        'created_at' => '2026-10-04 12:00:00', 'updated_at' => '2026-10-04 12:00:00',
    ];
}

it('creates exactly the five Resources tables of this package, and none for files, assets, history, trash or audiences-as-entities', function () {
    $tables = array_values(array_filter(Resources::allTables(), fn (string $name): bool => str_starts_with($name, 'resource')));
    sort($tables);

    expect($tables)->toBe(['resource_card_audiences', 'resource_cards', 'resource_categories', 'resource_pack_audiences', 'resource_packs']);
});

it('refuses a Pack in a Category that does not exist, and refuses to delete a Category a Pack still holds (RESTRICT, never cascade)', function () {
    $category = ulid();
    resourcesSchemaRefuses(fn () => DB::table('resource_packs')->insert(schemaPack(ulid(), ulid())), QueryException::class);

    DB::table('resource_categories')->insert(schemaCategory($category));
    DB::table('resource_packs')->insert(schemaPack($pack = ulid(), $category));
    resourcesSchemaRefuses(fn () => DB::table('resource_categories')->where('id', $category)->delete(), QueryException::class);

    expect(DB::table('resource_packs')->where('id', $pack)->count())->toBe(1);
});

it('refuses a Card in a Pack that does not exist, and refuses to delete a Pack that still holds Cards or audience rows', function () {
    resourcesSchemaRefuses(fn () => DB::table('resource_cards')->insert(schemaCard(ulid(), ulid())), QueryException::class);

    DB::table('resource_packs')->insert(schemaPack($pack = ulid()));
    DB::table('resource_cards')->insert(schemaCard($card = ulid(), $pack));
    DB::table('resource_pack_audiences')->insert(['pack_id' => $pack, 'audience' => 'guardian']);

    resourcesSchemaRefuses(fn () => DB::table('resource_packs')->where('id', $pack)->delete(), QueryException::class);
    DB::table('resource_cards')->where('id', $card)->delete();
    resourcesSchemaRefuses(fn () => DB::table('resource_packs')->where('id', $pack)->delete(), QueryException::class); // the audience row still holds it
    DB::table('resource_pack_audiences')->where('pack_id', $pack)->delete();
    DB::table('resource_packs')->where('id', $pack)->delete();

    expect(DB::table('resource_packs')->count())->toBe(0);
});

it('refuses an audience row for a Pack or Card that does not exist, a duplicate audience, and deleting a Card that has narrowing rows', function () {
    resourcesSchemaRefuses(fn () => DB::table('resource_pack_audiences')->insert(['pack_id' => ulid(), 'audience' => 'guardian']), QueryException::class);
    resourcesSchemaRefuses(fn () => DB::table('resource_card_audiences')->insert(['card_id' => ulid(), 'audience' => 'guardian']), QueryException::class);

    DB::table('resource_packs')->insert(schemaPack($pack = ulid()));
    DB::table('resource_cards')->insert(schemaCard($card = ulid(), $pack));
    DB::table('resource_pack_audiences')->insert(['pack_id' => $pack, 'audience' => 'guardian']);
    DB::table('resource_card_audiences')->insert(['card_id' => $card, 'audience' => 'guardian']);

    resourcesSchemaRefuses(fn () => DB::table('resource_pack_audiences')->insert(['pack_id' => $pack, 'audience' => 'guardian']), UniqueConstraintViolationException::class);
    resourcesSchemaRefuses(fn () => DB::table('resource_card_audiences')->insert(['card_id' => $card, 'audience' => 'guardian']), UniqueConstraintViolationException::class);
    resourcesSchemaRefuses(fn () => DB::table('resource_cards')->where('id', $card)->delete(), QueryException::class);
});

it('keeps Category names unique by their canonical form, on both engines', function () {
    DB::table('resource_categories')->insert(schemaCategory(ulid(), 'Guides'));

    resourcesSchemaRefuses(fn () => DB::table('resource_categories')->insert(schemaCategory(ulid(), 'GUIDES', 2)), UniqueConstraintViolationException::class);
});

it('does NOT make a position unique: ties are legal and (position, id) is the total order', function () {
    DB::table('resource_categories')->insert([schemaCategory(ulid(), 'One', 1), schemaCategory(ulid(), 'Two', 1)]);
    DB::table('resource_packs')->insert([schemaPack(ulid(), null, 1), schemaPack(ulid(), null, 1)]);

    expect(DB::table('resource_categories')->count())->toBe(2)->and(DB::table('resource_packs')->count())->toBe(2);
});

it('holds provenance as plain Person ids with no foreign key: a creator need not exist, and nothing blocks removing a Person', function () {
    // The created_by columns are provenance (ADR 0021). A made-up id is accepted, so no Person merge or anonymisation can be
    // blocked by, or forced to rewrite, what people authored.
    DB::table('resource_categories')->insert(schemaCategory(ulid()));
    DB::table('resource_packs')->insert(schemaPack(ulid()));

    foreach (['resource_categories', 'resource_packs', 'resource_cards'] as $table) {
        foreach (schemaForeignKeys($table) as $foreignKey) {
            $columns = implode(',', $foreignKey['columns']);
            expect($columns)->not->toContain('person');
        }
    }
    expect(DB::table('resource_packs')->count())->toBe(1);
});

it('has no cross-module foreign key and no cascade anywhere: every Resources foreign key points inside Resources and is RESTRICT', function () {
    foreach (['resource_packs', 'resource_pack_audiences', 'resource_cards', 'resource_card_audiences', 'resource_categories'] as $table) {
        foreach (schemaForeignKeys($table) as $fk) {
            expect(str_starts_with($fk['foreign_table'], 'resource'))->toBeTrue("{$table} -> {$fk['foreign_table']}")
                ->and($fk['on_delete'])->toBeIn(['restrict', 'no action'], "{$table} delete rule");
        }
    }
});

it('stores the document as text, never as a database json type, and holds a document of the full 256 KiB', function () {
    $type = schemaColumns('resource_cards')['content_document']['type_name'];

    expect($type)->not->toContain('json')->and($type)->toBeIn(['mediumtext', 'text']);

    DB::table('resource_packs')->insert(schemaPack($pack = ulid()));
    $document = str_repeat('x', 262144);
    DB::table('resource_cards')->insert([...schemaCard($card = ulid(), $pack), 'content_document' => $document]);

    expect(strlen(Resources::str(DB::table('resource_cards')->where('id', $card)->value('content_document'))))->toBe(262144);
});

it('uses validated strings, not database enums, for state, type, modes and audiences: the domain judges them', function () {
    foreach (['resource_packs', 'resource_cards'] as $table) {
        foreach (schemaColumns($table) as $column) {
            expect($column['type_name'])->not->toContain('enum');
        }
    }
    // The database accepts a value the domain would refuse: that is the portable choice (charter rule 13), so reading one is the
    // domain's problem to fail closed on (a repository maps through the enum), not a constraint the other engine lacks.
    DB::table('resource_packs')->insert([...schemaPack(ulid()), 'state' => 'archived']);
    expect(DB::table('resource_packs')->where('state', 'archived')->count())->toBe(1);
});

it('has the columns of this package and none a later one would add: no asset, file, tag, history or presentation column', function () {
    expect(Schema::getColumnListing('resource_cards'))->toBe([
        'id', 'pack_id', 'position', 'type', 'title', 'summary_mode', 'summary_text', 'content_format', 'content_version', 'content_document',
        'external_uri', 'audience_mode', 'state', 'revision', 'created_by_person_id', 'updated_by_person_id', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('resource_pack_audiences'))->toBe(['pack_id', 'audience'])
        ->and(Schema::getColumnListing('resource_card_audiences'))->toBe(['card_id', 'audience']);
});

it('stores instants in UTC datetime columns written by the domain, with no database default or on-update trigger', function () {
    foreach (['resource_categories', 'resource_packs', 'resource_cards'] as $table) {
        $columns = schemaColumns($table);
        foreach (['created_at', 'updated_at'] as $name) {
            expect($columns[$name]['type_name'])->toBeIn(['datetime', 'timestamp'])
                ->and($columns[$name]['default'])->toBeNull();
        }
    }
});
