<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Relationships\Application\CheckRelationships;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\NewPersonDetails;
use App\Modules\Relationships\Application\RelationshipCatalog;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\E2eAccountSeeder;
use Database\Seeders\RelationshipsDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Resources;
use Tests\Support\SourceScan;

/*
 * The Relationships demo dataset (ADR 0038, G10 WP7): who it records and in what state, that every row came from a use case an operator
 * could have run (attributed history, the explicit Initiate decision, Access's own grants and withdrawals, Membership's own grant, an
 * invitation), that it can be run again without adding or changing anything, and that it can only run where a demo belongs. Runs on
 * MariaDB and PostgreSQL.
 */

/** Runs the demo seeder as though the application were in the named environment (the default is `testing`). */
function seedRelationshipsDemo(?string $environment = null): void
{
    $original = app()->environment();
    if ($environment !== null) {
        app()->instance('env', $environment);
        config()->set('app.env', $environment);
    }

    try {
        (new RelationshipsDemoSeeder)->setContainer(app())->run();
    } finally {
        app()->instance('env', $original);
        config()->set('app.env', $original);
    }
}

/**
 * The tables the demo writes, as counts.
 *
 * @return array<string, int>
 */
function relationshipsDemoCounts(): array
{
    $counts = [];
    foreach (['people', 'accounts', 'person_relationships', 'person_relationship_status_changes', 'person_relationship_field_values', 'person_relationship_role_provisions', 'sourced_role_grants', 'role_assignments', 'membership_grants', 'account_invitations', 'contact_methods', 'security_events'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/**
 * Every relationship row and its children, as plain arrays in a fixed order.
 *
 * @return array<string, list<array<mixed>>>
 */
function relationshipsDemoSnapshot(): array
{
    $rows = [];
    foreach ([
        'person_relationships' => ['id'],
        'person_relationship_status_changes' => ['id'],
        'person_relationship_field_values' => ['relationship_id', 'field_key'],
        'person_relationship_role_provisions' => ['relationship_id', 'role_key'],
        'sourced_role_grants' => ['id'],
        'role_assignments' => ['person_id', 'role_key'],
        'membership_grants' => ['id'],
    ] as $table => $order) {
        $query = DB::table($table);
        foreach ($order as $column) {
            $query->orderBy($column);
        }
        $rows[$table] = array_values($query->get()->map(fn ($row) => (array) $row)->all());
    }

    return $rows;
}

function demoPersonId(string $name): string
{
    return Resources::str(DB::table('people')->where('display_name', $name)->value('id'));
}

/** @return array<string, string> type => status */
function demoRelationshipsOf(string $name): array
{
    $out = [];
    foreach (DB::table('person_relationships')->where('person_id', demoPersonId($name))->orderBy('relationship_type')->get() as $row) {
        $out[Resources::str($row->relationship_type)] = Resources::str($row->status);
    }

    return $out;
}

/** @return list<array{string|null, string}> a relationship's history, oldest first */
function demoHistoryOf(string $name, string $type): array
{
    $id = DB::table('person_relationships')->where('person_id', demoPersonId($name))->where('relationship_type', $type)->value('id');

    return array_values(DB::table('person_relationship_status_changes')->where('relationship_id', $id)->orderBy('changed_at')->orderBy('id')->get()
        ->map(fn (stdClass $row): array => [$row->from_status === null ? null : Resources::str($row->from_status), Resources::str($row->to_status)])->all());
}

function demoDecisionOf(string $name): ?string
{
    $id = DB::table('person_relationships')->where('person_id', demoPersonId($name))->where('relationship_type', 'guardian')->value('id');
    $decision = DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->value('decision');

    return $decision === null ? null : Resources::str($decision);
}

/** @return list<string> */
function demoSourcedRolesOf(string $name): array
{
    return Resources::strings(DB::table('sourced_role_grants')->where('person_id', demoPersonId($name))->pluck('role_key'));
}

/** @return list<string> */
function demoIndependentRolesOf(string $name): array
{
    return Resources::strings(DB::table('role_assignments')->where('person_id', demoPersonId($name))->pluck('role_key'));
}

/** The demo's operator (an administrator), made once per test. */
function demoOperator(): Account
{
    return app(AccountRepository::class)->findByEmail(EmailAddress::fromString('demo.administrator@example.org'))
        ?? Access::admin('demo.administrator@example.org', 'Demo Administrator');
}

/** Relationship history names the operator's Person. */
function demoOperatorAccount(): string
{
    return demoOperator()->personId->value;
}

it('records each Guardian and Volunteer in the state it exists to show', function () {
    demoOperatorAccount();
    seedRelationshipsDemo();

    expect(demoRelationshipsOf(RelationshipsDemoSeeder::ACTIVE_NO_ROLE))->toBe(['guardian' => 'active'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::FORMER))->toBe(['guardian' => 'inactive'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::MANAGED_INITIATE))->toBe(['guardian' => 'active', 'volunteer' => 'active'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::FULL_AND_INITIATE))->toBe(['guardian' => 'active'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::GRANTED_NO_ACCOUNT))->toBe(['guardian' => 'active', 'volunteer' => 'active'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::PENDING_VOLUNTEER))->toBe(['volunteer' => 'pending'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::ACTIVE_VOLUNTEER))->toBe(['volunteer' => 'active'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::INACTIVE_VOLUNTEER))->toBe(['volunteer' => 'inactive'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::REACTIVATED_VOLUNTEER))->toBe(['volunteer' => 'active'])
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::VOLUNTEER_MEMBER))->toBe(['volunteer' => 'active'])
        ->and(DB::table('person_relationships')->count())->toBe(12);
});

it('keeps each status change as history, on the same relationship, attributed to the operator', function () {
    $operator = demoOperatorAccount();
    seedRelationshipsDemo();

    expect(demoHistoryOf(RelationshipsDemoSeeder::FORMER, 'guardian'))->toBe([[null, 'active'], ['active', 'inactive']])
        ->and(demoHistoryOf(RelationshipsDemoSeeder::INACTIVE_VOLUNTEER, 'volunteer'))->toBe([[null, 'pending'], ['pending', 'active'], ['active', 'inactive']])
        // Reactivated on the SAME relationship: one row, three changes, revision 3.
        ->and(demoHistoryOf(RelationshipsDemoSeeder::REACTIVATED_VOLUNTEER, 'volunteer'))->toBe([[null, 'active'], ['active', 'inactive'], ['inactive', 'active']])
        ->and(DB::table('person_relationships')->where('person_id', demoPersonId(RelationshipsDemoSeeder::REACTIVATED_VOLUNTEER))->count())->toBe(1)
        ->and(Resources::int(DB::table('person_relationships')->where('person_id', demoPersonId(RelationshipsDemoSeeder::REACTIVATED_VOLUNTEER))->value('revision')))->toBe(3)
        ->and(DB::table('person_relationship_status_changes')->distinct()->pluck('changed_by')->all())->toBe([$operator])
        ->and(DB::table('person_relationships')->distinct()->pluck('created_by')->all())->toBe([$operator])
        ->and(DB::table('person_relationship_field_values')->distinct()->pluck('updated_by')->all())->toBe([$operator])
        ->and(DB::table('person_relationship_role_provisions')->distinct()->pluck('decided_by')->all())->toBe([$operator])
        // On the demo's own clock, never today's.
        ->and(DB::table('person_relationship_status_changes')->min('changed_at'))->toStartWith('2026-03-02 10:00:00')
        ->and(DB::table('person_relationship_status_changes')->max('changed_at'))->toBeLessThan('2026-10-01');
});

it('makes Guardian Initiate access only through the explicit decision, withdraws it on inactivation, and keeps an independent role independent', function () {
    demoOperatorAccount();
    seedRelationshipsDemo();

    // Declined: Active, no grant, no role.
    expect(demoDecisionOf(RelationshipsDemoSeeder::ACTIVE_NO_ROLE))->toBe('declined')
        ->and(demoSourcedRolesOf(RelationshipsDemoSeeder::ACTIVE_NO_ROLE))->toBe([])
        ->and(demoIndependentRolesOf(RelationshipsDemoSeeder::ACTIVE_NO_ROLE))->toBe([])
        // Granted, then inactivated: the decision is kept, the grant is gone (and is not restored by itself).
        ->and(demoDecisionOf(RelationshipsDemoSeeder::FORMER))->toBe('granted')
        ->and(demoSourcedRolesOf(RelationshipsDemoSeeder::FORMER))->toBe([])
        // Granted: Access holds the relationship-managed grant, and nothing else.
        ->and(demoDecisionOf(RelationshipsDemoSeeder::MANAGED_INITIATE))->toBe('granted')
        ->and(demoSourcedRolesOf(RelationshipsDemoSeeder::MANAGED_INITIATE))->toBe(['guardian-initiate'])
        ->and(demoIndependentRolesOf(RelationshipsDemoSeeder::MANAGED_INITIATE))->toBe([])
        // Both: an independently assigned Guardian Full (chosen from Access's catalog) beside the relationship-managed Guardian Initiate.
        ->and(demoSourcedRolesOf(RelationshipsDemoSeeder::FULL_AND_INITIATE))->toBe(['guardian-initiate'])
        ->and(demoIndependentRolesOf(RelationshipsDemoSeeder::FULL_AND_INITIATE))->toBe(['guardian-full'])
        // No Guardian Initiate for anyone who is only a Volunteer.
        ->and(demoSourcedRolesOf(RelationshipsDemoSeeder::ACTIVE_VOLUNTEER))->toBe([]);

    // Each grant is Access's: one role.granted with the relationship as its source, and one role.revoked for the withdrawal.
    $granted = DB::table('security_events')->where('type', 'role.granted')->get();
    $sourced = $granted->filter(fn (stdClass $e): bool => str_contains(Resources::str($e->context), 'relationship'));
    expect($sourced)->toHaveCount(4) // Bernard (later withdrawn), Chiara, Desmond, Esi
        ->and(DB::table('security_events')->where('type', 'role.revoked')->where('subject_person_id', demoPersonId(RelationshipsDemoSeeder::FORMER))->count())->toBe(1);
});

it('records a granted decision for a Person with no Account without creating one: the grant waits, inert', function () {
    demoOperatorAccount();
    $accounts = DB::table('accounts')->count();
    seedRelationshipsDemo();

    $esi = demoPersonId(RelationshipsDemoSeeder::GRANTED_NO_ACCOUNT);
    expect(demoDecisionOf(RelationshipsDemoSeeder::GRANTED_NO_ACCOUNT))->toBe('granted')
        ->and(demoSourcedRolesOf(RelationshipsDemoSeeder::GRANTED_NO_ACCOUNT))->toBe(['guardian-initiate'])
        ->and(DB::table('accounts')->where('person_id', $esi)->exists())->toBeFalse()
        ->and(DB::table('account_invitations')->count())->toBe(2)
        // Only the two Guardians whose access is the point were invited; nobody else gained an Account.
        ->and(DB::table('accounts')->count())->toBe($accounts + 2)
        ->and(Resources::strings(DB::table('accounts')->whereIn('person_id', [demoPersonId(RelationshipsDemoSeeder::MANAGED_INITIATE), demoPersonId(RelationshipsDemoSeeder::FULL_AND_INITIATE)])->pluck('status')))->toBe(['invited', 'invited']);
});

it('makes the Members through Membership and the Volunteer manager\'s correction through the scoped path, with its provenance', function () {
    $operator = demoOperatorAccount();
    seedRelationshipsDemo();

    $members = Resources::strings(DB::table('membership_grants')->distinct()->pluck('person_id'));
    sort($members);
    $expected = [demoPersonId(RelationshipsDemoSeeder::ACTIVE_NO_ROLE), demoPersonId(RelationshipsDemoSeeder::GRANTED_NO_ACCOUNT), demoPersonId(RelationshipsDemoSeeder::VOLUNTEER_MEMBER)];
    sort($expected);
    expect($members)->toBe($expected)
        ->and(DB::table('membership_grants')->distinct()->pluck('granted_by_account_id')->all())->toBe([demoOperator()->id->value]);

    // The phone a Volunteer manager added to a Guardian who is also a Volunteer is CRM's own contact method, stamped with who did it.
    $phone = DB::table('contact_methods')->where('person_id', demoPersonId(RelationshipsDemoSeeder::MANAGED_INITIATE))->where('kind', 'phone')->first();
    expect($phone)->not->toBeNull()
        ->and(Resources::str($phone?->value))->toBe('+1 555 010 0142')
        ->and($phone?->updated_by_account_id)->toBe(demoOperator()->id->value)
        ->and(demoRelationshipsOf(RelationshipsDemoSeeder::MANAGED_INITIATE))->toBe(['guardian' => 'active', 'volunteer' => 'active']);
});

it('leaves the relationships consistent with their definitions and with Access (relationships:check)', function () {
    demoOperatorAccount();
    seedRelationshipsDemo();

    expect(app(CheckRelationships::class)())->toBe([]);
});

it('is run again, on another day, without adding or changing anything', function () {
    demoOperatorAccount();
    seedRelationshipsDemo();
    $counts = relationshipsDemoCounts();
    $snapshot = relationshipsDemoSnapshot();

    Carbon::setTestNow(Carbon::parse('2031-06-01 12:00:00 UTC'));
    try {
        seedRelationshipsDemo();
        seedRelationshipsDemo();
    } finally {
        Carbon::setTestNow();
    }

    expect(relationshipsDemoCounts())->toBe($counts)
        ->and(relationshipsDemoSnapshot())->toEqual($snapshot);
});

it('leaves a relationship an operator already recorded alone, for the same Person, and records only the rest', function () {
    $operator = demoOperatorAccount();
    // Before the demo, an operator recorded Gideon (by the demo's email) as a Pending Volunteer. The demo would make him Active.
    $gideon = app(EstablishRelationship::class)(
        Access::actorFor(demoOperator()), app(RelationshipCatalog::class)->type('volunteer') ?? throw new RuntimeException('no Volunteer type'), null, false, 'pending', [], false, null,
        new NewPersonDetails(RelationshipsDemoSeeder::ACTIVE_VOLUNTEER, 'demo.gideon.park@example.org'),
    );
    seedRelationshipsDemo();

    expect(demoRelationshipsOf(RelationshipsDemoSeeder::ACTIVE_VOLUNTEER))->toBe(['volunteer' => 'pending'])
        ->and(DB::table('people')->where('display_name', RelationshipsDemoSeeder::ACTIVE_VOLUNTEER)->count())->toBe(1)
        ->and(DB::table('person_relationship_field_values')->where('relationship_id', $gideon->id->value)->count())->toBe(0)
        ->and(DB::table('person_relationships')->count())->toBe(12)
        ->and(DB::table('person_relationships')->distinct()->pluck('created_by')->all())->toBe([$operator]);
});

it('refuses to run outside local and testing, and writes nothing', function () {
    demoOperatorAccount();
    $before = relationshipsDemoCounts();

    foreach (['production', 'staging'] as $environment) {
        expect(fn () => seedRelationshipsDemo($environment))->toThrow(RuntimeException::class, 'only be seeded in a local or testing environment');
    }
    expect(relationshipsDemoCounts())->toBe($before);
});

it('runs in the local environment too', function () {
    demoOperatorAccount();
    seedRelationshipsDemo('local');

    expect(DB::table('person_relationships')->count())->toBe(12);
});

it('needs an operator who holds every capability it uses, says so, and prefers the stable demo operator', function () {
    expect(fn () => seedRelationshipsDemo())->toThrow(RuntimeException::class, 'no active Account that may manage Guardians')
        ->and(DB::table('person_relationships')->count())->toBe(0);

    // A Console user who may manage Volunteers but not Guardians, roles, Membership or invitations is not enough.
    Access::grant(Identity::savedActiveAccount('a.guardian@example.org'), 'guardian-full');
    expect(fn () => seedRelationshipsDemo())->toThrow(RuntimeException::class, 'no active Account that may manage Guardians');

    // Of two administrators, the stable demo operator signs the history, though another sorts first by email.
    Access::admin('a.first.administrator@example.org', 'First Administrator');
    $stable = Access::admin(E2eAccountSeeder::DEMO_OPERATOR[0], 'E2E Demo Operator');
    seedRelationshipsDemo();
    expect(DB::table('person_relationships')->distinct()->pluck('created_by')->all())->toBe([$stable->personId->value]);
});

it('is not part of the default seeder, and nothing in the application refers to it', function () {
    $root = dirname(__DIR__, 4);
    expect((string) file_get_contents("{$root}/database/seeders/DatabaseSeeder.php"))->not->toContain('RelationshipsDemo');

    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'RelationshipsDemoSeeder')) {
                $offenders[] = str_replace("{$root}/", '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(class_exists(DatabaseSeeder::class))->toBeTrue()
        ->and(str_contains((string) file_get_contents("{$root}/database/seeders/RelationshipsDemoSeeder.php"), 'RelationshipsDemoSeeder'))->toBeTrue(); // positive control
});

it('writes nothing directly: every row comes from a use case', function () {
    $code = SourceScan::code((string) file_get_contents(dirname(__DIR__, 4).'/database/seeders/RelationshipsDemoSeeder.php'));

    // A read of the Account an invitation made is the one direct query, and it is a read.
    expect(preg_match_all('/DB::table\(([^)]*)\)/', $code, $tables))->toBe(2)
        ->and($tables[1])->toBe(["'accounts'", "'accounts'"])
        ->and(preg_match('/->(insert|insertGetId|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\(/', $code))->toBe(0)
        ->and(preg_match('/->(insert|update|delete)\(/', "<?php DB::table('x')->where('a', 1)->update(['b' => 2]);"))->toBe(1) // positive control
        ->and($code)->toContain('EstablishRelationship')
        ->and($code)->toContain('GrantMembershipAccess')
        ->and($code)->toContain('InviteExistingPerson');
});
