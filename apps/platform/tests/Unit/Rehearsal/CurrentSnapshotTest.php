<?php

declare(strict_types=1);

use Tests\Support\CurrentSnapshot as Snapshot;
use Tests\Support\CurrentSnapshotRefusal;
use Tests\Support\HistoricalFixture;
use Tests\Support\HistoricalSnapshot;

/*
 * G6 WP3c S2.2C-3 WU4. The current-schema snapshot of the recovery rehearsal (Tests\Support\CurrentSnapshot, run in the rehearsal by `rehearse.php snapshot-all`),
 * the part of it that needs no database: the 34-table inventory and its 27/7 classification, the primary-key ordering, the digest, the ledger evidence and the
 * protocol lines. What it does to a real, migrated database is tests/Feature/Rehearsal/CurrentSnapshotDatabaseTest.php.
 *
 * Every safeguard in CurrentSnapshot is one line ending `// guard:S-<name>`. The matrix at the bottom copies the class, replaces each marked line with `;`,
 * loads the copy under another name, and requires that the scenario written for that guard no longer ends in that guard's refusal. A marker with no scenario,
 * and a scenario with no marker, fail. The same arrangement as ResourceFileArchiveTest's, which this file does not depend on.
 *
 * `./flow test backend -- tests/Unit/Rehearsal/CurrentSnapshotTest.php`
 */

const S22C3_SNAPSHOT_FILE = __DIR__.'/../../Support/CurrentSnapshot.php';

/** @return array<string, string> every one of the 34 tables as a base table, as information_schema lists them */
function s22c3_types(): array
{
    return array_fill_keys(Snapshot::TABLES, 'BASE TABLE');
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function s22c3_digest(array $rows): string
{
    return Snapshot::digest($rows);
}

it('names exactly the 34 tables of the current schema, sorted and once each: the 16 of v0.3.0 and the 18 the upgrade adds', function (): void {
    $historical = array_keys(HistoricalSnapshot::TABLES);
    $newer = HistoricalFixture::NEW_TABLES;
    $all = [...$historical, ...$newer];
    sort($all, SORT_STRING);

    expect(Snapshot::TABLES)->toHaveCount(34)
        ->and(Snapshot::TABLES)->toBe($all)
        ->and(count(array_unique(Snapshot::TABLES)))->toBe(34)
        ->and(count($historical))->toBe(16)
        ->and(count($newer))->toBe(18);
    $sorted = Snapshot::TABLES;
    sort($sorted, SORT_STRING);
    expect(Snapshot::TABLES)->toBe($sorted);
});

it('classifies 7 tables as transient and 27 as durable, and the transient ones are the historical ones the backup keeps the structure of', function (): void {
    $durable = Snapshot::durable();

    expect(Snapshot::TRANSIENT)->toHaveCount(7)
        ->and($durable)->toHaveCount(27)
        ->and(array_values(array_intersect($durable, Snapshot::TRANSIENT)))->toBe([])
        ->and(count(array_unique([...$durable, ...Snapshot::TRANSIENT])))->toBe(34)
        ->and(array_diff(Snapshot::TRANSIENT, array_keys(HistoricalSnapshot::TABLES)))->toBe([])
        ->and($durable)->toContain('migrations', 'people', 'resource_assets', 'sourced_role_grants')
        ->and($durable)->not->toContain('sessions', 'cache', 'jobs');
    $nine = array_values(array_diff(array_keys(HistoricalSnapshot::TABLES), Snapshot::TRANSIENT));
    expect($nine)->toHaveCount(9);
});

it('accepts the inventory of the current schema, and only that', function (): void {
    Snapshot::requireInventory(s22c3_types());
    expect(true)->toBeTrue();
});

it('orders the rows of a table by the columns of its primary key, whichever other index comes first', function (): void {
    $columns = Snapshot::orderColumns('t', [
        ['name' => 't_x_index', 'columns' => ['x'], 'primary' => false, 'unique' => false],
        ['name' => 'primary', 'columns' => ['a', 'b'], 'primary' => true, 'unique' => true],
    ]);

    expect($columns)->toBe(['a', 'b']);
});

it('digests the rows as text: 3 and \'3\' alike, and NULL, another value, a lost row and a different order all different', function (): void {
    $digest = s22c3_digest(...);

    expect($digest([['a' => 3, 'b' => null]]))->toBe($digest([['a' => '3', 'b' => null]]))
        ->and($digest([['a' => 3]]))->not->toBe($digest([['a' => null]]))
        ->and($digest([['a' => 3]]))->not->toBe($digest([['a' => 4]]))
        ->and($digest([['a' => 3]]))->not->toBe($digest([]))
        ->and($digest([['a' => 1], ['a' => 2]]))->not->toBe($digest([['a' => 2], ['a' => 1]]))
        ->and($digest([]))->toBe(hash('sha256', '[]'));
});

it('records a ledger as its count, its last migration and a digest of every name in order', function (): void {
    $names = ['0001_01_01_000001_create_cache_table', '2026_09_19_000001_create_people_table', '2026_10_11_000001_add_provenance_to_contact_methods'];
    $evidence = Snapshot::evidence($names);

    expect($evidence['count'])->toBe(3)
        ->and($evidence['last'])->toBe('2026_10_11_000001_add_provenance_to_contact_methods')
        ->and($evidence['digest'])->toBe(hash('sha256', implode("\n", $names)))
        ->and(Snapshot::evidence(array_reverse($names))['digest'])->not->toBe($evidence['digest'])
        ->and(Snapshot::requireLedger($names))->toBe($names);
});

it('prints one all-table line for each of the 34 tables, by name, and nothing a row holds', function (): void {
    $measured = [];
    foreach (Snapshot::TABLES as $i => $table) {
        $measured[$table] = ['rows' => $i, 'digest' => hash('sha256', $table)];
    }
    $lines = explode("\n", rtrim(Snapshot::lines($measured), "\n"));

    expect($lines)->toHaveCount(34)
        ->and($lines[0])->toBe('all-table account_invitations 0 '.hash('sha256', 'account_invitations'))
        ->and($lines[33])->toBe('all-table sourced_role_grants 33 '.hash('sha256', 'sourced_role_grants'));
    foreach ($lines as $line) {
        expect($line)->toMatch('/^all-table [a-z_]+ [0-9]+ [0-9a-f]{64}$/');
    }
});

/**
 * One scenario for each guard: [a fragment of the refusal's message, a closure given the class and expected to end in it].
 *
 * @return array<string, array{0: string, 1: Closure(class-string<Snapshot>): mixed}>
 */
function s22c3_scenarios(): array
{
    $types = s22c3_types();
    $measured = [];
    foreach (Snapshot::TABLES as $table) {
        $measured[$table] = ['rows' => 0, 'digest' => hash('sha256', $table)];
    }
    $primary = ['name' => 'primary', 'columns' => ['id'], 'primary' => true, 'unique' => true];

    return [
        'S-missing' => ['are missing from the database: people', function (string $c) use ($types): void {
            unset($types['people']);
            $c::requireInventory($types);
        }],
        'S-unexpected' => ['does not have are in the database: personal_access_tokens', function (string $c) use ($types): void {
            $c::requireInventory([...$types, 'personal_access_tokens' => 'BASE TABLE']);
        }],
        'S-base-table' => ['accounts is a VIEW, not a base table', function (string $c) use ($types): void {
            $types['accounts'] = 'VIEW';
            $c::requireInventory($types);
        }],
        'S-primary-key' => ['no single primary key', fn (string $c) => $c::orderColumns('people', [['name' => 'people_x', 'columns' => ['id'], 'primary' => false]])],
        'S-primary-columns' => ['names no column', fn (string $c) => $c::orderColumns('people', [[...$primary, 'columns' => []]])],
        'S-ledger-empty' => ['ledger is empty', fn (string $c) => $c::requireLedger([])],
        'S-ledger-names' => ['holds a name that is not a migration', fn (string $c) => $c::requireLedger(['0001_01_01_000001_create_cache_table', 'not a migration'])],
        'S-entry' => ['has no entry for sourced_role_grants', function (string $c) use ($measured): void {
            unset($measured['sourced_role_grants']);
            $c::lines($measured);
        }],
    ];
}

/** @return list<string> the guard IDs marked in the class's source */
function s22c3_guards(string $source): array
{
    preg_match_all('#// guard:(S-[a-z0-9-]+)$#m', $source, $matches);

    return array_values(array_unique($matches[1]));
}

function s22c3_outcome(Closure $run): ?Throwable
{
    try {
        $run();
    } catch (Throwable $failure) {
        return $failure;
    }

    return null;
}

it('has a scenario for every guard and a guard for every scenario', function (): void {
    $guards = s22c3_guards((string) file_get_contents(S22C3_SNAPSHOT_FILE));
    $scenarios = array_keys(s22c3_scenarios());
    sort($guards);
    sort($scenarios);

    expect(array_values(array_diff($guards, $scenarios)))->toBe([], 'guards with no scenario')
        ->and(array_values(array_diff($scenarios, $guards)))->toBe([], 'scenarios with no guard');
});

it('ends every scenario in its guard\'s refusal when the class is as committed', function (): void {
    $wrong = [];
    foreach (s22c3_scenarios() as $guard => [$fragment, $scenario]) {
        $outcome = s22c3_outcome(fn () => $scenario(Snapshot::class));
        if (! $outcome instanceof CurrentSnapshotRefusal) {
            $wrong[] = "$guard: expected a refusal, got ".($outcome === null ? 'none' : $outcome::class.': '.$outcome->getMessage());
        } elseif (! str_contains($outcome->getMessage(), $fragment)) {
            $wrong[] = "$guard: refused for another reason: {$outcome->getMessage()}";
        }
    }

    expect($wrong)->toBe([]);
});

it('is told by a scenario when a guard is removed: no scenario still ends in its guard\'s refusal without it', function (): void {
    $source = (string) file_get_contents(S22C3_SNAPSHOT_FILE);
    $directory = sys_get_temp_dir().'/flc-s22c3-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $survivors = [];
    $mutations = 0;
    try {
        foreach (s22c3_scenarios() as $guard => [$fragment, $scenario]) {
            $lines = explode("\n", $source);
            $marked = array_keys(array_filter($lines, static fn (string $line): bool => str_ends_with($line, '// guard:'.$guard)));
            expect($marked)->toHaveCount(1, "$guard is marked on exactly one line");
            $lines[$marked[0]] = ';';
            $name = 'CurrentSnapshotMutant'.(++$mutations);
            $copy = $directory.'/'.$name.'.php';
            file_put_contents($copy, str_replace('final class CurrentSnapshot', 'final class '.$name, implode("\n", $lines)));
            require_once $copy;
            /** @var class-string<Snapshot> $class */
            $class = 'Tests\\Support\\'.$name;

            $outcome = s22c3_outcome(fn () => $scenario($class));
            if ($outcome instanceof CurrentSnapshotRefusal && str_contains($outcome->getMessage(), $fragment)) {
                $survivors[] = "$guard (still: {$outcome->getMessage()})";
            }
        }
    } finally {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    expect($survivors)->toBe([], 'guards whose removal changed nothing the scenario notices');
});
