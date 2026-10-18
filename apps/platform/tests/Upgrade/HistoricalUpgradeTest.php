<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Identity\Application\TotpSecretCipher;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\HistoricalFixture as Fixture;
use Tests\Support\HistoricalRun;
use Tests\Support\HistoricalSnapshot as Snapshot;
use Tests\Support\UpgradeDatabase as Upgrade;

/*
 * G6 WP3b. A populated v0.3.0 database, upgraded to the current schema with the real migration runner, loses nothing and changes
 * nothing it should not. Production is at v0.3.0 (15 migrations); this release has 29. What it adds is checked against LITERAL
 * expectations written in this file, never against the code that is being upgraded to.
 *
 * Method (tests/Support/UpgradeDatabase.php says why the database is separate and how it is kept safe):
 *  1. build the v0.3.0 schema from the 15 migrations v0.3.0 shipped, on the dedicated database;
 *  2. load literal v0.3.0 rows (Tests\Support\HistoricalFixture; no application code), and take every row of the 16 tables;
 *  3. run the other 14 migrations, and take every row again;
 *  4. judge the second snapshot against the first with exactly the allowed changes applied (Tests\Support\HistoricalSnapshot).
 * The upgrade runs ONCE for the file; each test below reads a different part of its outcome. The negative case, an upgrade that must
 * be refused, is its own file (RefusedUpgradeTest) because it needs its own historical database.
 *
 * Not covered, on purpose: the v0.3.0 APPLICATION, backup and restore, an in-place rollback and the real host (WP3c). This proves
 * the approved DEVELOPMENT behaviour. It does not authorize shipping the capability expansion (docs/runbooks/g10-release-readiness.md).
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend -- tests/Upgrade` and `./flow test backend --pgsql -- tests/Upgrade`.
 */

/** Everything an administrator can do, by id, as of this release. An administrator's set is by definition the whole catalog, so it is also compared with the catalog. */
const ADMINISTRATOR_CAPABILITIES = [
    'access.roles.assign', 'console.access', 'crm.people.manage', 'crm.people.view', 'discussions.participate', 'discussions.view',
    'guardians.manage', 'guardians.view', 'identity.accounts.manage', 'identity.accounts.view', 'identity.invitations.issue',
    'identity.mfa.recover', 'membership.records.manage', 'membership.records.view', 'resources.manage', 'resources.view',
    'volunteers.manage', 'volunteers.view',
];

/** Builds the v0.3.0 database, loads the fixture, upgrades it, and returns what each stage looked like. Done once for the file. */
function historicalUpgradeRun(): HistoricalRun
{
    static $run = null;
    static $failure = null;

    if ($failure instanceof Throwable) {
        throw $failure;
    }
    if ($run instanceof HistoricalRun) {
        return $run;
    }

    try {
        Upgrade::clean();
        Upgrade::migrateHistorical();
        $db = Upgrade::connect();

        $historicalTables = Upgrade::tables();
        $historicalRan = Upgrade::ran();
        $historicalBatches = Upgrade::batches();
        $historicalStructure = Snapshot::structure(Upgrade::CONNECTION);

        Fixture::load($db, app('encrypter'));
        $before = Snapshot::take($db);

        Upgrade::migratePending();

        return $run = new HistoricalRun(
            $historicalTables, $historicalRan, $historicalBatches, $historicalStructure, $before, Snapshot::take($db),
            Snapshot::structure(Upgrade::CONNECTION), Upgrade::tables(), Upgrade::ran(), Upgrade::batches(),
        );
    } catch (Throwable $e) {
        $failure = $e;

        throw $e;
    }
}

/**
 * What Account `$number` may do now, decided by the real Authorizer against the upgraded database.
 *
 * @return list<string>
 */
function upgradedCapabilitiesOf(int $number): array
{
    $actor = Actor::user(AccountId::fromString(Fixture::account($number)), PersonId::fromString(Fixture::person($number)));

    return array_map(static fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf($actor));
}

/** @return list<string> the ids of the role assignments (as given) in the rows */
function assignmentIds(int ...$numbers): array
{
    return array_map(Fixture::assignment(...), array_values($numbers));
}

/**
 * The one row of a snapshotted table whose column holds the value.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function rowWhere(array $rows, string $column, string $value): array
{
    $found = array_values(array_filter($rows, static fn (array $row): bool => ($row[$column] ?? null) === $value));
    expect($found)->toHaveCount(1, "no single row has {$column} = {$value}");

    return $found[0];
}

beforeEach(function () {
    historicalUpgradeRun();
    Upgrade::useAsDefault();
});

afterEach(function () {
    Upgrade::restoreDefault();
});

afterAll(function () {
    Upgrade::cleanVerified();
});

it('builds the v0.3.0 schema from exactly its 15 migrations: 16 tables, nothing later', function () {
    $run = historicalUpgradeRun();

    expect($run->historicalRan)->toBe(Upgrade::HISTORICAL)
        ->and($run->historicalRan)->toHaveCount(15)
        ->and($run->historicalBatches)->toBe(array_fill(0, 15, 1))
        ->and($run->historicalTables)->toBe([
            'account_invitations', 'account_recovery_codes', 'account_totp_factors', 'accounts', 'cache', 'cache_locks', 'failed_jobs', 'job_batches',
            'jobs', 'membership_grants', 'migrations', 'password_reset_tokens', 'people', 'role_assignments', 'security_events', 'sessions',
        ])
        ->and(array_keys(Snapshot::TABLES))->toBe($run->historicalTables);
});

it('holds the fixture the matrix describes, and RENAMED names exactly the rows whose key is `guardian`', function () {
    $before = historicalUpgradeRun()->before;
    $exact = [];
    foreach ($before['role_assignments'] as $row) {
        if ($row['role_key'] === 'guardian') { // in PHP: MariaDB's `where` would also return the variants
            $exact[] = $row['id'];
        }
    }

    expect($exact)->toBe(assignmentIds(...Fixture::RENAMED))
        ->and(array_column($before['role_assignments'], 'role_key'))->toBe([
            'platform_administrator', 'guardian', 'platform_administrator', 'guardian', 'guardian', 'guardian', 'guardian',
            'Guardian', 'GUARDIAN', 'guardian ', 'guardián', 'Guardian-Full',
        ])
        ->and(array_map('count', $before))->toMatchArray([
            'people' => 13, 'accounts' => 11, 'account_invitations' => 4, 'password_reset_tokens' => 2, 'security_events' => 8, 'sessions' => 3,
            'role_assignments' => 12, 'account_totp_factors' => 3, 'account_recovery_codes' => 5, 'membership_grants' => 5, 'migrations' => 15,
            'cache' => 0, 'cache_locks' => 0, 'jobs' => 0, 'job_batches' => 0, 'failed_jobs' => 0,
        ])
        ->and(array_count_values(array_map(Snapshot::string(...), array_column($before['accounts'], 'status'))))->toBe(['active' => 9, 'invited' => 1, 'disabled' => 1]);
});

it('runs the other 14 migrations with the real runner, in order, and records each once in a second batch', function () {
    $run = historicalUpgradeRun();

    expect(Upgrade::pendingNames())->toBe(Fixture::UPGRADE_MIGRATIONS)
        ->and($run->afterRan)->toBe([...Upgrade::HISTORICAL, ...Fixture::UPGRADE_MIGRATIONS])
        ->and($run->afterRan)->toHaveCount(29)
        ->and($run->afterBatches)->toBe([...array_fill(0, 15, 1), ...array_fill(0, 14, 2)])
        ->and(array_slice($run->after['migrations'], 0, 15))->toBe($run->before['migrations']);
});

it('changes nothing in the 16 historical tables except the exact `guardian` keys and the 14 new migration records', function () {
    $run = historicalUpgradeRun();
    $expected = Snapshot::expected($run->before, assignmentIds(...Fixture::RENAMED), Fixture::UPGRADE_MIGRATIONS, 2);

    expect(Snapshot::differences($expected, $run->after))->toBe([]);

    // The same fact, table by table, so a failure names the table whatever the judgement above says.
    foreach (array_keys(Snapshot::TABLES) as $table) {
        if (in_array($table, ['role_assignments', 'migrations'], true)) {
            continue;
        }
        expect($run->after[$table])->toBe($run->before[$table], "table {$table} changed");
    }
    // Sessions are byte for byte what they were, payloads included.
    $digest = static fn (array $row): string => hash('sha256', Snapshot::string($row['id']).'|'.Snapshot::string($row['payload']));
    expect(array_map($digest, $run->after['sessions']))->toBe(array_map($digest, $run->before['sessions']));
});

it('stores exactly these role keys afterwards: five renamed, every other key exactly as it was', function () {
    $stored = DB::table('role_assignments')->orderBy('id')->get(['id', 'person_id', 'role_key', 'granted_by_account_id', 'granted_at']);
    $keys = [];
    foreach ($stored as $row) {
        $keys[Snapshot::string($row->id)] = Snapshot::string($row->role_key);
    }

    expect($keys)->toBe([
        Fixture::assignment(1) => 'platform_administrator',
        Fixture::assignment(2) => 'guardian-full',
        Fixture::assignment(3) => 'platform_administrator',
        Fixture::assignment(4) => 'guardian-full',
        Fixture::assignment(5) => 'guardian-full',
        Fixture::assignment(6) => 'guardian-full',
        Fixture::assignment(7) => 'guardian-full',
        Fixture::assignment(8) => 'Guardian',
        Fixture::assignment(9) => 'GUARDIAN',
        Fixture::assignment(10) => 'guardian ',
        Fixture::assignment(11) => 'guardián',
        Fixture::assignment(12) => 'Guardian-Full',
    ])->and(in_array('guardian', array_values($keys), true))->toBeFalse();

    // Who holds a row, who granted it and when did not move: the renamed rows belong to the same Persons.
    $historical = historicalUpgradeRun()->before['role_assignments'];
    foreach ($stored as $index => $row) {
        expect([$row->person_id, $row->granted_by_account_id, $row->granted_at])
            ->toBe([$historical[$index]['person_id'], $historical[$index]['granted_by_account_id'], $historical[$index]['granted_at']]);
    }
});

it('leaves multi-factor data untouched, and the stored authenticator secret still decrypts to the secret that was enrolled', function () {
    $run = historicalUpgradeRun();
    $factor = rowWhere($run->after['account_totp_factors'], 'account_id', Fixture::account(1));
    $pending = rowWhere($run->after['account_totp_factors'], 'account_id', Fixture::account(3));

    expect($run->after['account_totp_factors'])->toBe($run->before['account_totp_factors'])
        ->and($run->after['account_recovery_codes'])->toBe($run->before['account_recovery_codes'])
        ->and(app(TotpSecretCipher::class)->decrypt(Snapshot::string($factor['secret_ciphertext']))->reveal())->toBe(Fixture::totpSecret())
        ->and(Fixture::totpSecret())->toMatch('/^[A-Z2-7]{32}$/')
        ->and(collect($run->after['account_recovery_codes'])->whereNotNull('used_at')->pluck('id')->all())->toBe([Fixture::id('C', 1)])
        ->and($pending['enrolled_at'])->toBeNull()
        ->and($pending['secret_ciphertext'])->toBeNull()
        ->and($pending['pending_secret_ciphertext'])->not->toBeNull();
});

it('keeps the historical tables\' columns, indexes and foreign keys as they were', function () {
    $run = historicalUpgradeRun();

    expect($run->afterStructure)->toBe($run->historicalStructure);

    // The primary key is named by the engine (`primary`, `role_assignments_pkey`), so it is left out of the index list; the two indexes the migrations named are not.
    expect(Snapshot::foreignKeys($run->afterStructure))->toBe([
        'account_invitations.account_id -> accounts.id',
        'account_recovery_codes.account_id -> accounts.id',
        'account_totp_factors.account_id -> accounts.id',
        'accounts.person_id -> people.id',
        'membership_grants.person_id -> people.id',
        'role_assignments.person_id -> people.id',
    ])->and(Snapshot::indexNames($run->afterStructure, 'role_assignments'))->toBe(['role_assignments_person_id_role_key_unique', 'role_assignments_role_key_index']);
});

it('creates the 18 new tables, wires the ones that point at a Person to people, and seeds none of them', function () {
    $run = historicalUpgradeRun();
    $tables = array_values(array_diff($run->afterTables, $run->historicalTables));

    expect($tables)->toBe(Fixture::NEW_TABLES)
        ->and($run->afterTables)->toHaveCount(34)
        ->and(Snapshot::foreignTargets(Upgrade::CONNECTION, 'person_relationships'))->toContain('people')
        ->and(Snapshot::foreignTargets(Upgrade::CONNECTION, 'sourced_role_grants'))->toContain('people')
        ->and(Snapshot::foreignTargets(Upgrade::CONNECTION, 'contact_profiles'))->toContain('people');

    foreach (Fixture::NEW_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, "table {$table} is not empty after the upgrade");
    }
});

describe('what each Account may do afterwards, decided by the real Authorizer', function () {
    it('gives an active holder of the exact `guardian` key the ten Guardian capabilities, as the runbook lists them', function () {
        expect(upgradedCapabilitiesOf(3))->toBe(Fixture::GUARDIAN_BUNDLE)
            ->and(Fixture::GUARDIAN_BUNDLE)->toHaveCount(10)
            ->and(Fixture::GUARDIAN_BUNDLE)->not->toContain('guardians.manage')
            ->and(Fixture::GUARDIAN_BUNDLE)->not->toContain('access.roles.assign');
    });

    it('keeps an administrator\'s full authority, with or without the Guardian role beside it', function () {
        $every = array_map(static fn (Capability $capability): string => $capability->value, Capability::cases());
        sort($every, SORT_STRING);

        expect(upgradedCapabilitiesOf(1))->toBe($every)
            ->and(upgradedCapabilitiesOf(2))->toBe($every) // the union of the bundle and everything is everything
            ->and($every)->toContain(...ADMINISTRATOR_CAPABILITIES)
            ->and(upgradedCapabilitiesOf(1))->toContain('guardians.manage')
            ->and(DB::table('role_assignments')->where('person_id', Fixture::person(2))->orderBy('role_key')->pluck('role_key')->all())->toBe(['guardian-full', 'platform_administrator']);
    });

    it('gives a variant key nothing, even on an active Account', function (int $account, string $key) {
        expect(DB::table('role_assignments')->where('person_id', Fixture::person($account))->value('role_key'))->toBe($key)
            ->and(DB::table('accounts')->where('id', Fixture::account($account))->value('status'))->toBe('active')
            ->and(upgradedCapabilitiesOf($account))->toBe([]);
    })->with([
        'Guardian' => [7, 'Guardian'],
        'GUARDIAN' => [8, 'GUARDIAN'],
        'trailing space' => [9, 'guardian '],
        'accent' => [10, 'guardián'],
        'Guardian-Full' => [11, 'Guardian-Full'],
    ]);

    it('lets an invited or disabled Account exercise nothing although its stored key was renamed', function (int $account, string $status) {
        expect(DB::table('role_assignments')->where('person_id', Fixture::person($account))->value('role_key'))->toBe('guardian-full')
            ->and(DB::table('accounts')->where('id', Fixture::account($account))->value('status'))->toBe($status)
            ->and(upgradedCapabilitiesOf($account))->toBe([]);
    })->with([
        'invited' => [4, 'invited'],
        'disabled' => [5, 'disabled'],
    ]);

    it('gives an Account with no role nothing, and a Person without an Account no way to sign in at all', function () {
        expect(upgradedCapabilitiesOf(12))->toBe([])
            ->and(DB::table('role_assignments')->where('person_id', Fixture::person(12))->count())->toBe(0);

        foreach ([6, 13] as $person) {
            expect(DB::table('accounts')->where('person_id', Fixture::person($person))->count())->toBe(0)
                ->and(DB::table('sessions')->whereIn('user_id', DB::table('accounts')->where('person_id', Fixture::person($person))->pluck('id'))->count())->toBe(0);
        }
        // Nora's stored role was renamed like any other; with no Account it is a latent grant, and she has nobody to exercise it.
        expect(DB::table('role_assignments')->where('person_id', Fixture::person(6))->value('role_key'))->toBe('guardian-full');
    });

    it('creates no Guardian relationship and no membership grant, and writes no security event', function () {
        $run = historicalUpgradeRun();

        expect(DB::table('person_relationships')->count())->toBe(0)
            ->and(DB::table('sourced_role_grants')->count())->toBe(0)
            ->and($run->after['membership_grants'])->toBe($run->before['membership_grants'])
            ->and($run->after['security_events'])->toBe($run->before['security_events'])
            ->and($run->after['security_events'])->toHaveCount(8);
    });
});

describe('the diagnostics, run for real against the upgraded database', function () {
    it('finds the relationships consistent, and there are none', function () {
        $status = Artisan::call('relationships:check');

        expect($status)->toBe(0)
            ->and(trim(Artisan::output()))->toBe('Relationships are consistent with their definitions.');
    });

    it('lists every independent console-access holder in the adoption report, whatever their Account status, and writes nothing', function () {
        $before = Snapshot::take(DB::connection(Upgrade::CONNECTION));
        $status = Artisan::call('relationships:guardian-adoption-report');
        $lines = explode("\n", rtrim(Artisan::output(), "\n"));

        $section = static function (string $heading) use ($lines): array {
            $start = array_search($heading, $lines, true);
            expect($start)->not->toBeFalse("heading '{$heading}' is missing");
            $found = [];
            for ($i = (int) $start + 1; $i < count($lines) && $lines[$i] !== ''; $i++) {
                $found[] = $lines[$i];
            }

            return $found;
        };

        expect($status)->toBe(0)
            // The administrators, the active Guardian, and the invited, disabled and Account-less holders: none is filtered out for being unable to sign in.
            ->and($section('Console access without a Guardian relationship'))->toBe([
                '- '.Fixture::person(1).' Ada Admin',
                '- '.Fixture::person(2).' Gus Both',
                '- '.Fixture::person(3).' Guy Guardian',
                '- '.Fixture::person(4).' Ines Invited',
                '- '.Fixture::person(5).' Dora Disabled',
                '- '.Fixture::person(6).' Nora Noaccount',
            ])
            ->and($section('Guardian relationships whose Person has no Account'))->toBe(['- none'])
            ->and($section('Guardian relationships whose Person lacks console access'))->toBe(['- none'])
            ->and($section('relationships:check'))->toBe(['Relationships are consistent with their definitions.'])
            // Read-only: not a row anywhere moved.
            ->and(Snapshot::take(DB::connection(Upgrade::CONNECTION)))->toBe($before)
            ->and(DB::table('person_relationships')->count())->toBe(0);
    });
});

/**
 * The upgraded snapshot with one thing wrong, the way a migration defect could leave it.
 *
 * @param  array<string, list<array<string, mixed>>>  $after
 * @return array<string, list<array<string, mixed>>>
 */
function damaged(string $fault, array $after): array
{
    switch ($fault) {
        case 'a historical row is missing':
            $after['people'] = array_values(array_filter($after['people'], static fn (array $row): bool => $row['id'] !== Fixture::person(5)));
            break;
        case 'a security event is missing':
            array_pop($after['security_events']);
            break;
        case 'a variant key is renamed as if it were exact':
            $after['role_assignments'][7]['role_key'] = 'guardian-full'; // assignment 8, `Guardian`
            break;
        case 'an exact key is not renamed':
            $after['role_assignments'][1]['role_key'] = 'guardian'; // assignment 2
            break;
        case 'a recovery-code digest changes':
            $after['account_recovery_codes'][0]['code_hash'] = str_repeat('0', 64);
            break;
        case 'a timestamp moves':
            $after['membership_grants'][0]['created_at'] = '2026-09-23 10:00:01';
            break;
        case 'a row appears in a historical table':
            $after['sessions'][] = ['id' => 'stray', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => 0];
            break;
        case 'a migration is not recorded':
            array_pop($after['migrations']);
            break;
        default:
            throw new LogicException("Unknown fault {$fault}.");
    }

    return $after;
}

describe('the comparison itself would notice a defect', function () {
    // The judgement above is only worth anything if it can fail. Each case damages the REAL upgraded snapshot in one way a migration defect could,
    // and the judgement must name it.
    it('reports a fault', function (string $fault, string $reported) {
        $run = historicalUpgradeRun();
        $expected = Snapshot::expected($run->before, assignmentIds(...Fixture::RENAMED), Fixture::UPGRADE_MIGRATIONS, 2);
        $broken = damaged($fault, $run->after);

        expect($broken)->not->toBe($run->after)
            ->and(collect(Snapshot::differences($expected, $broken))->contains(static fn (string $line): bool => str_starts_with($line, $reported)))->toBeTrue();
    })->with([
        'a historical row is missing' => ['a historical row is missing', "people: row '".'01jbp000000000000000000005'."' was deleted"],
        'a security event is missing' => ['a security event is missing', "security_events: row '".'01jbe000000000000000000008'."' was deleted"],
        'a variant key is renamed as if it were exact' => ['a variant key is renamed as if it were exact', "role_assignments: row '01jbr000000000000000000008' column role_key is 'guardian-full', expected 'Guardian'"],
        'an exact key is not renamed' => ['an exact key is not renamed', "role_assignments: row '01jbr000000000000000000002' column role_key is 'guardian', expected 'guardian-full'"],
        'a recovery-code digest changes' => ['a recovery-code digest changes', "account_recovery_codes: row '01jbc000000000000000000001' column code_hash is '".str_repeat('0', 64)."', expected '"],
        'a timestamp moves' => ['a timestamp moves', "membership_grants: row '01jbm000000000000000000001' column created_at is '2026-09-23 10:00:01', expected '2026-09-23 10:00:00'"],
        'a row appears in a historical table' => ['a row appears in a historical table', "sessions: row 'stray' appeared"],
        'a migration is not recorded' => ['a migration is not recorded', "migrations: row '29' was deleted"],
    ]);
});
