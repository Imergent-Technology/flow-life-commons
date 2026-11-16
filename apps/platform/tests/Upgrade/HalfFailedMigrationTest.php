<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Tests\Support\HalfFailedShape as Shape;
use Tests\Support\HistoricalFixture as Fixture;
use Tests\Support\HistoricalSnapshot as Snapshot;
use Tests\Support\UpgradeDatabase as Upgrade;

/*
 * G6 WP3c S2.2C-1. The half-failed upgrade the recovery rehearsal produces on purpose, measured here on a real MariaDB with the real
 * migration runner, so that the rehearsal's expectations (scripts/rehearsal/restore-rehearsal.sh, `half-fail-upgrade` and the third starting
 * state of `restore-historical`) are not predictions.
 *
 * The mechanism. Production is at v0.3.0. A stub table named `person_relationship_field_values` (the THIRD table the Relationships migration
 * creates) is placed in the v0.3.0 database, and the 14 pending migrations are run. Nothing in any migration is changed. The ten migrations
 * before Relationships create 13 tables and commit; the Relationships migration creates `person_relationships` and
 * `person_relationship_status_changes` and then fails on the third `Schema::create`, because the table exists. MariaDB commits every DDL
 * statement as it goes and Laravel wraps no MySQL schema change in a transaction, so the two tables stay, and the runner never records the
 * migration that failed.
 *
 * What is proved:
 *  - the failure is an Illuminate\Database\QueryException, SQLSTATE 42S01, driver error 1050, on a `create table` of the stub's name;
 *  - the ledger holds the 15 historical migrations (batch 1) and the 10 that completed (batch 2) and not Relationships, the rename or anything after;
 *  - the schema is the 16 historical tables, the 13 tables of the ten completed migrations, the first two Relationships tables and the stub: 32 tables;
 *    `person_relationship_role_provisions` and `sourced_role_grants` do not exist; the stub has the one column it was made with and no row;
 *  - every historical row is as the fixture left it, the ten migration records apart;
 *  - the 15 committed tables are what a full upgrade makes, for 14 of them exactly; `contact_methods` is what its creating migration left, WITHOUT the
 *    `updated_by_account_id` column the 29th migration (never reached) adds, and the rehearsal's comparison (Tests\Support\HalfFailedShape, the logic
 *    the helper runs) accepts that, rejects the fully upgraded table, and rejects an unrelated deviation;
 *  - running `migrate` again does not repair anything: it fails the same way (on the FIRST table, which now exists) and changes neither the
 *    ledger nor one table.
 *
 * The statement is tests/Fixtures/rehearsal/half-failed-stub.sql; scripts/tests/rehearsal.sh holds the rehearsal script's copy equal to it.
 * MariaDB only: the rehearsal is MariaDB, and PostgreSQL wraps each migration in a transaction (the failed one would leave nothing).
 *
 * `./flow test backend -- tests/Upgrade/HalfFailedMigrationTest.php`
 */

const HALF_FAILED_STUB = __DIR__.'/../Fixtures/rehearsal/half-failed-stub.sql';

/**
 * The migrations that complete before the failure: the first ten after v0.3.0.
 *
 * @return list<string>
 */
function completedBeforeRelationships(): array
{
    return array_slice(Fixture::UPGRADE_MIGRATIONS, 0, 10);
}

/** The 15 tables the completed work leaves, besides the stub. Written out, not derived. */
const HALF_FAILED_NEW_TABLES = [
    'contact_interactions', 'contact_methods', 'contact_profiles', 'contact_tag_assignments', 'contact_tags', 'discussion_messages', 'discussions',
    'person_relationship_status_changes', 'person_relationships', 'resource_assets', 'resource_card_audiences', 'resource_cards',
    'resource_categories', 'resource_pack_audiences', 'resource_packs',
];

/**
 * The historical database with the stub placed in it, every historical row loaded, and what those rows were.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function halfFailedBaseline(): array
{
    Upgrade::clean();
    Upgrade::migrateHistorical();
    $db = Upgrade::connect();
    Fixture::load($db, app('encrypter'));
    $db->getPdo()->exec((string) file_get_contents(HALF_FAILED_STUB)); // a file's statement: the driver's own exec, as PreflightQueriesTest does

    return Snapshot::take($db);
}

/** What `migrate` threw, or null if it completed. */
function halfFailedAttempt(): ?Throwable
{
    try {
        Upgrade::migratePending();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** The table a failed `create table` statement was creating. */
function createdTable(Throwable $failure): string
{
    Assert::assertInstanceOf(QueryException::class, $failure);

    return preg_match('/^create table `([a-z_]+)`/i', $failure->getSql(), $match) === 1 ? $match[1] : '(not a create table)';
}

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mariadb') {
        Assert::markTestSkipped('The half-failed upgrade is a MariaDB behaviour: PostgreSQL runs each migration in a transaction.');
    }
});

beforeAll(function () {
    gc_collect_cycles(); // the earlier suites' connections (see Upgrade::release)
});

afterEach(function () {
    Upgrade::clean();
    Upgrade::release();
});

afterAll(function () {
    Upgrade::cleanVerified();
});

it('has a stub statement that is one CREATE TABLE of the third Relationships table, with a marker comment and no key', function () {
    $sql = trim((string) file_get_contents(HALF_FAILED_STUB));

    expect($sql)->toBe("CREATE TABLE `person_relationship_field_values` (`stub` CHAR(1) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='flc-rehearsal-stub-s22c1';")
        ->and(substr_count($sql, ';'))->toBe(1)
        // The real migration creates this table third: it is the failure point the rehearsal means, not another.
        ->and((string) file_get_contents(database_path('migrations/2026_10_09_000001_create_person_relationships_tables.php')))
        ->toMatch('/Schema::create\(\'person_relationships\'.*Schema::create\(\'person_relationship_status_changes\'.*Schema::create\(\'person_relationship_field_values\'/s');
});

it('fails the Relationships migration on its third table with a real 1050, after the first two were committed and ten migrations before them', function () {
    $before = halfFailedBaseline();
    $failure = halfFailedAttempt();
    $db = Upgrade::connect();

    expect($failure)->toBeInstanceOf(QueryException::class)
        ->and($failure?->getCode())->toBe('42S01')
        ->and($failure instanceof QueryException ? $failure->errorInfo : null)->toMatchArray([0 => '42S01', 1 => 1050])
        ->and($failure === null ? '' : createdTable($failure))->toBe('person_relationship_field_values')
        ->and($failure?->getPrevious())->toBeInstanceOf(PDOException::class);

    // The ledger: the 15 of v0.3.0 and the ten that completed. Not Relationships, the rename or the two after it.
    $ran = Upgrade::ran();
    expect($ran)->toBe([...Upgrade::HISTORICAL, ...completedBeforeRelationships()])
        ->and($ran)->toHaveCount(25)
        ->and($ran)->not->toContain('2026_10_09_000001_create_person_relationships_tables.php')
        ->and(Upgrade::batches())->toBe([...array_fill(0, 15, 1), ...array_fill(0, 10, 2)]);

    // The schema: partly built, exactly as far as the work got. Neither later table exists.
    $expected = [...array_keys(Snapshot::TABLES), ...HALF_FAILED_NEW_TABLES, 'person_relationship_field_values'];
    sort($expected, SORT_STRING);
    expect(Upgrade::tables())->toBe($expected)
        ->and(Upgrade::tables())->toHaveCount(32)
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('person_relationships'))->toBeTrue()
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('person_relationship_status_changes'))->toBeTrue()
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('person_relationship_role_provisions'))->toBeFalse()
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('sourced_role_grants'))->toBeFalse();

    // The stub is still the stub: one column, no row; the migration changed nothing about it. The two committed tables are the migration's.
    expect(array_column(Schema::connection(Upgrade::CONNECTION)->getColumns('person_relationship_field_values'), 'name'))->toBe(['stub'])
        ->and($db->table('person_relationship_field_values')->count())->toBe(0)
        ->and(Schema::connection(Upgrade::CONNECTION)->hasColumn('person_relationships', 'revision'))->toBeTrue();
    foreach (HALF_FAILED_NEW_TABLES as $table) {
        expect($db->table($table)->count())->toBe(0);
    }

    // No historical row moved: the only difference is the ten migration records.
    expect(Snapshot::differences(Snapshot::expected($before, [], completedBeforeRelationships(), 2), Snapshot::take($db)))->toBe([]);
});

it('does not repair the partial state: a second migrate fails the same way on the first table and changes neither the ledger nor a table', function () {
    halfFailedBaseline();
    expect(halfFailedAttempt())->toBeInstanceOf(QueryException::class);
    $db = Upgrade::connect();
    $ranAfterFirst = Upgrade::ran();
    $tablesAfterFirst = Upgrade::tables();
    $structureAfterFirst = Snapshot::structure(Upgrade::CONNECTION);
    $rowsAfterFirst = Snapshot::take($db);

    $second = halfFailedAttempt();

    expect($second)->toBeInstanceOf(QueryException::class)
        ->and($second?->getCode())->toBe('42S01')
        ->and($second instanceof QueryException ? $second->errorInfo : null)->toMatchArray([0 => '42S01', 1 => 1050])
        // The Relationships migration is the first pending one and it starts at its FIRST table, which exists now.
        ->and($second === null ? '' : createdTable($second))->toBe('person_relationships')
        ->and(Upgrade::ran())->toBe($ranAfterFirst)
        ->and(Upgrade::tables())->toBe($tablesAfterFirst)
        ->and(Snapshot::structure(Upgrade::CONNECTION))->toBe($structureAfterFirst)
        ->and(Snapshot::take($db))->toBe($rowsAfterFirst);
});

it('is NOT a state the application can step out of without a restore: nothing here removes the stub or the two tables', function () {
    halfFailedBaseline();
    halfFailedAttempt();

    // `migrate:status` reports the Relationships migration pending: the ledger says it never ran although two of its tables exist.
    $pending = collect(Upgrade::pendingNames())->diff(Upgrade::ran())->values()->all();
    expect($pending)->toBe(array_slice(Fixture::UPGRADE_MIGRATIONS, 10))
        ->and($pending)->toHaveCount(4)
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('person_relationships'))->toBeTrue();
});

/** The digest of a table's columns, indexes and foreign keys as the engine reports them: what the rehearsal's structure comparison hashes. */
function tableStructureDigest(string $table): string
{
    $schema = Schema::connection(Upgrade::CONNECTION);
    $shape = ['columns' => $schema->getColumns($table), 'indexes' => $schema->getIndexes($table), 'foreign_keys' => $schema->getForeignKeys($table)];
    foreach ($shape as &$list) {
        usort($list, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
    }
    unset($list);

    return hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR));
}

/**
 * What a state looks like for the structure comparison: each committed table's digest, and contact_methods in the comparison's portable terms.
 *
 * @return array{digests: array<string, string>, shape: array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign_keys: array<string, array<string, mixed>>}, tables: list<string>}
 */
function committedStructure(): array
{
    $digests = [];
    foreach (HALF_FAILED_NEW_TABLES as $table) {
        $digests[$table] = tableStructureDigest($table);
    }

    return ['digests' => $digests, 'shape' => Shape::portable(Upgrade::connect()), 'tables' => Upgrade::tables()];
}

it('finds contact_methods in a half-failed database without the provenance column a full upgrade adds, and nothing else of the 15 committed tables different', function () {
    // The FULL upgrade first: all 14 pending migrations, so the 29th has run.
    Upgrade::clean();
    Upgrade::migrateHistorical();
    Upgrade::migratePending();
    $full = committedStructure();
    expect($full['tables'])->toHaveCount(34)
        ->and(Upgrade::ran())->toHaveCount(29)
        ->and($full['shape']['columns'])->toHaveKey(Shape::LATER_COLUMN)
        ->and($full['shape']['columns'][Shape::LATER_COLUMN])->toMatchArray(['type' => 'char(26)', 'nullable' => true]);

    // The half-failed database, the way the rehearsal makes it.
    halfFailedBaseline();
    expect(halfFailedAttempt())->toBeInstanceOf(QueryException::class);
    $half = committedStructure();
    expect($half['tables'])->toHaveCount(32)
        ->and($half['shape']['columns'])->not->toHaveKey(Shape::LATER_COLUMN);

    // The intended difference is exactly one thing: the later migration's column. The old comparison (all 15 tables equal to the first upgrade's)
    // refuses this real state, on contact_methods and on nothing else; the 14 others are identical.
    $differing = array_keys(array_filter($half['digests'], static fn (string $digest, string $table): bool => $full['digests'][$table] !== $digest, ARRAY_FILTER_USE_BOTH));
    expect($differing)->toBe(['contact_methods']);
    $unchanged = array_values(array_diff(HALF_FAILED_NEW_TABLES, ['contact_methods']));
    expect($unchanged)->toHaveCount(14);
    foreach ($unchanged as $table) {
        expect($half['digests'][$table])->toBe($full['digests'][$table]);
    }

    // The upgraded table is the half-failed one plus that one column, and no other column, index or key moved.
    $withoutLater = $full['shape'];
    unset($withoutLater['columns'][Shape::LATER_COLUMN]);
    expect($withoutLater)->toBe($half['shape']);

    // The corrected comparison: the real half-failed table passes, and the fully upgraded one does not (for that column).
    // ... and the written-out expectation is the real structure, not an approximation of it.
    expect($half['shape'])->toBe(Shape::expected())
        ->and(Shape::differences($half['shape']))->toBe([])
        ->and(Shape::differences($full['shape']))->toBe(['unexpected column '.Shape::LATER_COLUMN])
        ->and(Shape::check(Upgrade::connect()))->toBe('ok');
});

/**
 * A shape with one column, index or foreign key replaced, added (a definition) or removed (null).
 *
 * @param  array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign_keys: array<string, array<string, mixed>>}  $shape
 * @param  'columns'|'indexes'|'foreign_keys'  $section
 * @param  array<string, mixed>|null  $definition
 * @return array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreign_keys: array<string, array<string, mixed>>}
 */
function deviated(array $shape, string $section, string $name, ?array $definition): array
{
    if ($definition === null) {
        unset($shape[$section][$name]);
    } else {
        $shape[$section][$name] = $definition;
    }

    return $shape;
}

it('still rejects an unrelated structural deviation in contact_methods, in the comparison and on a real half-failed table', function () {
    halfFailedBaseline();
    halfFailedAttempt();
    $db = Upgrade::connect();
    $real = Shape::portable($db);
    expect(Shape::differences($real))->toBe([]);

    $deviations = [
        'an extra column' => ['columns', 'surprise', $real['columns']['label'], 'unexpected column surprise'],
        'a missing column' => ['columns', 'label', null, 'missing column label'],
        'a column of another type' => ['columns', 'label', [...$real['columns']['label'], 'type' => 'varchar(65)'], 'different column label'],
        'a column made nullable' => ['columns', 'kind', [...$real['columns']['kind'], 'nullable' => true], 'different column kind'],
        'a missing index' => ['indexes', 'contact_methods_kind_search_value_index', null, 'missing index contact_methods_kind_search_value_index'],
        'an index that is no longer unique' => [
            'indexes', 'contact_methods_one_primary_per_kind_unique', [...$real['indexes']['contact_methods_one_primary_per_kind_unique'], 'unique' => false],
            'different index contact_methods_one_primary_per_kind_unique',
        ],
        'a foreign key that cascades' => [
            'foreign_keys', 'contact_methods_person_id_foreign', [...$real['foreign_keys']['contact_methods_person_id_foreign'], 'on_delete' => 'cascade'],
            'different foreign key contact_methods_person_id_foreign',
        ],
        'no foreign key' => ['foreign_keys', 'contact_methods_person_id_foreign', null, 'missing foreign key contact_methods_person_id_foreign'],
        'the later provenance column' => [
            'columns', Shape::LATER_COLUMN, ['type' => 'char(26)', 'type_name' => 'char', 'nullable' => true, 'default' => 'NULL', 'auto_increment' => false],
            'unexpected column '.Shape::LATER_COLUMN,
        ],
    ];
    foreach ($deviations as $name => [$section, $key, $definition, $expected]) {
        expect(Shape::differences(deviated($real, $section, $key, $definition)))->toBe([$expected], $name);
    }

    // On the real table: a changed column, then an added one. The comparison names each and nothing else.
    $db->getPdo()->exec('ALTER TABLE `contact_methods` MODIFY `label` VARCHAR(65) NULL');
    expect(Shape::check($db))->toBe('different column label');
    $db->getPdo()->exec('ALTER TABLE `contact_methods` ADD COLUMN `surprise` CHAR(1) NULL');
    expect(Shape::check($db))->toBe('different column label, unexpected column surprise');
});
