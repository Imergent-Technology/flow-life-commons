<?php

declare(strict_types=1);

use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\GrantSourcedRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Access\Domain\SourcedRoleGrant;
use App\Modules\Access\Domain\SourcedRoleGrantId;
use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Application\PersonExists;
use App\Modules\Identity\Application\ResolveActor;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Infrastructure\Mail\InvitationMail;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\DecideDefaultRole;
use App\Modules\Relationships\Application\DefaultRoleChoice;
use App\Modules\Relationships\Application\QualifyingRelationships;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Invitations;
use Tests\Support\Mfa;

/*
 * Guardian default-role provisioning (ADR 0038, Part K, F13, F15, M4). A sourced Guardian Initiate
 * grant exists when the relationship is Active, the decision is granted, and the grant is that
 * relationship's current default role for that Person. Removing the inactivation withdrawal, or
 * granting the operator instead of the locked subject, fails these tests.
 */

function provisionedGuardian(Console $console, string $name, string $status = 'active', ?string $decision = 'defer'): string
{
    $person = Identity::savedPerson($name)->id->value;
    $body = ['person_id' => $person, 'confirm_existing_person' => true, 'status' => $status];
    if ($decision !== null) {
        $body['default_role'] = $decision;
    }
    $console->post('/api/v1/admin/relationships/guardians', $body)->assertCreated();

    return $person;
}

function guardianId(string $person): string
{
    $id = DB::table('person_relationships')->where('person_id', $person)->where('relationship_type', 'guardian')->value('id');
    assert(is_string($id));

    return $id;
}

/** @return array{0: string, 1: int} */
function guardianCursor(string $person): array
{
    $row = DB::table('person_relationships')->where('person_id', $person)->where('relationship_type', 'guardian')->first();
    assert($row !== null && is_string($row->id) && is_numeric($row->revision));

    return [$row->id, (int) $row->revision];
}

function plantRelationshipGrant(string $personId, string $sourceId, string $role = 'guardian-initiate'): void
{
    DB::table('sourced_role_grants')->insert([
        'id' => strtolower((string) Str::ulid()),
        'person_id' => $personId,
        'role_key' => $role,
        'source_type' => 'relationship',
        'source_id' => $sourceId,
        'granted_by_account_id' => null,
        'granted_at' => '2026-10-09 12:00:00',
    ]);
}

function plantDecision(string $relationshipId, string $decidedBy, string $decision): void
{
    DB::table('person_relationship_role_provisions')->insert([
        'relationship_id' => $relationshipId,
        'role_key' => 'guardian-initiate',
        'decision' => $decision,
        'decided_at' => '2026-10-09 12:00:00',
        'decided_by' => $decidedBy,
    ]);
}

/**
 * An operator who may manage Guardians and may not assign roles. Relationships still asks the real
 * Authorizer for `guardians.manage`; only Access's own `access.roles.assign` check, inside
 * `GrantSourcedRole`, is answered by an Authorizer that finds the Person holds no role. No G10 role
 * holds one authority without the other (A3), so this is the one way to reach the cell.
 */
function denyRoleAssignment(): void
{
    $noAssignments = new class implements RoleAssignmentRepository
    {
        public function forPerson(PersonId $personId): array
        {
            return [];
        }

        public function forPeople(array $personIds): array
        {
            return [];
        }

        public function add(RoleAssignment $assignment): void
        {
            throw new LogicException('read-only');
        }

        public function remove(PersonId $personId, string $roleKey): bool
        {
            throw new LogicException('read-only');
        }

        public function holdersOf(string $roleKey): array
        {
            return [];
        }

        public function lockHoldersOf(string $roleKey): array
        {
            return [];
        }
    };
    $noGrants = new class implements SourcedRoleGrantRepository
    {
        public function forPerson(PersonId $personId): array
        {
            return [];
        }

        public function forPeople(array $personIds): array
        {
            return [];
        }

        public function forSourceType(string $sourceType): array
        {
            return [];
        }

        public function forSources(array $sources): array
        {
            return [];
        }

        public function findBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
        {
            return null;
        }

        public function lockBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant
        {
            return null;
        }

        public function lockSource(string $sourceType, string $sourceId): void
        {
            throw new LogicException('read-only');
        }

        public function lockForSource(string $sourceType, string $sourceId): array
        {
            return [];
        }

        public function add(SourcedRoleGrant $grant): void
        {
            throw new LogicException('read-only');
        }

        public function delete(SourcedRoleGrantId $id): void
        {
            throw new LogicException('read-only');
        }

        public function personIdsHoldingRoles(array $roleKeys): array
        {
            return [];
        }
    };
    $authorizer = new Authorizer(app(ResolveActor::class), $noAssignments, $noGrants);
    app()->bind(GrantSourcedRole::class, fn () => new GrantSourcedRole(
        new AuthorizeAction($authorizer),
        app(PersonExists::class),
        app(SourcedRoleGrantRepository::class),
        app(RecordSecurityEvent::class),
        app(ConnectionInterface::class),
    ));
}

/** Signs in the Account that an accepted invitation created for the Person. Console admission requires its authenticator (ADR 0023). */
function guardianInvitee(string $person, string $email, string $password): Console
{
    $account = app(AccountRepository::class)->findByPersonId(PersonId::fromString($person));
    assert($account !== null);
    Mfa::enroll($account);
    $invitee = new Console;
    $invitee->loginWithMfa($email, $password)->assertOk();

    return $invitee;
}

function guardianMailedToken(): string
{
    $sent = Mail::sent(InvitationMail::class);
    expect($sent)->toHaveCount(1);
    $mail = $sent->first();
    assert($mail instanceof InvitationMail);
    preg_match('~/accept-invitation#token=([A-Za-z0-9_-]{43})~', $mail->render(), $matches);

    return $matches[1] ?? '';
}

function failOnSecurityEvent(string $type): void
{
    $inner = app(SecurityEventWriter::class);
    app()->instance(SecurityEventWriter::class, new class($inner, $type) implements SecurityEventWriter
    {
        public function __construct(private SecurityEventWriter $inner, private string $type) {}

        public function append(SecurityEvent $event): void
        {
            if ($event->type === $this->type) {
                throw new RuntimeException('audit failed');
            }
            $this->inner->append($event);
        }
    });
}

it('requires an explicit decision to recognize an Active Guardian, and grants only that Person', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Recognized')->id->value;

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertStatus(422)->assertJson(['code' => 'default_role_decision_required']);
    expect(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);

    $granted = $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
        'default_role' => 'grant', 'fields' => ['stewardship' => 'Choir loft'],
    ])->assertCreated();

    expect($granted->json('revision'))->toBe(1)
        ->and($granted->json('default_role.decision'))->toBe('granted')
        ->and($granted->json('default_role.effective'))->toBeTrue()
        ->and($granted->json('default_role.display_name'))->toBe('Guardian Initiate')
        ->and($granted->json('default_role.decided_by.id'))->toBe($admin->personId->value)
        ->and(DB::table('sourced_role_grants')->where('person_id', $person)->value('role_key'))->toBe('guardian-initiate')
        ->and(DB::table('sourced_role_grants')->where('source_id', guardianId($person))->count())->toBe(1)
        ->and(DB::table('person_relationships')->where('person_id', $person)->value('person_id'))->toBe($person);
    $event = Identity::events('role.granted')[0];
    $context = Identity::context($event);
    expect($event->actor_account_id)->toBe($admin->id->value)
        ->and($event->subject_person_id)->toBe($person)
        ->and($context)->toBe([
            'role' => 'guardian-initiate',
            'source_type' => 'relationship',
            'source_id' => guardianId($person),
        ])
        ->and(Mfa::auditText())->not->toContain('Choir loft');
});

it('records decline and defer on intake without granting', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $declined = provisionedGuardian($console, 'Declined Person', decision: 'decline');
    $deferred = provisionedGuardian($console, 'Deferred Person', decision: 'defer');

    expect(DB::table('person_relationship_role_provisions')->where('relationship_id', guardianId($declined))->value('decision'))->toBe('declined')
        ->and(DB::table('person_relationship_role_provisions')->where('relationship_id', guardianId($declined))->value('decided_by'))->toBe($admin->personId->value)
        ->and(DB::table('person_relationship_role_provisions')->where('relationship_id', guardianId($deferred))->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.granted'))->toBe([])
        ->and(Identity::events('role.revoked'))->toBe([]);
});

it('refuses a default-role decision on inactive intake and writes nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Former')->id->value;
    $before = DB::table('people')->count();

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'inactive', 'default_role' => 'defer',
    ])->assertStatus(422)->assertJson(['code' => 'default_role_not_applicable']);

    expect(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('people')->count())->toBe($before)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'inactive',
    ])->assertCreated();
    expect(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0);
});

it('withdraws the sourced grant on inactivation and keeps the decision', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Stepping Down', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    expect(DB::table('sourced_role_grants')->count())->toBe(1);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive', 'default_role' => 'decline',
    ])->assertStatus(422)->assertJson(['code' => 'default_role_not_applicable']);
    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('active')
        ->and(DB::table('sourced_role_grants')->count())->toBe(1);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertOk();

    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('inactive')
        ->and(DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->value('decision'))->toBe('granted')
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.revoked'))->toHaveCount(1)
        ->and(Identity::events('relationship.deleted'))->toBe([]);
    expect(Identity::context(Identity::events('role.revoked')[0])['source_id'])->toBe($id);
});

it('treats reactivation as a fresh decision and does not restore a grant silently', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Returning', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertOk();
    [$id, $revision] = guardianCursor($person);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'active',
    ])->assertStatus(422)->assertJson(['code' => 'default_role_decision_required']);
    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('inactive')
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);

    $restorer = Access::actorFor(Access::admin('restorer@example.org', 'Restorer'));
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);
    $view = app(ChangeRelationshipStatus::class)(
        $restorer, $type, PersonId::fromString($person), RelationshipId::fromString($id), $revision, 'active', true, DefaultRoleChoice::Grant,
    );

    expect($view->status)->toBe('active')
        ->and($view->defaultRole?->decision)->toBe('granted')
        ->and($view->defaultRole?->decidedBy?->id->value)->toBe($restorer->personId->value)
        ->and(DB::table('sourced_role_grants')->where('person_id', $person)->value('granted_by_account_id'))->toBe($restorer->accountId->value)
        ->and(DB::table('sourced_role_grants')->value('person_id'))->toBe($person)
        ->and(Identity::events('role.granted'))->toHaveCount(2);
    expect(DB::table('person_relationship_role_provisions')->value('decided_by'))->not->toBe($admin->personId->value);
});

it('keeps a declined decision when reactivation defers, and does not turn it into a grant', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Still Declined', decision: 'decline');
    [$id, $revision] = guardianCursor($person);
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertOk();
    [$id, $revision] = guardianCursor($person);
    $restorer = Access::actorFor(Access::admin('restorer@example.org', 'Restorer'));
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);

    app(ChangeRelationshipStatus::class)(
        $restorer, $type, PersonId::fromString($person), RelationshipId::fromString($id), $revision, 'active', true, DefaultRoleChoice::Defer,
    );

    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('active')
        ->and(DB::table('person_relationship_role_provisions')->value('decision'))->toBe('declined')
        ->and(DB::table('person_relationship_role_provisions')->value('decided_by'))->toBe($admin->personId->value)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('ignores default_role on a same-status request', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Unchanged', decision: 'defer');
    [$id, $revision] = guardianCursor($person);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'active', 'default_role' => 'grant',
    ])->assertOk();
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'active', 'default_role' => 'not-a-decision',
    ])->assertOk();

    expect(DB::table('person_relationships')->where('id', $id)->value('revision'))->toBe(1)
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('refuses an invalid default_role on the status route and writes nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Invalid Choice', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertOk();
    [, $revision] = guardianCursor($person);
    $history = DB::table('person_relationship_status_changes')->count();
    $grants = DB::table('sourced_role_grants')->count();

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'active', 'default_role' => 'promote',
    ])->assertStatus(422)->assertJson(['code' => 'invalid_relationship_query']);

    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('inactive')
        ->and(DB::table('person_relationships')->where('id', $id)->value('revision'))->toBe($revision)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe($history)
        ->and(DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->value('decision'))->toBe('granted')
        ->and(DB::table('sourced_role_grants')->count())->toBe($grants)
        ->and(Identity::events('role.granted'))->toHaveCount(1);
});

it('decides the default role on its own route, and an identical decision does not rewrite it', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Later Grant', decision: 'defer');
    [$id, $revision] = guardianCursor($person);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision,
    ])->assertStatus(422)->assertJson(['code' => 'default_role_decision_required']);
    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'promote',
    ])->assertStatus(422)->assertJson(['code' => 'invalid_relationship_query']);

    $granted = $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'grant',
    ])->assertOk();
    expect($granted->json('revision'))->toBe(2)
        ->and($granted->json('default_role.effective'))->toBeTrue()
        ->and(DB::table('sourced_role_grants')->where('person_id', $person)->count())->toBe(1);
    $stored = DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->first();
    assert($stored !== null);

    $restorer = Access::actorFor(Access::admin('restorer@example.org', 'Restorer'));
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);
    $repeat = app(DecideDefaultRole::class)(
        $restorer, $type, PersonId::fromString($person), RelationshipId::fromString($id), 2, DefaultRoleChoice::Grant,
    );
    $again = DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->first();
    expect($repeat->revision)->toBe(2)
        ->and($repeat->defaultRole?->decidedBy?->id->value)->toBe($admin->personId->value)
        ->and($again?->decided_by)->toBe($stored->decided_by)
        ->and($again?->decided_at)->toBe($stored->decided_at)
        ->and(Identity::events('role.granted'))->toHaveCount(1)
        ->and(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('sourced_role_grants')->value('person_id'))->toBe($person);
});

it('declines and defers without role-assignment authority, and does not erase a decline', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Chooser', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    denyRoleAssignment();

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'grant',
    ])->assertForbidden()->assertJson(['code' => 'role_provisioning_not_permitted']);
    expect(DB::table('person_relationship_role_provisions')->value('decision'))->toBe('granted')
        ->and(DB::table('person_relationship_role_provisions')->value('decided_by'))->toBe($admin->personId->value)
        ->and(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('person_relationships')->value('revision'))->toBe(1);

    $declined = $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'decline',
    ])->assertOk();
    expect($declined->json('revision'))->toBe(2)
        ->and($declined->json('default_role.decision'))->toBe('declined')
        ->and($declined->json('default_role.effective'))->toBeFalse()
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => 2, 'default_role' => 'defer',
    ])->assertOk();
    expect(DB::table('person_relationship_role_provisions')->value('decision'))->toBe('declined')
        ->and(DB::table('person_relationship_role_provisions')->value('decided_by'))->toBe($admin->personId->value)
        ->and(DB::table('person_relationships')->value('revision'))->toBe(2);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => 2, 'default_role' => 'decline',
    ])->assertOk();
    expect(DB::table('person_relationships')->value('revision'))->toBe(2)
        ->and(Identity::events('role.revoked'))->toHaveCount(1);
});

it('removes a granted decision and withdraws its grant when an Active relationship defers', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Backs Out', decision: 'grant');
    [$id, $revision] = guardianCursor($person);

    $deferred = $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'defer',
    ])->assertOk();

    expect($deferred->json('revision'))->toBe(2)
        ->and($deferred->json('default_role.decision'))->toBeNull()
        ->and($deferred->json('default_role.effective'))->toBeFalse()
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.granted'))->toHaveCount(1)
        ->and(Identity::events('role.revoked'))->toHaveCount(1)
        ->and(Identity::events('role.revoked')[0]->actor_account_id)->toBe($admin->id->value);

    // Nothing is stored now, so a second defer changes nothing and records nothing.
    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => 2, 'default_role' => 'defer',
    ])->assertOk()->assertJsonPath('revision', 2);
    expect(Identity::events('role.revoked'))->toHaveCount(1);
});

it('reactivates with no stored decision only on an explicit grant', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Never Decided', decision: 'defer');
    /** @param  array<string, mixed>  $extra */
    $change = function (string $to, array $extra = []) use ($console, $person) {
        [$id, $revision] = guardianCursor($person);

        /** @var array<string, mixed> $body */
        $body = ['relationship_id' => $id, 'revision' => $revision, 'status' => $to, ...$extra];

        return $console->put("/api/v1/admin/relationships/guardians/{$person}/status", $body);
    };
    $change('inactive')->assertOk();

    $change('active')->assertStatus(422)->assertJson(['code' => 'default_role_decision_required']);
    expect(DB::table('person_relationships')->where('person_id', $person)->value('status'))->toBe('inactive');

    $change('active', ['default_role' => 'defer'])->assertOk();
    expect(DB::table('person_relationships')->where('person_id', $person)->value('status'))->toBe('active')
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);

    $change('inactive')->assertOk();
    $change('active', ['default_role' => 'grant'])->assertOk();
    expect(DB::table('sourced_role_grants')->where('person_id', $person)->count())->toBe(1)
        ->and(DB::table('person_relationship_role_provisions')->value('decision'))->toBe('granted');
});

it('lets a manager without role-assignment authority reactivate only by deferring or declining', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Needs Authority', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertOk();
    [, $revision] = guardianCursor($person);
    $history = DB::table('person_relationship_status_changes')->count();
    denyRoleAssignment();
    $status = fn (string $choice) => $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'active', 'default_role' => $choice,
    ]);

    $status('grant')->assertForbidden()->assertJson(['code' => 'role_provisioning_not_permitted']);
    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('inactive')
        ->and(DB::table('person_relationships')->where('id', $id)->value('revision'))->toBe($revision)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe($history)
        ->and(DB::table('person_relationship_role_provisions')->value('decision'))->toBe('granted')
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);

    $status('defer')->assertOk();
    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('active')
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('refuses a grant on an inactive relationship before asking Access, and leaves no write', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Inactive Grant', 'inactive', null);
    [$id, $revision] = guardianCursor($person);
    denyRoleAssignment();

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'grant',
    ])->assertStatus(409)->assertJson(['code' => 'relationship_not_active']);

    expect(DB::table('person_relationship_role_provisions')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(DB::table('person_relationships')->value('revision'))->toBe(1)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('rolls intake back when Access refuses the grant', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Rolled Back')->id->value;
    denyRoleAssignment();

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant',
    ])->assertForbidden()->assertJson(['code' => 'role_provisioning_not_permitted']);
    expect(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('person_relationship_role_provisions')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('rolls intake back when the grant audit fails after the decision was prepared', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Audit Grant')->id->value;
    failOnSecurityEvent('role.granted');

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant',
    ])->assertStatus(500);

    expect(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('rolls inactivation back when the withdrawal audit fails', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Audit Withdraw', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    $history = DB::table('person_relationship_status_changes')->count();
    failOnSecurityEvent('role.revoked');

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertStatus(500);
    expect(DB::table('person_relationships')->where('id', $id)->value('status'))->toBe('active')
        ->and(DB::table('person_relationships')->where('id', $id)->value('revision'))->toBe($revision)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe($history)
        ->and(DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->value('decision'))->toBe('granted')
        ->and(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(Identity::events('role.revoked'))->toBe([]);
});

it('rolls deletion back when the deletion audit fails after withdrawal was prepared', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Audit Delete', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    $history = DB::table('person_relationship_status_changes')->where('relationship_id', $id)->count();
    failOnSecurityEvent('relationship.deleted');

    $console->delete("/api/v1/admin/relationships/guardians/{$person}?relationship_id={$id}&revision={$revision}")
        ->assertStatus(500);

    expect(DB::table('person_relationships')->where('id', $id)->exists())->toBeTrue()
        ->and(DB::table('person_relationship_status_changes')->where('relationship_id', $id)->count())->toBe($history)
        ->and(DB::table('person_relationship_role_provisions')->where('relationship_id', $id)->value('decision'))->toBe('granted')
        ->and(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(Identity::events('relationship.deleted'))->toBe([])
        ->and(Identity::events('role.revoked'))->toBe([]);
});

it('refuses the default-role route to a Console user without guardians.manage', function () {
    [$guardian] = Mfa::signedIn();
    $person = Identity::savedPerson('Guarded')->id->value;
    $guardian->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => strtolower((string) Str::ulid()), 'revision' => 1, 'default_role' => 'grant',
    ])->assertForbidden()->assertJsonMissing(['code' => 'role_provisioning_not_permitted']);
    expect(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('refuses the default-role route for the usual reasons, and does not offer it for Volunteer', function () {
    [$console] = Mfa::signedInAdmin();
    $subject = provisionedGuardian($console, 'Verified Later', decision: 'defer');
    $other = provisionedGuardian($console, 'Other Guardian', decision: 'defer');
    [$staleId, $staleRevision] = guardianCursor($subject);
    [$otherId] = guardianCursor($other);
    $console->put("/api/v1/admin/relationships/guardians/{$subject}/default-role", [
        'relationship_id' => $otherId, 'revision' => $staleRevision, 'default_role' => 'grant',
    ])->assertStatus(409)->assertJson(['code' => 'stale_revision']);
    $console->put("/api/v1/admin/relationships/guardians/{$subject}/default-role", [
        'relationship_id' => $staleId, 'revision' => $staleRevision + 1, 'default_role' => 'grant',
    ])->assertStatus(409)->assertJson(['code' => 'stale_revision']);
    $console->put('/api/v1/admin/relationships/volunteers/'.$subject.'/default-role', [
        'relationship_id' => $staleId, 'revision' => 1, 'default_role' => 'grant',
    ])->assertNotFound();

    $console->tamperSession(fn ($session) => $session->forget('security_verified_at'));
    $console->put("/api/v1/admin/relationships/guardians/{$subject}/default-role", [
        'relationship_id' => $staleId, 'revision' => $staleRevision, 'default_role' => 'grant',
    ])->assertForbidden()->assertJson(['verification_required' => true]);
    expect(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.volunteers.default-role'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('api.v1.admin.relationships.guardians.default-role'))->not->toBeNull();
});

it('shows the default-role state on the Guardian record and management views only', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Visible', decision: 'grant');
    Access::grant(PersonId::fromString($person), Role::GuardianFull);

    $record = $console->get("/api/v1/admin/relationships/guardians/{$person}")->assertOk();
    $management = $console->get("/api/v1/admin/relationships/guardians/{$person}/management")->assertOk();
    foreach ([$record, $management] as $response) {
        expect($response->json('default_role.display_name'))->toBe('Guardian Initiate')
            ->and($response->json('default_role.decision'))->toBe('granted')
            ->and($response->json('default_role.effective'))->toBeTrue()
            ->and($response->json('default_role.decided_by.id'))->toBe($admin->personId->value)
            ->and($response->getContent())->not->toContain('guardian-full');
    }
    $directory = $console->get('/api/v1/admin/relationships/guardians')->assertOk()->json('data.0');
    assert(is_array($directory));
    expect(array_keys($directory))->toEqualCanonicalizing(['relationship_id', 'person', 'status', 'status_changed_at', 'basics']);

    $volunteer = Identity::savedPerson('Volunteer')->id->value;
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $volunteer, 'confirm_existing_person' => true, 'status' => 'pending',
    ])->assertCreated();
    expect($console->get("/api/v1/admin/relationships/volunteers/{$volunteer}")->json('default_role'))->toBeNull();
});

it('withdraws only this relationship on deletion and keeps independent assignments', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Removed', decision: 'grant');
    $personId = PersonId::fromString($person);
    foreach ([Role::GuardianFull, Role::GuardianSenior, Role::PlatformAdministrator, Role::GuardianInitiate] as $role) {
        Access::grant($personId, $role);
    }
    $other = provisionedGuardian($console, 'Other Source', decision: 'grant');
    [$id, $revision] = guardianCursor($person);

    $console->delete("/api/v1/admin/relationships/guardians/{$person}?relationship_id={$id}&revision={$revision}")
        ->assertNoContent();

    expect(DB::table('person_relationships')->where('person_id', $person)->exists())->toBeFalse()
        ->and(DB::table('sourced_role_grants')->where('source_id', $id)->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('source_id', guardianId($other))->count())->toBe(1)
        ->and(DB::table('role_assignments')->where('person_id', $person)->orderBy('role_key')->pluck('role_key')->all())->toBe([
            'guardian-full', 'guardian-initiate', 'guardian-senior', 'platform_administrator',
        ])
        ->and(Identity::events('relationship.deleted'))->toHaveCount(1)
        ->and(Identity::context(Identity::events('relationship.deleted')[0])['withdrawn_grants'])->toBe(1);
});

it('grants Guardian Initiate beside higher roles and leaves those assignments when the grant is withdrawn', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Already Senior', decision: 'defer');
    $personId = PersonId::fromString($person);
    foreach ([Role::GuardianFull, Role::GuardianSenior, Role::PlatformAdministrator, Role::GuardianInitiate] as $role) {
        Access::grant($personId, $role);
    }
    [$id, $revision] = guardianCursor($person);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision, 'default_role' => 'grant',
    ])->assertOk();
    expect(DB::table('role_assignments')->where('person_id', $person)->count())->toBe(4)
        ->and(DB::table('sourced_role_grants')->where('person_id', $person)->where('role_key', 'guardian-initiate')->count())->toBe(1);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/default-role", [
        'relationship_id' => $id, 'revision' => $revision + 1, 'default_role' => 'decline',
    ])->assertOk();
    expect(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(DB::table('role_assignments')->where('person_id', $person)->count())->toBe(4);
});

it('recognizes an Active Guardian who holds no Access role as a Guardian', function () {
    [$console] = Mfa::signedInAdmin();
    [$member, $account] = Mfa::signedInMember('plain@example.org');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $account->personId->value, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer',
    ])->assertCreated();

    expect($member->me()->json('capabilities'))->toBe([])
        ->and(array_map(fn ($type) => $type->key, app(QualifyingRelationships::class)($account->personId)))->toBe(['guardian'])
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('stores a dormant grant that becomes effective only after a trusted invitation, and not after inactivation', function () {
    Mail::fake();
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Account Later');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person->id->value, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant',
    ])->assertCreated();
    expect(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeFalse()
        ->and(DB::table('sourced_role_grants')->where('person_id', $person->id->value)->count())->toBe(1);

    $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'later@example.org'])->assertCreated();
    Invitations::accept(guardianMailedToken(), 'a long enough passphrase for the invitee')->assertNoContent();
    $invitee = guardianInvitee($person->id->value, 'later@example.org', 'a long enough passphrase for the invitee');

    expect($invitee->me()->json('capabilities'))->toContain('console.access')
        ->and(DB::table('sourced_role_grants')->value('person_id'))->toBe($person->id->value)
        ->and(DB::table('sourced_role_grants')->value('granted_by_account_id'))->toBe($admin->id->value)
        ->and(Identity::events('role.granted'))->toHaveCount(1);
});

it('has already withdrawn a dormant grant when the Guardian is inactivated before the invitation is accepted', function () {
    Mail::fake();
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Left Early', decision: 'grant');
    [$id, $revision] = guardianCursor($person);
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => $revision, 'status' => 'inactive',
    ])->assertOk();
    expect(DB::table('sourced_role_grants')->count())->toBe(0);

    $console->post("/api/v1/admin/people/{$person}/invitation", ['email' => 'early@example.org'])->assertCreated();
    Invitations::accept(guardianMailedToken(), 'a long enough passphrase for the invitee')->assertNoContent();
    $invitee = guardianInvitee($person, 'early@example.org', 'a long enough passphrase for the invitee');
    expect($invitee->me()->json('capabilities'))->toBe([])
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('reports every sourced-grant inconsistency and no field value', function (string $prepare, string $finding) {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Checked', 'active', 'defer');
    $id = guardianId($person);
    $console->patch("/api/v1/admin/relationships/guardians/{$person}/fields", [
        'relationship_id' => $id, 'revision' => 1, 'fields' => ['stewardship' => 'do-not-print-this-value'],
    ])->assertOk();

    match ($prepare) {
        'missing' => plantRelationshipGrant($person, strtolower((string) Str::ulid())),
        'inactive' => (function () use ($person, $id, $admin): void {
            DB::table('person_relationships')->where('id', $id)->update(['status' => 'inactive']);
            plantDecision($id, $admin->personId->value, 'granted');
            plantRelationshipGrant($person, $id);
        })(),
        'decision' => plantRelationshipGrant($person, $id),
        'role' => (function () use ($person, $id, $admin): void {
            plantDecision($id, $admin->personId->value, 'granted');
            plantRelationshipGrant($person, $id, 'guardian-full');
        })(),
        'person' => (function () use ($id, $admin): void {
            $other = Identity::savedPerson('Someone Else')->id->value;
            plantDecision($id, $admin->personId->value, 'granted');
            plantRelationshipGrant($other, $id);
        })(),
        'absent' => plantDecision($id, $admin->personId->value, 'granted'),
        default => throw new RuntimeException($prepare),
    };

    expect(Artisan::call('relationships:check'))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain($finding)
        ->and($output)->not->toContain('do-not-print-this-value');
})->with([
    'missing relationship' => ['missing', 'has no relationship'],
    'not qualifying' => ['inactive', 'relationship is not qualifying'],
    'decision not granted' => ['decision', 'decision is not granted'],
    'role differs' => ['role', 'role is not the current default role'],
    'person differs' => ['person', 'person differs from the relationship'],
    'granted without a grant' => ['absent', 'is granted without a matching grant'],
]);

it('reports a sourced grant of a role no relationship can confer even when its relationship is missing, without naming the role or repairing anything', function () {
    $holder = Identity::savedPerson('Planted Administrator')->id->value;
    $source = strtolower((string) Str::ulid());
    plantRelationshipGrant($holder, $source, 'platform_administrator');
    plantRelationshipGrant(Identity::savedPerson('Plain Orphan')->id->value, strtolower((string) Str::ulid()));

    expect(Artisan::call('relationships:check'))->toBe(1);
    $output = Artisan::output();

    expect($output)->toContain("sourced grant {$source} has no relationship")
        ->and($output)->toContain("sourced grant {$source} role is not provisionable")
        ->and(substr_count($output, 'role is not provisionable'))->toBe(1) // the valid orphan is not accused of it
        ->and($output)->not->toContain('platform_administrator')
        ->and(DB::table('sourced_role_grants')->count())->toBe(2);
});

it('reports several grant findings from one read', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Several', decision: 'defer');
    $id = guardianId($person);
    plantRelationshipGrant($person, $id);
    plantRelationshipGrant(Identity::savedPerson('Orphan Holder')->id->value, strtolower((string) Str::ulid()));
    plantDecision($id, $admin->personId->value, 'declined');

    expect(Artisan::call('relationships:check'))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('decision is not granted')
        ->and($output)->toContain('has no relationship');
});

it('lists Console participants and Guardian gaps without adopting anyone', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $noAccount = provisionedGuardian($console, 'No Account Yet', 'inactive', null);
    $plain = Identity::savedActiveAccount('plain-guardian@example.org', name: 'Plain Guardian');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $plain->personId->value, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'defer',
    ])->assertCreated();
    $dormant = Identity::savedPerson('Dormant Grant');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $dormant->id->value, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant',
    ])->assertCreated();

    $relationships = DB::table('person_relationships')->count();
    $grants = DB::table('sourced_role_grants')->count();
    $people = DB::table('people')->count();

    expect(Artisan::call('relationships:guardian-adoption-report'))->toBe(0);
    $text = Artisan::output();
    expect($text)->toContain('Many Console participants are not Guardians.')
        ->and($text)->toContain('This report writes nothing.')
        ->and($text)->toContain('Relationships are consistent with their definitions.')
        ->and($text)->not->toContain('admin@example.org')
        ->and($text)->not->toContain('plain-guardian@example.org');

    $between = static function (string $start, string $end) use ($text): string {
        $from = strpos($text, $start);
        $to = strpos($text, $end);
        assert($from !== false && $to !== false && $to > $from);

        return substr($text, $from, $to - $from);
    };
    expect($between('Console access without a Guardian relationship', 'Guardian relationships whose Person has no Account'))
        ->toContain($admin->personId->value)
        ->and($between('Guardian relationships whose Person has no Account', 'Guardian relationships whose Person lacks console access'))
        ->toContain($noAccount)
        ->toContain($dormant->id->value)
        ->and($between('Guardian relationships whose Person lacks console access', 'relationships:check'))
        ->toContain($plain->personId->value)
        ->and($between('Guardian relationships whose Person lacks console access', 'relationships:check'))
        ->not->toContain($dormant->id->value);
    expect(DB::table('person_relationships')->count())->toBe($relationships)
        ->and(DB::table('sourced_role_grants')->count())->toBe($grants)
        ->and(DB::table('people')->count())->toBe($people);
});

it('exits nonzero from the adoption report when the integrity check has a finding', function () {
    [$console] = Mfa::signedInAdmin();
    $person = provisionedGuardian($console, 'Inconsistent', decision: 'defer');
    plantRelationshipGrant($person, guardianId($person));

    expect(Artisan::call('relationships:guardian-adoption-report'))->toBe(1)
        ->and(Artisan::output())->toContain('decision is not granted')
        ->and(DB::table('person_relationships')->count())->toBe(1);
});
