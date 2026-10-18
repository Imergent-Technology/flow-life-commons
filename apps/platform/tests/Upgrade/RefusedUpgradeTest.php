<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HistoricalFixture as Fixture;
use Tests\Support\HistoricalSnapshot as Snapshot;
use Tests\Support\UpgradeDatabase as Upgrade;

/*
 * G6 WP3b, the upgrade that must be REFUSED. The rename migration (ADR 0038, M6) stops when an exact `guardian-full` assignment already
 * exists, and on MariaDB it also stops when a collation-equal variant of `guardian-full` sits on the same Person as a `guardian` (G6 WP3-02,
 * kept on purpose). Each case here needs a historical database of its own, so it is not part of HistoricalUpgradeTest's single upgrade.
 *
 * What is proved, for each: which migrations the runner recorded before it stopped, that the migration that stopped it was NOT recorded, that
 * not one role assignment (nor any other historical row) changed, that nothing the stopped migrations would have made exists, and what the
 * exception is. The refusal policy itself is not under test and is not altered. This does not prove the stopped state is recoverable on a
 * production host: WP3c rehearses restore.
 *
 * The first 11 of the 14 pending migrations run before the rename (the 12th); on MariaDB DDL commits as it goes and on PostgreSQL each
 * migration is its own transaction, so on both the 11 stay recorded and in place when the 12th stops.
 *
 * Runs on MariaDB and PostgreSQL.
 */

/**
 * Builds the v0.3.0 schema, loads the fixture and any extra role assignments, and returns every historical row as it then stood.
 *
 * @param  array<int, array{int, string}>  $extraAssignments  assignment number => [person number, role key]
 * @return array<string, list<array<string, mixed>>>
 */
function refusedUpgradeBaseline(array $extraAssignments): array
{
    Upgrade::clean();
    Upgrade::migrateHistorical();
    $db = Upgrade::connect();
    Fixture::load($db, app('encrypter'));
    foreach ($extraAssignments as $number => [$person, $key]) {
        $db->table('role_assignments')->insert([
            'id' => Fixture::assignment($number), 'person_id' => Fixture::person($person), 'role_key' => $key,
            'granted_by_account_id' => Fixture::account(1), 'granted_at' => '2026-09-30 09:00:00',
        ]);
    }

    return Snapshot::take($db);
}

/** What `migrate` threw, or null when it completed. */
function refusedUpgradeFailure(): ?Throwable
{
    try {
        Upgrade::migratePending();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** @return list<string> the 11 migrations that run before the rename */
function migrationsBeforeTheRename(): array
{
    return array_slice(Fixture::UPGRADE_MIGRATIONS, 0, 11);
}

afterEach(function () {
    Upgrade::clean();
});

afterAll(function () {
    Upgrade::cleanVerified();
});

it('refuses an exact guardian-full assignment: stops at the rename, records the 11 before it and not it, and changes no historical row', function () {
    // The assignment is on a Person with no other role, so no unique key is involved on either engine: the refusal is the migration's own.
    $before = refusedUpgradeBaseline([13 => [12, 'guardian-full']]);
    expect(array_column($before['role_assignments'], 'role_key'))->toContain('guardian-full')
        ->and(array_column($before['role_assignments'], 'role_key'))->toContain('guardian'); // there is something it would have renamed

    $failure = refusedUpgradeFailure();

    $after = Snapshot::take(Upgrade::connect());
    $ran = Upgrade::ran();

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toBe('Refusing to rename role key guardian to guardian-full: a guardian-full assignment already exists.')
        // The runner recorded the 15 and the 11 that ran, and nothing for the one that stopped or the 2 after it.
        ->and($ran)->toBe([...Upgrade::HISTORICAL, ...migrationsBeforeTheRename()])
        ->and($ran)->toHaveCount(26)
        ->and($ran)->not->toContain('2026_10_09_000002_rename_guardian_role_key.php')
        ->and(DB::connection(Upgrade::CONNECTION)->table('migrations')->orderBy('id')->pluck('batch')->all())->toBe([...array_fill(0, 15, 1), ...array_fill(0, 11, 2)])
        // Not one assignment moved, the five exact `guardian` rows included: the rename is all or nothing.
        ->and($after['role_assignments'])->toBe($before['role_assignments'])
        ->and(collect($after['role_assignments'])->where('role_key', 'guardian')->pluck('id')->all())->toBe(array_map(Fixture::assignment(...), Fixture::RENAMED))
        // And nothing else historical changed either: the only difference is the 11 migration records.
        ->and(Snapshot::differences(Snapshot::expected($before, [], migrationsBeforeTheRename(), 2), $after))->toBe([])
        // The schema is as far as the runner got: the migrations before the rename are in place, those at and after it are not.
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('person_relationships'))->toBeTrue()
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('sourced_role_grants'))->toBeFalse()
        ->and(Schema::connection(Upgrade::CONNECTION)->hasColumn('contact_methods', 'source'))->toBeFalse();
});

it('can be re-run once the refused assignment is gone: it renames and completes, with no earlier migration run twice', function () {
    refusedUpgradeBaseline([13 => [12, 'guardian-full']]);
    expect(refusedUpgradeFailure())->toBeInstanceOf(RuntimeException::class);

    // The operator's remedy is to remove the offending assignment (outside this test: a decision about a person, not a migration's).
    DB::connection(Upgrade::CONNECTION)->table('role_assignments')->where('id', Fixture::assignment(13))->delete();
    $before = Snapshot::take(Upgrade::connect());
    Upgrade::migratePending();
    $after = Snapshot::take(Upgrade::connect());

    expect(Upgrade::ran())->toBe([...Upgrade::HISTORICAL, ...Fixture::UPGRADE_MIGRATIONS])
        ->and(DB::connection(Upgrade::CONNECTION)->table('migrations')->orderBy('id')->pluck('batch')->all())
        ->toBe([...array_fill(0, 15, 1), ...array_fill(0, 11, 2), ...array_fill(0, 3, 3)])
        ->and(collect($after['role_assignments'])->where('role_key', 'guardian-full')->pluck('id')->all())->toBe(array_map(Fixture::assignment(...), Fixture::RENAMED))
        ->and(collect($after['role_assignments'])->where('role_key', 'guardian')->count())->toBe(0)
        ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('sourced_role_grants'))->toBeTrue()
        // Only the five renames and the three records of the second run differ from where the refusal left it.
        ->and(Snapshot::differences(Snapshot::expected($before, array_map(Fixture::assignment(...), Fixture::RENAMED), array_slice(Fixture::UPGRADE_MIGRATIONS, 11), 3), $after))->toBe([]);
});

it('on the SAME Person, a collation-equal variant of guardian-full stops the rename on MariaDB and is renamed beside on PostgreSQL (WP3-02)', function () {
    // Gus holds `guardian`; this gives him `Guardian-Full` too. It is not the exact key, so the migration's own check lets it through; whether the
    // rename then collides is the engine's unique key on (person_id, role_key).
    $before = refusedUpgradeBaseline([13 => [2, 'Guardian-Full']]);
    $failure = refusedUpgradeFailure();
    $after = Snapshot::take(Upgrade::connect());
    $driver = DB::connection(Upgrade::CONNECTION)->getDriverName();

    if ($driver === 'mariadb') {
        // The unique key folds case, so `guardian-full` and `Guardian-Full` are one key: the update is rejected, the transaction leaves every row as
        // it was, and the migration is not recorded. Kept on purpose (owner triage, WP3-02): no collation-aware refusal and no repair.
        expect($failure)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and(Upgrade::ran())->toBe([...Upgrade::HISTORICAL, ...migrationsBeforeTheRename()])
            ->and($after['role_assignments'])->toBe($before['role_assignments'])
            ->and(Snapshot::differences(Snapshot::expected($before, [], migrationsBeforeTheRename(), 2), $after))->toBe([])
            ->and(Schema::connection(Upgrade::CONNECTION)->hasTable('sourced_role_grants'))->toBeFalse();
    } else {
        // PostgreSQL's key is exact: no collision, so the upgrade completes and Gus holds both keys, which differ.
        expect($driver)->toBe('pgsql')
            ->and($failure)->toBeNull()
            ->and(Upgrade::ran())->toBe([...Upgrade::HISTORICAL, ...Fixture::UPGRADE_MIGRATIONS])
            ->and(DB::connection(Upgrade::CONNECTION)->table('role_assignments')->where('person_id', Fixture::person(2))->orderBy('role_key')->pluck('role_key')->all())
            ->toBe(['Guardian-Full', 'guardian-full', 'platform_administrator'])
            ->and(Snapshot::differences(Snapshot::expected($before, array_map(Fixture::assignment(...), Fixture::RENAMED), Fixture::UPGRADE_MIGRATIONS, 2), $after))->toBe([]);
    }
});
