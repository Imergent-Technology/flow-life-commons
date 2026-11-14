<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\Support\HistoricalFixture;
use Tests\Support\HistoricalSnapshot;
use Tests\Support\Totp;
use Tests\Support\UpgradeDatabase;

// G6 WP3c slice 2.2 fixed helper, run inside the disposable runner by `restore-rehearsal.sh`. One action per run, chosen by the first
// argument, and nothing else:
//
//   fixture-load   in the HISTORICAL tree: refuses unless the database is exactly the empty v0.3.0 schema, loads the synthetic v0.3.0
//                  fixture in ONE transaction, gives the administrator a password and a TOTP enrolment this run can derive again (below),
//                  requires it complete, and prints a digest of every table (what it is now, what the upgrade must make of it) and of
//                  every table's structure.
//   snapshot       in any tree: prints the per-table row digests of the 16 v0.3.0 tables and the structure digest of every table present
//                  as they are now. Read-only.
//   mark           in the CURRENT tree: refuses unless the database is exactly the upgraded schema, then adds one clearly synthetic
//                  post-upgrade Person and CRM profile, so a recovery can tell the upgraded state from the historical one.
//   signin         in the HISTORICAL tree, after a restore: the restored v0.3.0 application signs the administrator in over its real HTTP
//                  stack (kernel, middleware, cookies, CSRF, the database session) with the password, then the TOTP code, then reads /me.
//                  All of it happens inside a transaction that is rolled back, and the table digests after the rollback are printed so the
//                  caller can require that signing in changed nothing.
//
// CREDENTIALS. The fixture's own passwords and TOTP secrets are random and forgotten when the process ends. For the one account the sign-in
// uses (Ada, the administrator), fixture-load replaces the password hash and the TOTP ciphertext with ones derived from this run's synthetic
// APP_KEY (HMAC-SHA256 of a fixed label), and encrypts the TOTP secret under the PREVIOUS key, so signing in after a restore needs the whole
// keyring, not just the current key. Nothing is stored: `signin` derives the same values again, and none is ever printed.
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

/** The 32 raw bytes of a Laravel `base64:` key. */
function keyBytes(string $key): string
{
    if (! str_starts_with($key, 'base64:')) {
        throw new HarnessRefusal('an encryption key is not in the base64: form');
    }
    $raw = base64_decode(substr($key, 7), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new HarnessRefusal('an encryption key does not decode to 32 bytes');
    }

    return $raw;
}

/** `sha256("flc-key-v1|" + the key's decoded 32 bytes)`, first 16 hex characters. Takes a Laravel `base64:` key. */
function keyFingerprint(string $key): string
{
    return substr(hash('sha256', 'flc-key-v1|'.keyBytes($key)), 0, 16);
}

/** 32 bytes derived from this run's current key for one purpose: the same in every process of the run, different for each purpose. */
function derived(string $purpose): string
{
    return hash_hmac('sha256', "flc-rehearsal-$purpose-v1", keyBytes((string) config('app.key')), true);
}

/** The administrator's password for this run: 64 hex characters, never printed. */
function rehearsalPassword(): string
{
    return bin2hex(derived('password'));
}

/** RFC 4648 base32 of 20 bytes: 32 characters, no padding. */
function base32(string $raw): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($raw) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }

    return $out;
}

/** The administrator's TOTP secret for this run (160 bits, base32), never printed. */
function rehearsalTotpSecret(): string
{
    return base32(substr(derived('totp'), 0, 20));
}

/**
 * The digest of a table's rows as the driver returns them, ordered by primary key (HistoricalSnapshot::take), each value as text: a changed
 * value, a lost row or a different order is a different digest. The rows themselves are never printed.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function rowsDigest(array $rows): string
{
    // Every value as text (NULL stays NULL): the digest is compared across two application trees (v0.3.0 and the current one) whose
    // drivers could differ in whether an integer arrives as 3 or '3', and a restore that changes a value still changes its text.
    $normal = array_map(static fn (array $row): array => array_map(static fn (mixed $value): ?string => $value === null ? null : (is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR)), $row), $rows);

    return hash('sha256', json_encode($normal, JSON_THROW_ON_ERROR));
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

/**
 * The names of every table in the connected database, sorted. This is information_schema, which also lists views, so a view is an
 * unexpected name rather than something a plain table listing would hide. MariaDB is the only database the rehearsal runs against: any other
 * driver is refused, not approximated.
 *
 * @return list<string>
 */
function tableNames(ConnectionInterface $db): array
{
    if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
        throw new HarnessRefusal('the rehearsal runs only against MariaDB (the connection uses the "'.$db->getDriverName().'" driver)');
    }
    $names = [];
    foreach ($db->select('select table_name as name from information_schema.tables where table_schema = database() order by table_name') as $row) {
        $names[] = (string) $row->name;
    }
    sort($names, SORT_STRING);

    return $names;
}

/**
 * One protocol line per table present: `structure <table> <digest>`, the digest of its columns, indexes and foreign keys as the engine reports
 * them (the same three lists HistoricalSnapshot::structure compares), each list in a fixed order. A table whose structure cannot be read is
 * an exception, never a missing line.
 */
function structureLines(ConnectionInterface $db): string
{
    $schema = $db->getSchemaBuilder();
    $out = '';
    foreach (tableNames($db) as $table) {
        $shape = ['columns' => $schema->getColumns($table), 'indexes' => $schema->getIndexes($table), 'foreign_keys' => $schema->getForeignKeys($table)];
        foreach ($shape as &$list) {
            usort($list, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        }
        unset($list);
        $out .= sprintf("structure %s %s\n", $table, hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR)));
    }

    return $out;
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

/** @return array{0: \Illuminate\Foundation\Application, 1: ConnectionInterface, 2: HttpKernel} the booted application with its HTTP kernel, for a request made in this process */
function bootHttp(): array
{
    $root = (string) getcwd();
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(HttpKernel::class);
    $kernel->bootstrap();

    return [$app, $app->make('db')->connection(), $kernel];
}

/** The WP3b test support, by path, from the current tree (the one place it exists); no autoload-dev, no other class. Totp is its independent RFC 6238 code generator. */
function loadSupport(): void
{
    $support = dirname(__DIR__).'/current/tests/Support';
    foreach (['HistoricalFixture', 'HistoricalSnapshot', 'UpgradeDatabase', 'Totp'] as $class) {
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

/**
 * Replaces the administrator's password hash and TOTP ciphertext with the ones this run can derive again, and encrypts the TOTP secret under the
 * PREVIOUS key (the first of the previous keys), as a secret enrolled before a key rotation would be. The caller holds a transaction.
 */
function applyRehearsalCredentials(ConnectionInterface $db): void
{
    $previous = array_values((array) config('app.previous_keys'));
    if (count($previous) !== 1) {
        throw new HarnessRefusal('exactly one previous encryption key is expected, so that the enrolment can depend on it');
    }
    $old = new Encrypter(keyBytes((string) $previous[0]), (string) config('app.cipher'));
    $changed = $db->table('accounts')->where('id', HistoricalFixture::account(1))->update(['password_hash' => Hash::make(rehearsalPassword())]);
    $changed += $db->table('account_totp_factors')->where('id', HistoricalFixture::id('T', 1))->update(['secret_ciphertext' => $old->encryptString(rehearsalTotpSecret())]);
    if ($changed !== 2) {
        throw new HarnessRefusal('the administrator\'s credentials were not where the fixture puts them');
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
        applyRehearsalCredentials($db);
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
    echo structureLines($db);
    echo "fixture:complete=ok\n";
}

function actionSnapshot(): void
{
    [, $db] = bootApplication();
    loadSupport();
    echo tableLines('table', HistoricalSnapshot::take($db));
    echo structureLines($db);
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

/**
 * A browser stand-in for one process: a cookie jar, and a clean session and guard before every request (as tests/Support/Console does), so
 * nothing passes from one request to the next except what the server put in a cookie or in the database. It sends what the server issued,
 * untouched, and echoes the XSRF-TOKEN cookie as X-XSRF-TOKEN.
 */
final class RehearsalBrowser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly \Illuminate\Foundation\Application $app, private readonly HttpKernel $kernel) {}

    /**
     * @param  array<string, mixed>  $json
     * @return array{0: int, 1: array<string, mixed>} the status, and the JSON body if there is one (never printed by the caller)
     */
    public function send(string $method, string $path, array $json = []): array
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        $this->app['auth']->forgetGuards();

        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        $request = Request::create(rtrim((string) config('app.url'), '/').$path, $method, [], $this->cookies, [], $server, $json === [] ? null : json_encode($json, JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        foreach ($response->headers->getCookies() as $cookie) {
            $value = (string) $cookie->getValue();
            if ($value === '' || ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time())) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $value;
            }
        }
        $body = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }

    public function holds(string $cookie): bool
    {
        return isset($this->cookies[$cookie]);
    }
}

function actionSignin(): void
{
    [$app, $db, $kernel] = bootHttp();
    loadSupport();
    requireInventory($db, array_keys(HistoricalSnapshot::TABLES), 'the 16-table v0.3.0 schema');
    if (ledger($db) !== migrationNames(UpgradeDatabase::HISTORICAL)) {
        throw new HarnessRefusal('the migration ledger is not exactly the 15 migrations v0.3.0 shipped');
    }
    // Nothing the application logs may reach the terminal (an exception's message carries a statement and its bindings), and the session
    // is the application's own, in its own table.
    config(['logging.default' => 'null', 'session.driver' => 'database']);

    // The enrolment was made under the PREVIOUS key. If the current key alone could read it, this would not be a test of the keyring.
    $ciphertext = (string) $db->table('account_totp_factors')->where('id', HistoricalFixture::id('T', 1))->value('secret_ciphertext');
    $currentOnly = new Encrypter(keyBytes((string) config('app.key')), (string) config('app.cipher'));
    try {
        $currentOnly->decryptString($ciphertext);
        throw new HarnessRefusal('the administrator\'s enrolment is readable with the current key alone, so signing in would not test the previous key');
    } catch (DecryptException) {
        echo "signin:enrolment-needs-previous-key=ok\n";
    }

    $administrator = HistoricalFixture::account(1);
    $db->beginTransaction();
    try {
        $browser = new RehearsalBrowser($app, $kernel);
        [$status] = $browser->send('GET', '/api/v1/me');
        if ($status !== 401 || ! $browser->holds('XSRF-TOKEN')) {
            throw new HarnessRefusal("the session and CSRF cookies were not issued to an anonymous visitor (HTTP $status)");
        }
        [$status, $body] = $browser->send('POST', '/api/v1/login', ['email' => 'person1@example.test', 'password' => rehearsalPassword()]);
        if ($status !== 202 || ($body['next'] ?? null) !== 'challenge') {
            throw new HarnessRefusal("the password was not accepted as the first step of a sign-in that needs a second factor (HTTP $status)");
        }
        echo "signin:password=ok\n";
        [$status, $body] = $browser->send('POST', '/api/v1/mfa/challenge', ['code' => Totp::code(rehearsalTotpSecret())]);
        if ($status !== 200 || ($body['account']['id'] ?? null) !== $administrator) {
            throw new HarnessRefusal("the authenticator code was not accepted for the administrator (HTTP $status)");
        }
        echo "signin:second-factor=ok\n";
        [$status, $body] = $browser->send('GET', '/api/v1/me');
        $capabilities = is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [];
        if ($status !== 200 || ($body['account']['id'] ?? null) !== $administrator || ($body['account']['email'] ?? null) !== 'person1@example.test'
            || ! in_array('console.access', $capabilities, true) || ! in_array('access.roles.assign', $capabilities, true)) {
            throw new HarnessRefusal("/me did not show the signed-in administrator with the administrator's capabilities (HTTP $status)");
        }
        echo "signin:me=ok\n";
    } finally {
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
    }
    echo "signin:rolled-back=ok\n";
    echo tableLines('table', HistoricalSnapshot::take($db));
    echo "signin:complete=ok\n";
}

// Run the action only when this file is the script, so the pure functions above can be exercised by requiring it.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $actions = ['fixture-load' => 'actionFixtureLoad', 'snapshot' => 'actionSnapshot', 'mark' => 'actionMark', 'signin' => 'actionSignin'];
    if ($argc !== 2 || ! isset($actions[$argv[1]])) {
        fwrite(STDERR, "usage: rehearse.php fixture-load|snapshot|mark|signin\n");
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
