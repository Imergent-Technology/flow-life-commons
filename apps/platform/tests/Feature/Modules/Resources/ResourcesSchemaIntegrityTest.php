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

/** @return array<string, mixed> an asset row, as the store would have written its file */
function schemaAsset(string $id): array
{
    return [
        'id' => $id, 'storage_key' => $id, 'original_filename' => 'a.pdf', 'media_type' => 'application/pdf', 'byte_size' => 10,
        'sha256' => str_repeat('a', 64), 'uploaded_by_person_id' => ulid(), 'created_at' => '2026-10-04 12:00:00',
    ];
}

it('creates exactly the six Resources tables, the sixth being WP3\'s assets, and none for history, trash, folders or audiences-as-entities', function () {
    $tables = array_values(array_filter(Resources::allTables(), fn (string $name): bool => str_starts_with($name, 'resource')));
    sort($tables);

    expect($tables)->toBe(['resource_assets', 'resource_card_audiences', 'resource_cards', 'resource_categories', 'resource_pack_audiences', 'resource_packs']);
});

it('gives each asset to at most one Card: a second Card naming the same asset is refused, while any number of Cards have none', function () {
    DB::table('resource_packs')->insert(schemaPack($pack = ulid()));
    DB::table('resource_assets')->insert(schemaAsset($asset = ulid()));
    DB::table('resource_cards')->insert([...schemaCard(ulid(), $pack, 1), 'type' => 'file', 'asset_id' => $asset]);

    resourcesSchemaRefuses(fn () => DB::table('resource_cards')->insert([...schemaCard(ulid(), $pack, 2), 'type' => 'file', 'asset_id' => $asset]), UniqueConstraintViolationException::class);
    DB::table('resource_cards')->insert([schemaCard(ulid(), $pack, 3), schemaCard(ulid(), $pack, 4)]); // NULL twice is not a duplicate, on either engine

    expect(DB::table('resource_cards')->whereNull('asset_id')->count())->toBe(2);
});

it('refuses a Card naming an asset that does not exist, and refuses to delete an asset a Card still names (RESTRICT, never cascade)', function () {
    DB::table('resource_packs')->insert(schemaPack($pack = ulid()));
    resourcesSchemaRefuses(fn () => DB::table('resource_cards')->insert([...schemaCard(ulid(), $pack), 'type' => 'file', 'asset_id' => ulid()]), QueryException::class);

    DB::table('resource_assets')->insert(schemaAsset($asset = ulid()));
    DB::table('resource_cards')->insert([...schemaCard($card = ulid(), $pack), 'type' => 'file', 'asset_id' => $asset]);
    resourcesSchemaRefuses(fn () => DB::table('resource_assets')->where('id', $asset)->delete(), QueryException::class);
    DB::table('resource_cards')->where('id', $card)->delete();
    DB::table('resource_assets')->where('id', $asset)->delete();

    expect(DB::table('resource_assets')->count())->toBe(0);
});

it('keeps storage keys unique and holds a file size past 2 GiB', function () {
    DB::table('resource_assets')->insert(schemaAsset($asset = ulid()));
    resourcesSchemaRefuses(fn () => DB::table('resource_assets')->insert([...schemaAsset(ulid()), 'storage_key' => $asset]), UniqueConstraintViolationException::class);

    DB::table('resource_assets')->insert([...schemaAsset($big = ulid()), 'byte_size' => 5_000_000_000]);
    expect(Resources::int(DB::table('resource_assets')->where('id', $big)->value('byte_size')))->toBe(5_000_000_000);
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

    foreach (['resource_categories', 'resource_packs', 'resource_cards', 'resource_assets'] as $table) {
        foreach (schemaForeignKeys($table) as $foreignKey) {
            $columns = implode(',', $foreignKey['columns']);
            expect($columns)->not->toContain('person');
        }
    }
    expect(DB::table('resource_packs')->count())->toBe(1);
});

it('has no cross-module foreign key and no cascade anywhere: every Resources foreign key points inside Resources and is RESTRICT', function () {
    $all = [];
    foreach (['resource_packs', 'resource_pack_audiences', 'resource_cards', 'resource_card_audiences', 'resource_categories', 'resource_assets'] as $table) {
        foreach (schemaForeignKeys($table) as $fk) {
            expect(str_starts_with($fk['foreign_table'], 'resource'))->toBeTrue("{$table} -> {$fk['foreign_table']}")
                ->and($fk['on_delete'])->toBeIn(['restrict', 'no action'], "{$table} delete rule");
            $all[] = "{$table}.".implode(',', $fk['columns'])." -> {$fk['foreign_table']}";
        }
    }
    sort($all);

    // The asset is owned FROM the Card; an asset row points at nothing, so it can never block anything but its own deletion.
    expect($all)->toBe([
        'resource_card_audiences.card_id -> resource_cards',
        'resource_cards.asset_id -> resource_assets',
        'resource_cards.pack_id -> resource_packs',
        'resource_pack_audiences.pack_id -> resource_packs',
        'resource_packs.category_id -> resource_categories',
    ]);
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

it('has exactly the columns of the contract and none a later package would add: no path, URL, folder, tag, history or presentation column', function () {
    // Column ORDER is not compared: `asset_id` was added by WP3's migration, which (portably) does not position it.
    expect(Schema::getColumnListing('resource_cards'))->toEqualCanonicalizing([
        'id', 'pack_id', 'position', 'type', 'title', 'summary_mode', 'summary_text', 'content_format', 'content_version', 'content_document',
        'external_uri', 'asset_id', 'audience_mode', 'state', 'revision', 'created_by_person_id', 'updated_by_person_id', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('resource_assets'))->toBe([
        'id', 'storage_key', 'original_filename', 'media_type', 'byte_size', 'sha256', 'uploaded_by_person_id', 'created_at',
    ])->and(Schema::getColumnListing('resource_pack_audiences'))->toBe(['pack_id', 'audience'])
        ->and(Schema::getColumnListing('resource_card_audiences'))->toBe(['card_id', 'audience']);
});

it('indexes what the asset contract names: the storage key and a Card\'s asset, each unique', function () {
    $unique = function (string $table): array {
        $columns = [];
        foreach (Schema::getIndexes($table) as $index) {
            assert(is_array($index) && is_array($index['columns'] ?? null));
            if (($index['unique'] ?? false) === true && ($index['primary'] ?? false) !== true) {
                $columns[] = implode(',', array_map(fn (mixed $c): string => Resources::str($c), $index['columns']));
            }
        }

        return $columns;
    };

    expect($unique('resource_assets'))->toBe(['storage_key'])
        ->and($unique('resource_cards'))->toContain('asset_id');
});

it('stores instants in UTC datetime columns written by the domain, with no database default or on-update trigger', function () {
    foreach (['resource_categories', 'resource_packs', 'resource_cards', 'resource_assets'] as $table) {
        $columns = schemaColumns($table);
        foreach ($table === 'resource_assets' ? ['created_at'] : ['created_at', 'updated_at'] as $name) {
            expect($columns[$name]['type_name'])->toBeIn(['datetime', 'timestamp'])
                ->and($columns[$name]['default'])->toBeNull();
        }
    }
});
