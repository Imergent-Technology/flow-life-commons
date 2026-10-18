<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\Account;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Resources;

/*
 * ADR 0038, M6. The migration renames a permission bundle. It does not create a relationship
 * and it does not change who holds a role. The release that runs it is restore-required:
 * down() is exercised here only as the development reversal.
 *
 * The subject is the migration file itself, loaded as the Migrator loads it (G6 WP3a, D8): its logic is inlined, so nothing in
 * application code can change what it does once it has shipped.
 *
 * What this file proves is the migration's own behaviour: every holder of the key `guardian` holds `guardian-full` afterwards,
 * and nothing else about any assignment changes. What `guardian-full` GRANTS is Access's catalog (`CatalogTest`), and relative to the
 * `guardian` role of the deployed v0.3.0 (which granted `console.access` only) it is a large expansion that the release as a whole
 * carries (People, Discussions, Resources, relationships). The rename preserves that release's bundle for its holders, not
 * production's former one: docs/runbooks/g10-release-readiness.md lists the difference.
 *
 * Runs on MariaDB and PostgreSQL (`./flow test backend --pgsql`). Where the engines differ (a key that differs from `guardian-full`
 * only in case, a trailing space or an accent, G6 WP3-02), each expectation is that engine's own answer, kept on purpose.
 */

/** Runs the real migration file's up() or down(), loaded fresh as the Migrator would load it. */
function runGuardianRoleKeyMigration(string $direction): void
{
    $migration = require database_path('migrations/2026_10_09_000002_rename_guardian_role_key.php');
    assert($migration instanceof Migration && method_exists($migration, 'up') && method_exists($migration, 'down'));

    $direction === 'down' ? $migration->down() : $migration->up();
}

/** What the migration threw, or null when it completed. */
function guardianRoleKeyMigrationFailure(): ?Throwable
{
    try {
        runGuardianRoleKeyMigration('up');
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** Writes a role assignment with a chosen id, so the order the migration visits rows in is fixed by the test, not by chance. */
function plantRoleAssignmentWithId(string $id, PersonId $person, string $roleKey): void
{
    DB::table('role_assignments')->insert([
        'id' => $id, 'person_id' => $person->value, 'role_key' => $roleKey, 'granted_by_account_id' => null, 'granted_at' => '2026-09-19 12:00:00',
    ]);
}

/**
 * Whether this engine's unique key on (person_id, role_key) treats a key that differs only in case, a trailing space or an accent
 * as equal. MariaDB's utf8mb4_unicode_ci does (PAD SPACE, accent- and case-insensitive); PostgreSQL's default collation does not.
 */
function roleKeyUniqueIndexFoldsVariants(): bool
{
    return match (DB::connection()->getDriverName()) {
        'mariadb', 'mysql' => true,
        'pgsql' => false,
        default => throw new LogicException('No expectation for engine '.DB::connection()->getDriverName()),
    };
}

it('renames guardian to guardian-full and leaves every other assignment and the capability set', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org');
    $admin = Identity::savedActiveAccount('admin@example.org');
    Access::plant($guardian->personId, 'guardian');
    Access::grant($admin, Role::PlatformAdministrator);
    $before = DB::table('role_assignments')->count();
    $events = DB::table('security_events')->count();

    expect(app(Authorizer::class)->capabilitiesOf(Access::actorFor($guardian)))->toBe([]);

    runGuardianRoleKeyMigration('up');

    $full = array_map(fn (Capability $capability): string => $capability->value, Role::GuardianFull->capabilities());
    sort($full, SORT_STRING);
    $every = array_map(fn (Capability $capability): string => $capability->value, Capability::cases());
    sort($every, SORT_STRING);

    expect(DB::table('role_assignments')->where('role_key', 'guardian')->count())->toBe(0)
        ->and(DB::table('role_assignments')->where('person_id', $guardian->personId->value)->value('role_key'))->toBe('guardian-full')
        ->and(DB::table('role_assignments')->where('role_key', 'platform_administrator')->count())->toBe(1)
        ->and(DB::table('role_assignments')->count())->toBe($before)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('security_events')->count())->toBe($events)
        ->and(array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($guardian))))->toBe($full)
        ->and(array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($admin))))->toBe($every);
});

it('renames only an exact guardian key and leaves a wrongly cased row granting nothing', function () {
    $exact = Identity::savedActiveAccount('exact-guardian@example.org');
    $cased = Identity::savedActiveAccount('cased-guardian@example.org');
    Access::plant($exact->personId, 'guardian');
    Access::plant($cased->personId, 'Guardian');

    runGuardianRoleKeyMigration('up');

    $full = array_map(fn (Capability $capability): string => $capability->value, Role::GuardianFull->capabilities());
    sort($full, SORT_STRING);

    expect(DB::table('role_assignments')->where('person_id', $exact->personId->value)->value('role_key'))->toBe('guardian-full')
        ->and(DB::table('role_assignments')->where('person_id', $cased->personId->value)->value('role_key'))->toBe('Guardian')
        ->and(array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($exact))))->toBe($full)
        ->and(app(Authorizer::class)->capabilitiesOf(Access::actorFor($cased)))->toBe([]);
});

it('refuses to rename when a guardian-full assignment already exists, and changes nothing', function () {
    $person = Identity::savedActiveAccount('both@example.org');
    Access::grant($person, Role::GuardianFull);
    Access::plant(Identity::savedActiveAccount('old@example.org')->personId, 'guardian');

    expect(fn () => runGuardianRoleKeyMigration('up'))
        ->toThrow(RuntimeException::class, 'guardian-full assignment already exists');

    expect(DB::table('role_assignments')->where('role_key', 'guardian')->count())->toBe(1)
        ->and(DB::table('role_assignments')->where('role_key', 'guardian-full')->count())->toBe(1);
});

it('reverses the rename in development and refuses when a guardian row is already there', function () {
    $person = Identity::savedActiveAccount('renamed@example.org');
    Access::plant($person->personId, 'guardian');
    runGuardianRoleKeyMigration('up');

    runGuardianRoleKeyMigration('down');

    expect(DB::table('role_assignments')->where('person_id', $person->personId->value)->value('role_key'))->toBe('guardian')
        ->and(app(Authorizer::class)->capabilitiesOf(Access::actorFor($person)))->toBe([]);

    expect(fn () => runGuardianRoleKeyMigration('down'))
        ->toThrow(RuntimeException::class, 'a guardian assignment already exists');

    expect(DB::table('role_assignments')->where('person_id', $person->personId->value)->value('role_key'))->toBe('guardian');
});

/**
 * Every role assignment row as stored, in a stable order.
 *
 * @return list<array<string, mixed>>
 */
function roleAssignmentSnapshot(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = array_values(DB::table('role_assignments')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());

    return $rows;
}

/**
 * The snapshot as the rename should leave it: each exact `guardian` key becomes `guardian-full`, and nothing else changes.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function withExactGuardianRenamed(array $rows): array
{
    return array_map(function (array $row): array {
        if ($row['role_key'] === 'guardian') {
            $row['role_key'] = 'guardian-full';
        }

        return $row;
    }, $rows);
}

/**
 * @return list<string> the capability ids the Person holds, as the real Authorizer decides them
 */
function heldBy(Account $account): array
{
    $held = array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($account)));
    sort($held, SORT_STRING);

    return $held;
}

it('moves exactly the guardian rows to guardian-full and nothing else, for every kind of holder, and /me follows', function () {
    $bundle = array_map(fn (Capability $capability): string => $capability->value, Role::GuardianFull->capabilities());
    sort($bundle, SORT_STRING);
    $consoleOnly = ['console.access'];
    $every = Access::everyCapabilityId();

    // A Person holding the legacy `guardian` key; one holding it beside the administrator role; the administrator alone; and one
    // holder of each role key that already exists in the new catalog, plus a wrongly cased key that was never this role.
    $legacy = Identity::savedActiveAccount('legacy@example.org', name: 'Legacy Guardian');
    $both = Identity::savedActiveAccount('both-roles@example.org', name: 'Guardian And Administrator');
    $administrator = Identity::savedActiveAccount('administrator@example.org', name: 'Administrator Only');
    $senior = Identity::savedActiveAccount('senior@example.org', name: 'Senior');
    $initiate = Identity::savedActiveAccount('initiate@example.org', name: 'Initiate');
    $participant = Identity::savedActiveAccount('participant@example.org', name: 'Participant');
    $cased = Identity::savedActiveAccount('cased@example.org', name: 'Wrongly Cased');
    Access::plant($legacy->personId, 'guardian');
    Access::plant($both->personId, 'guardian');
    Access::grant($both, Role::PlatformAdministrator);
    Access::grant($administrator, Role::PlatformAdministrator);
    Access::grant($senior, Role::GuardianSenior);
    Access::grant($initiate, Role::GuardianInitiate);
    Access::grant($participant, Role::ConsoleParticipant);
    Access::plant($cased->personId, 'Guardian');
    foreach ([$legacy, $both, $administrator, $senior, $initiate, $participant, $cased] as $account) {
        Mfa::enroll($account);
    }

    // Signed in BEFORE the rename: the same session sees the change, because capabilities are derived on every request.
    $console = new Console;
    $console->loginWithMfa('legacy@example.org', Identity::PASSWORD)->assertOk();
    expect($console->me()->assertOk()->json('capabilities'))->toBe([]);

    $before = roleAssignmentSnapshot();
    $counts = fn (): array => [
        DB::table('security_events')->count(), DB::table('person_relationships')->count(), DB::table('sourced_role_grants')->count(),
        DB::table('accounts')->count(), DB::table('people')->count(),
    ];
    $others = $counts();
    expect($before)->toHaveCount(8)
        ->and(heldBy($legacy))->toBe([])
        ->and(heldBy($both))->toBe($every)
        ->and(heldBy($cased))->toBe([]);

    runGuardianRoleKeyMigration('up');

    // The whole table: every row is what it was, except that the two exact `guardian` keys are now `guardian-full`.
    $expected = array_map(function (array $row): array {
        if ($row['role_key'] === 'guardian') {
            $row['role_key'] = 'guardian-full';
        }

        return $row;
    }, $before);
    expect(roleAssignmentSnapshot())->toBe($expected)
        // Compared in PHP: MariaDB matches the key case-insensitively, so a count in SQL would count the wrongly cased row too.
        ->and(array_count_values(array_map(Resources::str(...), array_column(roleAssignmentSnapshot(), 'role_key'))))->toMatchArray([
            'guardian-full' => 2, 'Guardian' => 1, 'platform_administrator' => 2, 'guardian-senior' => 1, 'guardian-initiate' => 1, 'console-participant' => 1,
        ])
        ->and(in_array('guardian', array_column(roleAssignmentSnapshot(), 'role_key'), true))->toBeFalse()
        ->and($counts())->toBe($others);

    // What each holder is granted afterwards, decided by the real Authorizer.
    expect(heldBy($legacy))->toBe($bundle)
        ->and(heldBy($both))->toBe($every)
        ->and(heldBy($administrator))->toBe($every)
        ->and(heldBy($senior))->toBe($bundle)
        ->and(heldBy($initiate))->toBe($consoleOnly)
        ->and(heldBy($participant))->toBe($consoleOnly)
        ->and(heldBy($cased))->toBe([]);

    // And over HTTP: the session that was open across the rename now reports the bundle, and a fresh sign-in of each other holder
    // reports theirs. No relationship is created for anyone.
    expect($console->me()->assertOk()->json('capabilities'))->toBe($bundle);
    $reported = function (string $email): mixed {
        $session = new Console;
        $session->loginWithMfa($email, Identity::PASSWORD)->assertOk();

        return $session->me()->assertOk()->json('capabilities');
    };
    expect($reported('both-roles@example.org'))->toBe($every)
        ->and($reported('administrator@example.org'))->toBe($every)
        ->and($reported('senior@example.org'))->toBe($bundle)
        ->and($reported('initiate@example.org'))->toBe($consoleOnly)
        ->and($reported('participant@example.org'))->toBe($consoleOnly);
    expect(DB::table('person_relationships')->count())->toBe(0);
});

it('is not repeatable: a second run is refused because the first made a guardian-full row, and it changes nothing', function () {
    $legacy = Identity::savedActiveAccount('legacy@example.org', name: 'Legacy Guardian');
    Access::plant($legacy->personId, 'guardian');
    runGuardianRoleKeyMigration('up');
    $after = roleAssignmentSnapshot();

    expect(fn () => runGuardianRoleKeyMigration('up'))
        ->toThrow(RuntimeException::class, 'a guardian-full assignment already exists')
        ->and(roleAssignmentSnapshot())->toBe($after);
});

it('refuses on a guardian-full row held by anyone, the same Person or another, and on none changes a row', function (string $holder) {
    $legacy = Identity::savedActiveAccount('legacy@example.org', name: 'Legacy Guardian');
    $other = Identity::savedActiveAccount('other@example.org', name: 'Other');
    Access::plant($legacy->personId, 'guardian');
    Access::grant($holder === 'the same Person' ? $legacy : $other, Role::GuardianFull);
    $before = roleAssignmentSnapshot();

    expect(fn () => runGuardianRoleKeyMigration('up'))
        ->toThrow(RuntimeException::class, 'a guardian-full assignment already exists')
        ->and(roleAssignmentSnapshot())->toBe($before);
})->with(['the same Person', 'another Person']);

it('does nothing, and does not refuse, when no assignment exists at all', function () {
    expect(roleAssignmentSnapshot())->toBe([]);

    runGuardianRoleKeyMigration('up');

    expect(roleAssignmentSnapshot())->toBe([]);
});

it('renames a guardian key whoever holds it: a Person with no Account, an invited Account, a disabled Account', function () {
    $bundle = array_map(fn (Capability $capability): string => $capability->value, Role::GuardianFull->capabilities());
    sort($bundle, SORT_STRING);
    $noAccount = Identity::savedPerson('No Account');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    $disabled = Identity::savedDisabledAccount('disabled@example.org');
    Access::plant($noAccount->id, 'guardian');
    Access::plant($invited->personId, 'guardian');
    Access::plant($disabled->personId, 'guardian');
    $before = roleAssignmentSnapshot();

    runGuardianRoleKeyMigration('up');

    // Every one is renamed (WP3-10: a latent holder gains the bundle when the Account becomes active), and none is granted
    // anything while it is not active.
    expect(roleAssignmentSnapshot())->toBe(withExactGuardianRenamed($before))
        ->and(array_column(roleAssignmentSnapshot(), 'role_key'))->toBe(['guardian-full', 'guardian-full', 'guardian-full'])
        ->and(heldBy($invited))->toBe([])
        ->and(heldBy($disabled))->toBe([]);
});

it('leaves a variant of the legacy key on its own Person alone on both engines, and that row grants nothing', function (string $variant) {
    $exact = Identity::savedActiveAccount('exact@example.org', name: 'Exact');
    $holder = Identity::savedActiveAccount('variant@example.org', name: 'Variant');
    Access::plant($exact->personId, 'guardian');
    Access::plant($holder->personId, $variant);

    runGuardianRoleKeyMigration('up');

    // MariaDB's `where role_key = 'guardian'` also returns the variant row; the PHP comparison is what keeps it out.
    expect(DB::table('role_assignments')->where('person_id', $exact->personId->value)->value('role_key'))->toBe('guardian-full')
        ->and(DB::table('role_assignments')->where('person_id', $holder->personId->value)->value('role_key'))->toBe($variant)
        ->and(heldBy($holder))->toBe([]);
})->with([
    'case' => 'Guardian',
    'upper case' => 'GUARDIAN',
    'trailing space' => 'guardian ',
    'accent' => 'guardián',
]);

it('renames beside a variant of guardian-full held by ANOTHER Person on both engines, and the variant grants nothing', function (string $variant) {
    $legacy = Identity::savedActiveAccount('legacy@example.org', name: 'Legacy Guardian');
    $other = Identity::savedActiveAccount('other@example.org', name: 'Other');
    Access::plant($legacy->personId, 'guardian');
    Access::plant($other->personId, $variant);
    $before = roleAssignmentSnapshot();

    // Not the exact key, so not the refusal; and a different Person, so no unique-key collision on either engine.
    runGuardianRoleKeyMigration('up');

    expect(roleAssignmentSnapshot())->toBe(withExactGuardianRenamed($before))
        ->and(roleAssignmentSnapshot())->not->toBe($before)
        ->and(heldBy($other))->toBe([]);
})->with([
    'case' => 'Guardian-Full',
    'upper case' => 'GUARDIAN-FULL',
    'trailing space' => 'guardian-full ',
    'accent' => 'guardián-full',
]);

it('on the SAME Person as guardian, a variant of guardian-full fails the rename on MariaDB and is renamed beside on PostgreSQL (WP3-02)', function (string $variant) {
    $person = Identity::savedActiveAccount('both@example.org', name: 'Both Keys');
    Access::plant($person->personId, 'guardian');
    Access::plant($person->personId, $variant);
    $before = roleAssignmentSnapshot();

    $failure = guardianRoleKeyMigrationFailure();

    if (roleKeyUniqueIndexFoldsVariants()) {
        // Not refused by the migration's own check (the variant is not the exact key), but rejected by the unique key, and the
        // transaction leaves every row as it was. Kept on purpose: no collation-aware refusal and no repair (owner triage, WP3-02).
        expect($failure)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and(roleAssignmentSnapshot())->toBe($before);
    } else {
        $bundle = array_map(fn (Capability $capability): string => $capability->value, Role::GuardianFull->capabilities());
        sort($bundle, SORT_STRING);
        expect($failure)->toBeNull()
            ->and(roleAssignmentSnapshot())->toBe(withExactGuardianRenamed($before))
            ->and(roleAssignmentSnapshot())->not->toBe($before)
            ->and(heldBy($person))->toBe($bundle);
    }
})->with([
    'case' => 'Guardian-Full',
    'upper case' => 'GUARDIAN-FULL',
    'trailing space' => 'guardian-full ',
    'accent' => 'guardián-full',
]);

it('is atomic on its own: a failure part-way through the renames leaves the rows it already renamed as they were (WP3-03)', function () {
    // The migration's explicit transaction is the only one on MariaDB (Laravel wraps a migration in a transaction on PostgreSQL
    // only), and this is the case that needs it. Three Persons hold `guardian`; the middle one also holds a variant of
    // `guardian-full` that MariaDB's unique key treats as equal. The ids fix the order the rows are visited in, and the failing
    // row sits between two that rename cleanly, so whichever way the rows were walked, one would already be renamed when the
    // middle one fails.
    $a = Identity::savedActiveAccount('a@example.org', name: 'A')->personId;
    $b = Identity::savedActiveAccount('b@example.org', name: 'B')->personId;
    $c = Identity::savedActiveAccount('c@example.org', name: 'C')->personId;
    plantRoleAssignmentWithId('01jwp3a0000000000000000001', $a, 'guardian');
    plantRoleAssignmentWithId('01jwp3a0000000000000000002', $b, 'guardian');
    plantRoleAssignmentWithId('01jwp3a0000000000000000003', $b, 'Guardian-Full');
    plantRoleAssignmentWithId('01jwp3a0000000000000000004', $c, 'guardian');
    $before = roleAssignmentSnapshot();
    expect(array_column($before, 'id'))->toBe(['01jwp3a0000000000000000001', '01jwp3a0000000000000000002', '01jwp3a0000000000000000003', '01jwp3a0000000000000000004']);

    $failure = guardianRoleKeyMigrationFailure();

    if (roleKeyUniqueIndexFoldsVariants()) {
        expect($failure)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and(roleAssignmentSnapshot())->toBe($before);
    } else {
        // PostgreSQL sees no collision, so this cannot exercise the transaction there; it pins that engine's own answer.
        expect($failure)->toBeNull()
            ->and(array_column(roleAssignmentSnapshot(), 'role_key'))->toBe(['guardian-full', 'guardian-full', 'Guardian-Full', 'guardian-full']);
    }
});
