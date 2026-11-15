<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Assert;
use Tests\Support\HistoricalFixture as Fixture;
use Tests\Support\HistoricalSnapshot as Snapshot;
use Tests\Support\UpgradeDatabase as Upgrade;

/*
 * G6 WP3-10 and WP3-12. The read-only pre-flight a production operator runs against the v0.3.0 database before the next release's
 * migrations (docs/runbooks/g10-release-readiness.md). The SQL is the file tests/Fixtures/preflight/v030-preflight.sql, run here
 * VERBATIM: `scripts/tests/preflight-sql.sh` (./flow check repo) holds the runbook's copy byte for byte equal to it, so what the operator
 * pastes is what this file ran.
 *
 * What is proved, against the WP3b historical fixture (the real v0.3.0 schema, literal v0.3.0-era rows):
 *  - the file holds exactly the ten statements the runbook describes, and none can change anything: each is a SELECT apart from the one
 *    `SET SESSION TRANSACTION READ ONLY`, no mutating keyword appears outside a string literal, and the checker that says so rejects a
 *    set of mutating statements (so it is not vacuous);
 *  - run in full, it changes no row, no table and no migration record; and the server itself refuses a write in the session the first
 *    statement set up, so the file is read-only by enforcement and not only by reading;
 *  - it reports the fixture's role keys by exact bytes, the exact `guardian` holders apart from the case, space and accent variants, the
 *    holders by Account status (invited, disabled and Account-less included), no conflict on the unmodified fixture, and the collations;
 *  - on a database with the conflicts planted (a collation-equal `guardian-full` variant or an exact one on a Person who holds
 *    `guardian`, and an exact `guardian-full` on someone else) it names each one, and MariaDB's own unique key agrees that each is a
 *    duplicate, independently of the SQL's `=`.
 *
 * MariaDB only: HEX() and the collation behaviour it reports do not exist on PostgreSQL, and the production database is MariaDB.
 * It does not run against production or staging, and nothing here changes the migration, which is the behaviour WP3a and WP3b pin.
 *
 * `./flow test backend -- tests/Upgrade/PreflightQueriesTest.php`
 */

const PREFLIGHT_SQL = __DIR__.'/../Fixtures/preflight/v030-preflight.sql';

/** What each statement's `check_name` is, in file order: the runbook's interpretation table is written against these labels. */
const PREFLIGHT_CHECKS = [
    '1 server and database',
    '2 role keys by exact bytes',
    '3 same-Person conflicts',
    '4 exact guardian-full on anyone',
    '5 guardian-role holders by Account status',
    '6 guardian-role holders',
    '7a table collations',
    '7b column character sets and collations',
    '7c columns the new foreign keys and the rename depend on',
];

/** The 16 tables of the v0.3.0 schema. */
const PREFLIGHT_TABLES = [
    'account_invitations', 'account_recovery_codes', 'account_totp_factors', 'accounts', 'cache', 'cache_locks', 'failed_jobs', 'job_batches',
    'jobs', 'membership_grants', 'migrations', 'password_reset_tokens', 'people', 'role_assignments', 'security_events', 'sessions',
];

/** The file's text without its comment lines. */
function preflightText(string $sql): string
{
    $kept = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (! str_starts_with(ltrim($line), '--')) {
            $kept[] = $line;
        }
    }

    return implode("\n", $kept);
}

/**
 * The statements of the file, in order, without terminators or comment lines.
 *
 * @return list<string>
 */
function preflightStatements(string $sql): array
{
    $statements = [];
    foreach (explode(';', preflightText($sql)) as $piece) {
        $piece = trim($piece);
        if ($piece !== '') {
            $statements[] = $piece;
        }
    }

    return $statements;
}

/**
 * Why a statement could change something, or is not one the pre-flight allows. Empty means it is a plain read (or the one read-only SET).
 *
 * @return list<string>
 */
function preflightProblems(string $statement): array
{
    $problems = [];
    $bare = preg_replace("/'(?:[^']|'')*'/", "''", $statement) ?? $statement; // string literals are text, not SQL
    $isReadOnlySet = preg_match('/^SET\s+SESSION\s+TRANSACTION\s+READ\s+ONLY$/i', $statement) === 1;

    if (! $isReadOnlySet && preg_match('/^SELECT\b/i', $statement) !== 1) {
        $problems[] = 'it is neither a SELECT nor the one SET SESSION TRANSACTION READ ONLY';
    }
    if (substr_count($statement, "'") % 2 !== 0) {
        $problems[] = 'it has an unbalanced quote';
    }
    if (! $isReadOnlySet && preg_match_all('/\b(SET|INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|LOCK|UNLOCK|LOAD|CALL|HANDLER|DO|PREPARE|EXECUTE|DEALLOCATE|INTO|OUTFILE|DUMPFILE|FOR|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|SHUTDOWN|KILL|FLUSH|ANALYZE|OPTIMIZE|REPAIR|START|COMMIT|ROLLBACK|BEGIN)\b/i', $bare, $found) > 0) {
        $problems[] = 'it contains '.implode(', ', array_unique(array_map('strtoupper', $found[1])));
    }

    return $problems;
}

/**
 * The fixture's database, built once for the file: the v0.3.0 schema from its 15 migrations and the WP3b rows. Nothing is upgraded.
 */
function preflightDatabase(): Connection
{
    static $built = false;

    if (! $built) {
        Upgrade::clean();
        Upgrade::migrateHistorical();
        Fixture::load(Upgrade::connect(), app('encrypter'));
        $built = true;
    }

    // Each test has a fresh application, which has forgotten the upgrade connection: register and verify it again (the data stays).
    return Upgrade::connect();
}

/**
 * Runs the file as an operator would, statement by statement on one connection, and returns what each SELECT reported, keyed by its
 * `check_name` and without that column. Every value is a string (or null), so a driver that returns 2 or '2' reads the same.
 *
 * @return array<string, list<array<string, string|null>>>
 */
function preflightRun(Connection $db): array
{
    $results = [];
    foreach (preflightStatements((string) file_get_contents(PREFLIGHT_SQL)) as $statement) {
        if (preg_match('/^SET\b/i', $statement) === 1) {
            $db->getPdo()->exec($statement); // the file's own text, as the mysql client would send it

            continue;
        }
        $name = null;
        $rows = [];
        foreach ($db->select($statement) as $row) {
            $values = [];
            foreach ((array) $row as $column => $value) {
                if ($column === 'check_name') {
                    $name = is_scalar($value) ? (string) $value : null;

                    continue;
                }
                $values[(string) $column] = is_scalar($value) ? (string) $value : null;
            }
            $rows[] = $values;
        }
        if ($name === null) {
            // No row came back, so the label is the statement's own literal.
            preg_match("/^SELECT\\s+'([^']+)'\\s+AS\\s+check_name/i", $statement, $label);
            $name = $label[1] ?? throw new RuntimeException('A pre-flight statement has no check_name.');
        }
        $results[$name] = $rows;
    }

    return $results;
}

/**
 * Runs the file in a session the file itself made read-only, then puts the session back so the test can write again.
 *
 * @return array<string, list<array<string, string|null>>>
 */
function preflightRunReadOnly(Connection $db): array
{
    try {
        return preflightRun($db);
    } finally {
        $db->unprepared('SET SESSION TRANSACTION READ WRITE');
    }
}

/**
 * Rows in a fixed order, so a comparison does not depend on how the engine sorted equal-looking keys.
 *
 * @param  list<array<string, string|null>>  $rows
 * @return list<array<string, string|null>>
 */
function preflightSorted(array $rows): array
{
    usort($rows, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

    return $rows;
}

/** The uppercase hex of a key, as the SQL's HEX() writes it: computed in PHP, not by the SQL under test. */
function preflightHex(string $key): string
{
    return strtoupper(bin2hex($key));
}

/**
 * @param  array<int, array{int, string}>  $assignments  assignment number => [person number, role key]
 * @return list<string> the ids planted
 */
function preflightPlant(Connection $db, array $assignments): array
{
    $ids = [];
    foreach ($assignments as $number => [$person, $key]) {
        $db->table('role_assignments')->insert([
            'id' => Fixture::assignment($number), 'person_id' => Fixture::person($person), 'role_key' => $key,
            'granted_by_account_id' => Fixture::account(1), 'granted_at' => '2026-09-30 09:00:00',
        ]);
        $ids[] = Fixture::assignment($number);
    }

    return $ids;
}

beforeEach(function () {
    if (config('database.default') !== 'mariadb') {
        Assert::markTestSkipped('The pre-flight is MariaDB SQL (HEX, the collation behaviour it reports); production is MariaDB.');
    }
});

beforeAll(function () {
    gc_collect_cycles(); // the earlier suites' connections (see Upgrade::release)
});

afterEach(function () {
    Upgrade::release();
});

afterAll(function () {
    Upgrade::cleanVerified();
});

it('is the ten statements the runbook describes: one read-only SET, then the labelled SELECTs', function () {
    $sql = (string) file_get_contents(PREFLIGHT_SQL);
    $statements = preflightStatements($sql);

    expect($statements)->toHaveCount(10)
        ->and(preg_match('/^SET\s+SESSION\s+TRANSACTION\s+READ\s+ONLY$/i', $statements[0]))->toBe(1)
        ->and(substr_count(preflightText($sql), ';'))->toBe(10, 'a semicolon inside a statement or a string would split it differently in the mysql client');

    $labels = [];
    foreach (array_slice($statements, 1) as $statement) {
        expect(preg_match("/^SELECT\\s+'([^']+)'\\s+AS\\s+check_name\\b/i", $statement, $label))->toBe(1, "no label: {$statement}");
        $labels[] = $label[1] ?? '';
    }
    expect($labels)->toBe(PREFLIGHT_CHECKS);
});

it('contains no statement that can change anything', function () {
    foreach (preflightStatements((string) file_get_contents(PREFLIGHT_SQL)) as $statement) {
        expect(preflightProblems($statement))->toBe([], $statement);
    }
});

it('rejects mutating statements, so the check above is not vacuous', function (string $statement) {
    expect(preflightProblems($statement))->not->toBe([], $statement);
})->with([
    'insert' => 'INSERT INTO role_assignments (id) VALUES (1)',
    'update' => "UPDATE role_assignments SET role_key = 'guardian-full'",
    'delete' => 'DELETE FROM accounts',
    'replace' => 'REPLACE INTO people (id) VALUES (1)',
    'drop' => 'DROP TABLE people',
    'alter' => 'ALTER TABLE people ADD x INT',
    'create' => 'CREATE TABLE x (a INT)',
    'truncate' => 'TRUNCATE people',
    'rename' => 'RENAME TABLE people TO p',
    'grant' => "GRANT ALL ON *.* TO 'x'@'%'",
    'call' => 'CALL something()',
    'load' => "LOAD DATA INFILE '/etc/passwd' INTO TABLE people",
    'outfile' => "SELECT 1 INTO OUTFILE '/tmp/x'",
    'into a variable' => 'SELECT 1 INTO @x',
    'a locking read' => 'SELECT * FROM people FOR UPDATE',
    'a sleep' => 'SELECT SLEEP(5)',
    'a global set' => 'SET GLOBAL read_only = 0',
    'a read-write set' => 'SET SESSION TRANSACTION READ WRITE',
    'a mutation after a SELECT' => 'SELECT 1; DELETE FROM people',
    'a lowercase delete' => 'delete from people',
    'text that is not SQL we allow' => 'SHOW TABLES',
]);

it('does not mistake a word inside a string literal for a mutation', function () {
    expect(preflightProblems("SELECT 'collation-equal variant: MariaDB rejects the rename with error 1062' AS consequence"))->toBe([]);
});

it('reports the fixture\'s role keys by their exact bytes, with each variant apart from the key it resembles', function () {
    $result = preflightRunReadOnly(preflightDatabase())['2 role keys by exact bytes'];

    $row = static fn (string $key, string $kind, int $assignments, int $persons): array => [
        'key_hex' => preflightHex($key), 'key_text' => $key, 'kind' => $kind, 'assignments' => (string) $assignments, 'persons' => (string) $persons,
    ];

    expect(preflightSorted($result))->toBe(preflightSorted([
        $row('platform_administrator', 'exact platform_administrator', 2, 2),
        $row('guardian', 'exact guardian', 5, 5),
        $row('Guardian', 'variant of guardian', 1, 1),
        $row('GUARDIAN', 'variant of guardian', 1, 1),
        $row('guardian ', 'variant of guardian', 1, 1),
        $row('guardián', 'variant of guardian', 1, 1),
        $row('Guardian-Full', 'variant of guardian-full', 1, 1),
    ]));
});

it('finds no conflict and no exact guardian-full on the unmodified fixture, although it holds a guardian-full variant', function () {
    $result = preflightRunReadOnly(preflightDatabase());

    expect($result['3 same-Person conflicts'])->toBe([])
        ->and($result['4 exact guardian-full on anyone'])->toBe([]);
});

it('groups the guardian-role holders by Account status, with the variants and the Account-less apart', function () {
    $result = preflightRunReadOnly(preflightDatabase());
    $exact = 'exact guardian: renamed by the migration';
    $variant = 'variant: not renamed, grants nothing';

    // Gus (2) also holds platform_administrator. Ines (4) is invited, Dora (5) disabled, Nora (6) has no Account at all.
    expect(preflightSorted($result['5 guardian-role holders by Account status']))->toBe(preflightSorted([
        ['key_kind' => $exact, 'account_status' => 'active', 'persons' => '2', 'also_platform_administrator' => '1'],
        ['key_kind' => $exact, 'account_status' => 'invited', 'persons' => '1', 'also_platform_administrator' => '0'],
        ['key_kind' => $exact, 'account_status' => 'disabled', 'persons' => '1', 'also_platform_administrator' => '0'],
        ['key_kind' => $exact, 'account_status' => 'no Account', 'persons' => '1', 'also_platform_administrator' => '0'],
        ['key_kind' => $variant, 'account_status' => 'active', 'persons' => '4', 'also_platform_administrator' => '0'],
    ]));

    $holder = static fn (int $person, int $assignment, string $key, string $status): array => [
        'person_id' => Fixture::person($person), 'assignment_id' => Fixture::assignment($assignment), 'key_hex' => preflightHex($key),
        'account_status' => $status, 'granted_at' => '2026-09-21 09:'.str_pad((string) $assignment, 2, '0', STR_PAD_LEFT).':00',
    ];
    expect(preflightSorted($result['6 guardian-role holders']))->toBe(preflightSorted([
        $holder(2, 2, 'guardian', 'active'), $holder(3, 4, 'guardian', 'active'), $holder(4, 5, 'guardian', 'invited'),
        $holder(5, 6, 'guardian', 'disabled'), $holder(6, 7, 'guardian', 'no Account'),
        $holder(7, 8, 'Guardian', 'active'), $holder(8, 9, 'GUARDIAN', 'active'), $holder(9, 10, 'guardian ', 'active'), $holder(10, 11, 'guardián', 'active'),
    ]));
});

it('reports the server, the database and the collations the new foreign keys and the rename depend on', function () {
    $db = preflightDatabase();
    $result = preflightRunReadOnly($db);

    expect($result['1 server and database'])->toHaveCount(1)
        ->and($result['1 server and database'][0]['db'])->toBe(Upgrade::NAME)
        ->and($result['1 server and database'][0]['server_version'])->toContain('10.11')
        ->and($result['1 server and database'][0]['db_charset'])->toBe('utf8mb4');

    // The SQL orders by the collation, in which `_` sorts apart from ASCII; the set of tables is what matters.
    $tables = array_column($result['7a table collations'], 'table_name');
    sort($tables);
    expect($tables)->toBe(PREFLIGHT_TABLES)
        ->and(array_unique(array_column($result['7a table collations'], 'table_collation')))->toBe(['utf8mb4_unicode_ci'])
        ->and(array_unique(array_column($result['7a table collations'], 'engine')))->toBe(['InnoDB']);

    // 62 text columns in the table collation, and one in utf8mb4_bin: MariaDB's JSON type is a LONGTEXT in that collation, and
    // `security_events.context` is the one JSON column of the v0.3.0 schema (named independently of the SQL under test, below).
    expect(preflightSorted($result['7b column character sets and collations']))->toBe(preflightSorted([
        ['character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_bin', 'column_count' => '1'],
        ['character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_unicode_ci', 'column_count' => '62'],
    ]));
    $binary = $db->select("select table_name, column_name from information_schema.columns where table_schema = database() and collation_name = 'utf8mb4_bin'");
    expect(array_map(static fn (mixed $row): array => (array) $row, $binary))->toBe([['table_name' => 'security_events', 'column_name' => 'context']]);

    $columns = [];
    foreach ($result['7c columns the new foreign keys and the rename depend on'] as $row) {
        $columns[$row['table_name'].'.'.$row['column_name']] = [$row['character_set_name'], $row['collation_name']];
    }
    expect($columns)->toBe([
        'accounts.id' => ['utf8mb4', 'utf8mb4_unicode_ci'],
        'accounts.person_id' => ['utf8mb4', 'utf8mb4_unicode_ci'],
        'membership_grants.person_id' => ['utf8mb4', 'utf8mb4_unicode_ci'],
        'people.id' => ['utf8mb4', 'utf8mb4_unicode_ci'],
        'role_assignments.person_id' => ['utf8mb4', 'utf8mb4_unicode_ci'],
        'role_assignments.role_key' => ['utf8mb4', 'utf8mb4_unicode_ci'],
    ]);
});

it('changes no row, no table and no migration record', function () {
    $db = preflightDatabase();
    $before = [Snapshot::take($db), Upgrade::tables(), Upgrade::ran(), Upgrade::batches()];

    preflightRunReadOnly($db);

    expect([Snapshot::take($db), Upgrade::tables(), Upgrade::ran(), Upgrade::batches()])->toBe($before);
});

it('puts its session in read-only mode, so the server itself refuses a write', function () {
    $db = preflightDatabase();
    $first = preflightStatements((string) file_get_contents(PREFLIGHT_SQL))[0];
    $rows = $db->table('role_assignments')->count();

    $db->getPdo()->exec($first);
    try {
        expect(fn () => $db->table('role_assignments')->insert([
            'id' => Fixture::assignment(90), 'person_id' => Fixture::person(12), 'role_key' => 'guardian',
            'granted_by_account_id' => Fixture::account(1), 'granted_at' => '2026-09-30 09:00:00',
        ]))->toThrow(QueryException::class, 'READ ONLY');
    } finally {
        $db->unprepared('SET SESSION TRANSACTION READ WRITE');
    }

    expect($db->table('role_assignments')->count())->toBe($rows);
});

it('names every planted conflict, and MariaDB\'s own unique key agrees that each is a duplicate', function () {
    $db = preflightDatabase();
    // Persons 3, 4, 5 and 6 hold an exact `guardian`; person 2 too. Person 12 holds nothing.
    $ids = preflightPlant($db, [
        13 => [3, 'GUARDIAN-FULL'],
        14 => [4, 'guardian-full '],
        15 => [5, 'guardián-full'],
        16 => [6, 'Guardian-Full'],
        17 => [2, 'guardian-full'],
        18 => [12, 'guardian-full'],
    ]);

    try {
        // Independent of the SQL under test: the table's own unique key on (person_id, role_key) says each variant IS `guardian-full`.
        foreach ([3, 4, 5, 6, 2, 12] as $person) {
            expect(fn () => $db->table('role_assignments')->insert([
                'id' => Fixture::assignment(91), 'person_id' => Fixture::person($person), 'role_key' => 'guardian-full',
                'granted_by_account_id' => Fixture::account(1), 'granted_at' => '2026-09-30 09:00:00',
            ]))->toThrow(UniqueConstraintViolationException::class);
        }

        $result = preflightRunReadOnly($db);

        $variant = 'collation-equal variant: MariaDB rejects the rename with error 1062';
        $exact = 'exact guardian-full: the migration refuses to run';
        $conflict = static fn (int $person, int $guardian, int $other, string $key, string $consequence): array => [
            'person_id' => Fixture::person($person), 'guardian_assignment_id' => Fixture::assignment($guardian),
            'other_assignment_id' => Fixture::assignment($other), 'other_key_hex' => preflightHex($key), 'consequence' => $consequence,
        ];
        expect(preflightSorted($result['3 same-Person conflicts']))->toBe(preflightSorted([
            $conflict(3, 4, 13, 'GUARDIAN-FULL', $variant),
            $conflict(4, 5, 14, 'guardian-full ', $variant),
            $conflict(5, 6, 15, 'guardián-full', $variant),
            $conflict(6, 7, 16, 'Guardian-Full', $variant),
            $conflict(2, 2, 17, 'guardian-full', $exact),
        ]));

        // An exact guardian-full anywhere stops the migration, on a Person who holds no `guardian` too.
        expect(preflightSorted($result['4 exact guardian-full on anyone']))->toBe(preflightSorted([
            ['assignment_id' => Fixture::assignment(17), 'person_id' => Fixture::person(2), 'granted_at' => '2026-09-30 09:00:00'],
            ['assignment_id' => Fixture::assignment(18), 'person_id' => Fixture::person(12), 'granted_at' => '2026-09-30 09:00:00'],
        ]));
    } finally {
        $db->table('role_assignments')->whereIn('id', $ids)->delete();
    }

    expect($db->table('role_assignments')->count())->toBe(12);
});
