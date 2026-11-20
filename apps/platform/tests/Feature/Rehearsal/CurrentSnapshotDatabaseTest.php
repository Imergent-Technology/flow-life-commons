<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\CurrentSnapshot as Snapshot;
use Tests\Support\HistoricalFixture;
use Tests\Support\UpgradeDatabase;

/*
 * G6 WP3c S2.2C-3 WU4. The current-schema snapshot of the recovery rehearsal (Tests\Support\CurrentSnapshot, run by `rehearse.php snapshot-all`) measured on a REAL
 * database with the real migrations: the schema the application's own migrations make is exactly the 34 tables the snapshot expects, every table has one primary
 * key to read it in order of, the snapshot is the same when taken twice, and a change to the contents of a table, durable or transient, is a different digest for
 * that table and for no other. What the snapshot refuses with no database is tests/Unit/Rehearsal/CurrentSnapshotTest.php.
 *
 * MariaDB only (the rehearsal is MariaDB, and `database()` is its function); every test runs in the suite's transaction and leaves nothing behind.
 *
 * `./flow test backend -- tests/Feature/Rehearsal/CurrentSnapshotDatabaseTest.php`
 */

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mariadb') {
        Assert::markTestSkipped('the rehearsal snapshot is for MariaDB only');
    }
});

/** @return array<string, array{rows: int, digest: string}> */
function s22c3_take(): array
{
    return Snapshot::take(DB::connection());
}

function s22c3_person(string $id, string $name): void
{
    DB::table('people')->insert(['id' => $id, 'display_name' => $name, 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00']);
}

it('measures exactly the 34 tables the real migrations make, and each one is a base table with one primary key', function (): void {
    $measured = s22c3_take();

    expect(array_keys($measured))->toBe(Snapshot::TABLES)->and($measured)->toHaveCount(34);
    $schema = DB::connection()->getSchemaBuilder();
    foreach (Snapshot::TABLES as $table) {
        expect(Snapshot::orderColumns($table, $schema->getIndexes($table)))->not->toBe([], $table);
    }
    expect(Snapshot::orderColumns('contact_tag_assignments', $schema->getIndexes('contact_tag_assignments')))->toBe(['person_id', 'tag_id'])
        ->and(Snapshot::orderColumns('person_relationship_field_values', $schema->getIndexes('person_relationship_field_values')))->toBe(['relationship_id', 'field_key'])
        ->and(Snapshot::orderColumns('people', $schema->getIndexes('people')))->toBe(['id']);
});

it('records the 29 migrations the repository has as the ledger, in order, ending at the one that adds the provenance column', function (): void {
    $ledger = Snapshot::ledger(DB::connection());
    $expected = array_map(static fn (string $file): string => (string) preg_replace('/\.php$/', '', $file), [...UpgradeDatabase::HISTORICAL, ...HistoricalFixture::UPGRADE_MIGRATIONS]);
    $evidence = Snapshot::evidence($ledger);

    expect($ledger)->toBe($expected)
        ->and($evidence['count'])->toBe(29)
        ->and($evidence['last'])->toBe('2026_10_11_000001_add_provenance_to_contact_methods')
        ->and($evidence['digest'])->toBe(hash('sha256', implode("\n", $expected)));
});

it('gives the same snapshot every time it is taken of the same data', function (): void {
    expect(s22c3_take())->toBe(s22c3_take());
});

it('changes the digest of a durable table when a row is added, changed or removed, and of no other table', function (): void {
    $before = s22c3_take();
    expect($before['people']['rows'])->toBe(0);

    s22c3_person('01jcz000000000000000000001', 'Ada');
    $added = s22c3_take();
    expect($added['people']['rows'])->toBe(1)->and($added['people']['digest'])->not->toBe($before['people']['digest']);
    foreach (array_diff(Snapshot::TABLES, ['people']) as $table) {
        expect($added[$table])->toBe($before[$table], $table);
    }

    DB::table('people')->where('id', '01jcz000000000000000000001')->update(['display_name' => 'Ada ']);
    $changed = s22c3_take();
    expect($changed['people']['rows'])->toBe(1)
        ->and($changed['people']['digest'])->not->toBe($added['people']['digest']);
    expect($changed['people']['digest'])->not->toBe($before['people']['digest']);

    DB::table('people')->where('id', '01jcz000000000000000000001')->delete();
    expect(s22c3_take())->toBe($before);
});

it('changes the digest of other durable tables of other shapes when their contents change', function (): void {
    $before = s22c3_take();
    expect($before['resource_categories']['rows'])->toBe(0)->and($before['security_events']['rows'])->toBe(0);

    DB::table('resource_categories')->insert([
        'id' => '01jcz000000000000000000002', 'name' => 'Guides', 'name_canonical' => 'guides', 'position' => 1,
        'created_by_person_id' => '01jcz000000000000000000001', 'updated_by_person_id' => '01jcz000000000000000000001',
        'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00',
    ]);
    $added = s22c3_take();
    expect($added['resource_categories']['rows'])->toBe(1)->and($added['resource_categories']['digest'])->not->toBe($before['resource_categories']['digest']);

    DB::table('resource_categories')->update(['position' => 2]);
    expect(s22c3_take()['resource_categories']['digest'])->not->toBe($added['resource_categories']['digest']);
    expect(s22c3_take()['people'])->toBe($before['people']);
});

it('measures the transient tables too, so a session or a cache entry that appears during a backup is noticed', function (): void {
    $before = s22c3_take();

    DB::table('cache')->insert(['key' => 'flc-s22c3', 'value' => 'v', 'expiration' => 1_900_000_000]);
    $after = s22c3_take();

    expect($after['cache']['rows'])->toBe(1)->and($after['cache']['digest'])->not->toBe($before['cache']['digest'])
        ->and($after['sessions'])->toBe($before['sessions']);
});

it('does not depend on the order the rows were written in', function (): void {
    s22c3_person('01jcz000000000000000000003', 'Bo');
    s22c3_person('01jcz000000000000000000004', 'Cy');
    $first = s22c3_take()['people'];

    DB::table('people')->delete();
    s22c3_person('01jcz000000000000000000004', 'Cy');
    s22c3_person('01jcz000000000000000000003', 'Bo');

    expect(s22c3_take()['people'])->toBe($first);
});

/*
 * R1 of the WU4 review. The backup's dump check (scripts/rehearsal/restore-rehearsal.sh, current_dump_problem) accepts the lines of a CREATE TABLE body only in the forms the
 * schema uses, and scripts/tests/rehearsal.sh runs it over the fixture below. The fixture is what the migrated schema's own SHOW CREATE TABLE says, and mariadb-dump writes the
 * same text for the structure of a table, so the check is held to the real column, key and foreign-key forms. This test is what keeps the fixture honest: a migration that
 * changes a table makes it fail, and the remedy is to regenerate the fixture (the statements with AUTO_INCREMENT counters removed, each ending in `;`, in table order) and to
 * widen the check only if it then refuses the new form. It is NOT a probe of mariadb-dump, whose complete output has not been read (docs, "WU4").
 */
it('has the CREATE TABLE statements of the migrated schema in the fixture the dump check is tested against', function (): void {
    $text = '';
    foreach (Snapshot::TABLES as $table) {
        $row = (array) DB::selectOne("SHOW CREATE TABLE `$table`");
        $statement = $row['Create Table'] ?? null;
        Assert::assertIsString($statement);
        $text .= preg_replace('/ AUTO_INCREMENT=\d+/', '', $statement).";\n";
    }

    expect($text)->toBe((string) file_get_contents(__DIR__.'/../../Fixtures/rehearsal/current-create-tables.sql'));
});
