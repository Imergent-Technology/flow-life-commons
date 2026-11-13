<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Tests\Support\HistoricalFixture;
use Tests\Support\HistoricalSnapshot;
use Tests\Support\UpgradeDatabase;

// G6 WP3c slice 2.2A fixed helper, run inside the disposable runner by `restore-rehearsal.sh` (baseline, backup, upgrade, reproduce-wp3-01).
// One action per run, chosen by the first argument, and nothing else:
//
//   fixture-load   in the HISTORICAL tree: refuses unless the database is exactly the empty v0.3.0 schema, loads the synthetic v0.3.0
//                  fixture in ONE transaction, requires it complete, and prints a digest of every table (what it is now, and what the
//                  upgrade must make of it).
//   snapshot       in any tree: prints the same per-table digests for the 16 v0.3.0 tables as they are now. Read-only.
//   mark           in the CURRENT tree: refuses unless the database is exactly the upgraded schema, then adds one clearly synthetic
//                  post-upgrade Person and CRM profile, so a recovery can tell the upgraded state from the historical one.
//
// The fixture and the snapshot judgement are the WP3b test support (apps/platform/tests/Support), required from the current tree by path:
// the runner installs with --no-dev, so Composer's autoload-dev (`Tests\`) is not there, and nothing here changes the application's autoloading.
// No application service is called and no SQL is taken from the caller.
//
// What it prints is protocol, never data: table names, row counts, SHA-256 digests, key fingerprints (16 hex characters of a domain-separated
// digest, never a key). A failure prints the exception CLASS and code to stderr, never its message: a query exception's message carries the
// statement and its bindings, which here include password hashes and ciphertext.

final class HarnessRefusal extends RuntimeException {}

/** The row count of every v0.3.0 table once the fixture is loaded. Written out, not computed from the fixture: a fixture that quietly lost rows must not agree with itself. */
const FIXTURE_ROWS = [
    'account_invitations' => 4, 'account_recovery_codes' => 5, 'account_totp_factors' => 3, 'accounts' => 11, 'cache' => 0,
    'cache_locks' => 0, 'failed_jobs' => 0, 'job_batches' => 0, 'jobs' => 0, 'membership_grants' => 5, 'migrations' => 15,
    'password_reset_tokens' => 2, 'people' => 13, 'role_assignments' => 12, 'security_events' => 8, 'sessions' => 3,
];

/** The version the upgrade's migrations are recorded under: the 15 historical migrations ran as batch 1, the pending ones run as batch 2. */
const UPGRADE_BATCH = 2;

/** `sha256("flc-key-v1|" + the key's decoded 32 bytes)`, first 16 hex characters. Takes a Laravel `base64:` key. */
function keyFingerprint(string $key): string
{
    if (! str_starts_with($key, 'base64:')) {
        throw new HarnessRefusal('an encryption key is not in the base64: form');
    }
    $raw = base64_decode(substr($key, 7), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new HarnessRefusal('an encryption key does not decode to 32 bytes');
    }

    return substr(hash('sha256', 'flc-key-v1|'.$raw), 0, 16);
}

/**
 * The digest of a table's rows exactly as the driver returns them, ordered by primary key (HistoricalSnapshot::take): a changed value, type
 * or order is a different digest. The rows themselves are never printed.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function rowsDigest(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

/**
 * One protocol line per v0.3.0 table: `<kind> <table> <row count> <digest>`.
 *
 * @param  array<string, list<array<string, mixed>>>  $rows
 */
function tableLines(string $kind, array $rows): string
{
    $out = '';
    foreach (array_keys(HistoricalSnapshot::TABLES) as $table) {
        if (! array_key_exists($table, $rows)) {
            throw new HarnessRefusal("the snapshot has no rows entry for $table");
        }
        $out .= sprintf("%s %s %d %s\n", $kind, $table, count($rows[$table]), rowsDigest($rows[$table]));
    }

    return $out;
}

/** The names of every table in the connected database, sorted. */
function tableNames(ConnectionInterface $db): array
{
    $names = [];
    foreach ($db->select('select table_name as name from information_schema.tables where table_schema = database() order by table_name') as $row) {
        $names[] = (string) $row->name;
    }
    sort($names, SORT_STRING);

    return $names;
}

/** @return list<string> the migrations recorded in the ledger, in order, without `.php` */
function ledger(ConnectionInterface $db): array
{
    return array_values(array_map('strval', $db->table('migrations')->orderBy('id')->pluck('migration')->all()));
}

/** @param  list<string>  $files */
function migrationNames(array $files): array
{
    return array_map(static fn (string $file): string => (string) preg_replace('/\.php$/', '', $file), $files);
}

/** @return array{0: \Illuminate\Foundation\Application, 1: ConnectionInterface} the booted application of the tree the runner is in, and its default connection */
function bootApplication(): array
{
    $root = (string) getcwd();
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return [$app, $app->make('db')->connection()];
}

/** The WP3b test support, by path, from the current tree (the one place it exists); no autoload-dev, no other class. */
function loadSupport(): void
{
    $support = dirname(__DIR__).'/current/tests/Support';
    foreach (['HistoricalFixture', 'HistoricalSnapshot', 'UpgradeDatabase'] as $class) {
        if (! is_file("$support/$class.php")) {
            throw new HarnessRefusal("the test support file $class.php is not in the prepared source");
        }
        require_once "$support/$class.php";
    }
}

/** @param  list<string>  $expected */
function requireInventory(ConnectionInterface $db, array $expected, string $what): void
{
    sort($expected, SORT_STRING);
    if (tableNames($db) !== $expected) {
        throw new HarnessRefusal("the database is not exactly $what (its table inventory differs)");
    }
}

function actionFixtureLoad(): void
{
    [$app, $db] = bootApplication();
    loadSupport();
    requireInventory($db, array_keys(HistoricalSnapshot::TABLES), 'the 16-table v0.3.0 schema');
    if (ledger($db) !== migrationNames(UpgradeDatabase::HISTORICAL)) {
        throw new HarnessRefusal('the migration ledger is not exactly the 15 migrations v0.3.0 shipped');
    }
    foreach (array_keys(HistoricalSnapshot::TABLES) as $table) {
        if ($table !== 'migrations' && $db->table($table)->count() !== 0) {
            throw new HarnessRefusal("the table $table is not empty, so the fixture would not describe the database");
        }
    }

    // All of it or none of it: the inserts are plain DML, so a failure part-way rolls back and leaves the empty schema.
    $db->transaction(static function () use ($app, $db): void {
        HistoricalFixture::load($db, $app->make('encrypter'));
        foreach (FIXTURE_ROWS as $table => $count) {
            if ($db->table($table)->count() !== $count) {
                throw new HarnessRefusal("the fixture is incomplete: $table does not hold the expected $count rows");
            }
        }
    });

    // The key the application actually used, as a fingerprint, so the wrapper can compare it with the key it generated.
    echo 'key:current='.keyFingerprint((string) config('app.key'))."\n";
    $previous = array_map(static fn (mixed $key): string => keyFingerprint((string) $key), (array) config('app.previous_keys'));
    sort($previous, SORT_STRING);
    foreach ($previous as $fingerprint) {
        echo "key:previous=$fingerprint\n";
    }

    $before = HistoricalSnapshot::take($db);
    $renamed = array_map(HistoricalFixture::assignment(...), HistoricalFixture::RENAMED);
    $expected = HistoricalSnapshot::expected($before, $renamed, HistoricalFixture::UPGRADE_MIGRATIONS, UPGRADE_BATCH);
    echo tableLines('table', $before);
    echo tableLines('upgrade', $expected);
    echo "fixture:complete=ok\n";
}

function actionSnapshot(): void
{
    [, $db] = bootApplication();
    loadSupport();
    echo tableLines('table', HistoricalSnapshot::take($db));
    echo "snapshot:complete=ok\n";
}

function actionMark(): void
{
    [, $db] = bootApplication();
    loadSupport();
    requireInventory($db, [...array_keys(HistoricalSnapshot::TABLES), ...HistoricalFixture::NEW_TABLES], 'the 34-table upgraded schema');
    if (ledger($db) !== migrationNames([...UpgradeDatabase::HISTORICAL, ...HistoricalFixture::UPGRADE_MIGRATIONS])) {
        throw new HarnessRefusal('the migration ledger is not the 29 migrations of an upgrade from v0.3.0');
    }
    if ($db->table('contact_profiles')->count() !== 0) {
        throw new HarnessRefusal('contact_profiles is not empty, so a post-upgrade marker would not be recognisable');
    }

    $person = HistoricalFixture::id('X', 1);
    $at = '2026-11-01 12:00:00';
    $db->transaction(static function () use ($db, $person, $at): void {
        $db->table('people')->insert(['id' => $person, 'display_name' => 'Post-upgrade marker (rehearsal)', 'created_at' => $at, 'updated_at' => $at]);
        $db->table('contact_profiles')->insert(['person_id' => $person, 'how_we_know' => 'written after the upgrade, by the rehearsal', 'affiliation' => null, 'updated_by_account_id' => null, 'created_at' => $at, 'updated_at' => $at]);
    });

    if ($db->table('people')->count() !== FIXTURE_ROWS['people'] + 1 || $db->table('contact_profiles')->count() !== 1) {
        throw new HarnessRefusal('the post-upgrade marker is not what was written');
    }
    echo "marker:written=ok\n";
}

// Run the action only when this file is the script, so the pure functions above can be exercised by requiring it.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $actions = ['fixture-load' => 'actionFixtureLoad', 'snapshot' => 'actionSnapshot', 'mark' => 'actionMark'];
    if ($argc !== 2 || ! isset($actions[$argv[1]])) {
        fwrite(STDERR, "usage: rehearse.php fixture-load|snapshot|mark\n");
        exit(64);
    }
    try {
        $actions[$argv[1]]();
    } catch (HarnessRefusal $refusal) {
        fwrite(STDERR, 'harness:refused '.$refusal->getMessage()."\n");
        exit(3);
    } catch (Throwable $failure) {
        fwrite(STDERR, 'harness:error '.$failure::class.' code='.$failure->getCode()."\n");
        exit(1);
    }
}
