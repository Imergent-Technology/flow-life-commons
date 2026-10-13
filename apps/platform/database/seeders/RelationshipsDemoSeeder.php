<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\GrantRoleToAccount;
use App\Modules\Access\Application\InviteExistingPerson;
use App\Modules\Access\Application\ListRoleCatalog;
use App\Modules\Identity\Application\PersonHasAccount;
use App\Modules\Identity\Application\ResolveActor;
use App\Modules\Membership\Application\GetMembershipRecord;
use App\Modules\Membership\Application\GrantMembershipAccess;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\DecideDefaultRole;
use App\Modules\Relationships\Application\DefaultRoleChoice;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\ListRelationshipCandidates;
use App\Modules\Relationships\Application\NewPersonDetails;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipManagementView;
use App\Modules\Relationships\Application\UpdateRelationshipFields;
use App\Modules\Relationships\Application\UpdateRelationshipPersonBasics;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEVELOPMENT AND TESTING ONLY, and opt-in: Flow Life's Guardians and Volunteers as an operator would have recorded them (ADR 0038, G10
 * WP7), so the Relationships screens, the Person record, Guardian Initiate provisioning and Resource audiences can be reviewed without
 * recording anyone first.
 *
 *     ./flow artisan db:seed --class=RelationshipsDemoSeeder
 *
 * It is not part of DatabaseSeeder, refuses to run outside the local and testing environments (before it reads or writes anything),
 * and is referenced by nothing in the application. Everything is written through the use cases an operator's requests reach, as a
 * real operator, so every row is one the platform could have made: intake (a new Person through CRM's minimal registration, with
 * duplicate advice), status changes and their history, field values and their editor, the explicit Guardian Initiate decision and the
 * relationship-managed grant Access makes for it (withdrawn by inactivation), a Volunteer manager's scoped basic-detail correction,
 * Membership's own grant, an invitation to an Account (mailed, as ever, to the development mailbox), and one independent role
 * assignment (chosen from Access's role catalog, never named here). Recent verification is a property of an HTTP session, which a seeder has none of; the use cases do not ask for it.
 *
 * The operator is {@see E2eAccountSeeder::DEMO_OPERATOR} when it exists (the browser suite makes it once and never recreates it, so
 * the history it signs never names an Account that is gone), and otherwise the first ACTIVE Account, by email, that holds every
 * capability the demo needs (an administrator). It creates no role, Account or Person except through those use cases.
 *
 * The people (see {@see self::people()}): ten, each showing something, several more than one.
 * - Guardians: an Active Guardian with no role at all (declined); a former Guardian, now Inactive (granted, then inactivated, which
 *   withdrew the grant); an Active Guardian with relationship-managed Guardian Initiate access (invited to an Account); an Active
 *   Guardian with the Guardian Full role AND relationship-managed Guardian Initiate access; Guardians with no Account.
 * - A Person with no Account whose Active Guardian relationship records a GRANTED decision: Access holds an inert grant for the Person,
 *   no Account is created, and access would take effect only once an operator invites them and they accept.
 * - Volunteers: Pending; Active; Inactive; Inactive and reactivated on the same relationship; a Volunteer who is a Guardian; a
 *   Volunteer who is an Active Member; Volunteers with no Account.
 * - A Guardian who is a Member, and a Person who is a Guardian, a Volunteer and a Member.
 *
 * Each step runs with the clock held at its own fixed past instant (the person's story, in order), so the data never depends on the day
 * it is made. The exception is an invitation (and the role assigned with it), issued on the real clock so that it can still be
 * accepted. The clock is put back as it was.
 *
 * Repeatable: a Person is recognised by their exact email, through intake's own lookup. A relationship that already exists is left
 * alone, with everything an operator has since done to it, and none of its steps is taken again; a Person already invited is not
 * invited again; a Person who already has any Membership grant is not granted another; an assigned role is assigned idempotently. Running this again adds nothing.
 * Each use case is its own transaction, so a run that fails part-way leaves whole steps behind and the next run finishes the rest.
 */
final class RelationshipsDemoSeeder extends Seeder
{
    public const string ACTIVE_NO_ROLE = 'Amara Okafor';

    public const string FORMER = 'Bernard Lindqvist';

    public const string MANAGED_INITIATE = 'Chiara Benedetti';

    public const string FULL_AND_INITIATE = 'Desmond Achterberg';

    public const string GRANTED_NO_ACCOUNT = 'Esi Mensah';

    public const string PENDING_VOLUNTEER = 'Farah Haddad';

    public const string ACTIVE_VOLUNTEER = 'Gideon Park';

    public const string INACTIVE_VOLUNTEER = 'Hana Kowalczyk';

    public const string REACTIVATED_VOLUNTEER = 'Idris Barzani';

    public const string VOLUNTEER_MEMBER = 'Joaquin Reyes';

    /** The capabilities the demo's operator must hold, each for the use cases named. */
    private const array CAPABILITIES = [
        Capability::ManageGuardians,          // Guardian intake, status, fields and decisions
        Capability::ManageVolunteers,         // Volunteer intake, status, fields and basic details
        Capability::AssignRoles,              // granting Guardian Initiate through the relationship, and the one independent role
        Capability::ManageMembershipRecords,  // Membership's grant
        Capability::ViewMembershipRecords,    // whether a Person already has one
        Capability::IssueInvitations,         // inviting a Person to an Account
        Capability::ViewAccounts,             // reading the role catalog, as the Account page does
    ];

    /**
     * Everyone, in the order they are recorded. A relationship's steps are taken in order, each at its own instant:
     * - `['record', status, default role or null, fields]`: intake (a new Person for their first relationship, the same Person after);
     * - `['status', status, default role or null]`;
     * - `['fields', changes]`;
     * - `['decide', grant|decline|defer]`;
     * - `['basics', changes]` (a Volunteer manager's correction: `{detail: [from, to]}`).
     *
     * @return list<array{name: string, email: string, account: bool, fullRole: bool, member: string|null, relationships: list<array{type: string, steps: list<array{0: string, 1: string, 2?: mixed, 3?: mixed, 4?: mixed}>}>}>
     */
    public static function people(): array
    {
        return [
            [
                'name' => self::ACTIVE_NO_ROLE, 'email' => 'demo.amara.okafor@example.org', 'account' => false, 'fullRole' => false, 'member' => '2025-11-01 00:00:00',
                'relationships' => [[
                    'type' => 'guardian', 'steps' => [
                        ['record', '2026-03-02 10:00:00', 'active', 'decline', []],
                        ['fields', '2026-03-02 10:05:00', ['recognized_on' => '2019-04-06', 'stewardship' => 'The garden and the quiet room']],
                    ],
                ]],
            ],
            [
                'name' => self::FORMER, 'email' => 'demo.bernard.lindqvist@example.org', 'account' => false, 'fullRole' => false, 'member' => null,
                'relationships' => [[
                    'type' => 'guardian', 'steps' => [
                        ['record', '2026-03-03 10:00:00', 'active', 'grant', ['stewardship' => 'Opening on Tuesdays']],
                        ['status', '2026-08-20 16:00:00', 'inactive', null],
                    ],
                ]],
            ],
            [
                'name' => self::MANAGED_INITIATE, 'email' => 'demo.chiara.benedetti@example.org', 'account' => true, 'fullRole' => false, 'member' => null,
                'relationships' => [
                    [
                        'type' => 'guardian', 'steps' => [
                            ['record', '2026-03-04 10:00:00', 'active', 'grant', ['recognized_on' => '2025-09-14']],
                        ],
                    ],
                    [
                        'type' => 'volunteer', 'steps' => [
                            ['record', '2026-03-10 11:00:00', 'active', null, ['interests' => 'Welcoming new visitors; first aid']],
                            ['basics', '2026-04-01 09:30:00', ['primary_phone' => [null, '+1 555 010 0142']]],
                        ],
                    ],
                ],
            ],
            [
                'name' => self::FULL_AND_INITIATE, 'email' => 'demo.desmond.achterberg@example.org', 'account' => true, 'fullRole' => true, 'member' => null,
                'relationships' => [[
                    'type' => 'guardian', 'steps' => [
                        ['record', '2026-03-05 10:00:00', 'active', 'grant', ['stewardship' => 'Rota and keys']],
                    ],
                ]],
            ],
            [
                'name' => self::GRANTED_NO_ACCOUNT, 'email' => 'demo.esi.mensah@example.org', 'account' => false, 'fullRole' => false, 'member' => '2026-01-15 00:00:00',
                'relationships' => [
                    [
                        'type' => 'guardian', 'steps' => [
                            ['record', '2026-03-06 10:00:00', 'active', 'grant', []],
                        ],
                    ],
                    [
                        'type' => 'volunteer', 'steps' => [
                            ['record', '2026-03-06 10:30:00', 'pending', null, []],
                            ['status', '2026-03-20 10:00:00', 'active', null],
                            ['fields', '2026-03-20 10:05:00', ['availability' => 'Saturday mornings']],
                        ],
                    ],
                ],
            ],
            [
                'name' => self::PENDING_VOLUNTEER, 'email' => 'demo.farah.haddad@example.org', 'account' => false, 'fullRole' => false, 'member' => null,
                'relationships' => [[
                    'type' => 'volunteer', 'steps' => [
                        ['record', '2026-09-01 10:00:00', 'pending', null, ['interests' => 'Gardening']],
                    ],
                ]],
            ],
            [
                'name' => self::ACTIVE_VOLUNTEER, 'email' => 'demo.gideon.park@example.org', 'account' => false, 'fullRole' => false, 'member' => null,
                'relationships' => [[
                    'type' => 'volunteer', 'steps' => [
                        ['record', '2026-04-01 10:00:00', 'active', null, ['interests' => 'Tea room and setting up', 'availability' => 'Weekday evenings']],
                    ],
                ]],
            ],
            [
                'name' => self::INACTIVE_VOLUNTEER, 'email' => 'demo.hana.kowalczyk@example.org', 'account' => false, 'fullRole' => false, 'member' => null,
                'relationships' => [[
                    'type' => 'volunteer', 'steps' => [
                        ['record', '2026-04-02 10:00:00', 'pending', null, []],
                        ['status', '2026-04-09 10:00:00', 'active', null],
                        ['status', '2026-07-31 17:00:00', 'inactive', null],
                    ],
                ]],
            ],
            [
                'name' => self::REACTIVATED_VOLUNTEER, 'email' => 'demo.idris.barzani@example.org', 'account' => false, 'fullRole' => false, 'member' => null,
                'relationships' => [[
                    'type' => 'volunteer', 'steps' => [
                        ['record', '2026-04-03 10:00:00', 'active', null, ['availability' => 'Sundays']],
                        ['status', '2026-06-01 10:00:00', 'inactive', null],
                        ['status', '2026-09-07 10:00:00', 'active', null],
                    ],
                ]],
            ],
            [
                'name' => self::VOLUNTEER_MEMBER, 'email' => 'demo.joaquin.reyes@example.org', 'account' => false, 'fullRole' => false, 'member' => '2026-02-01 00:00:00',
                'relationships' => [[
                    'type' => 'volunteer', 'steps' => [
                        ['record', '2026-04-04 10:00:00', 'active', null, ['interests' => 'Music for the gatherings']],
                    ],
                ]],
            ],
        ];
    }

    public function run(): void
    {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The Relationships demo data may only be seeded in a local or testing environment.');
        }

        $operator = $this->operator();
        $previous = Carbon::getTestNow();

        try {
            foreach (self::people() as $person) {
                $this->seed($operator, $person);
            }
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * @param  array{name: string, email: string, account: bool, fullRole: bool, member: string|null, relationships: list<array{type: string, steps: list<array{0: string, 1: string, 2?: mixed, 3?: mixed, 4?: mixed}>}>}  $spec
     */
    private function seed(Actor $as, array $spec): void
    {
        $id = null;
        foreach ($spec['relationships'] as $relationship) {
            $type = $this->type($relationship['type']);
            [$found, $held] = $this->lookup($as, $type, $spec['email']);
            $id ??= $found;
            if ($held) {
                continue; // already recorded: left alone, whatever has happened to it since
            }

            $view = null;
            foreach ($relationship['steps'] as $step) {
                $this->at($step[1]);
                $view = $this->step($as, $type, $spec, $id, $view, $step);
                $id = $view->person->id;
            }
        }
        if ($id === null) {
            throw new RuntimeException("The demo Person {$spec['name']} was not recorded.");
        }

        $first = $spec['relationships'][0]['steps'][0][1];
        // The operator's own member-detail read (not the eligibility read, which only Resources' audience eligibility may call): a Person who
        // has ever been granted Membership, active or since revoked, is left as they are.
        if ($spec['member'] !== null && ($this->container->make(GetMembershipRecord::class))($as, $id)->grants === []) {
            $this->at($first);
            ($this->container->make(GrantMembershipAccess::class))($as, $id, new DateTimeImmutable($spec['member'].' UTC'), null, MembershipGrantSource::Operator, 'demo');
        }
        if ($spec['account']) {
            // On the real clock: an invitation is valid for days from when it is issued, and one dated in the demo's past would have
            // expired before anyone could accept it.
            Carbon::setTestNow();
            $this->invite($as, $id, $spec['email']);
            if ($spec['fullRole']) {
                // Independent of the relationship: an operator assigning a role on the Account page.
                ($this->container->make(GrantRoleToAccount::class))($as, $this->accountOf($id), $this->trustedGuardianRole($as));
            }
        }
    }

    /**
     * @param  array{name: string, email: string, account: bool, fullRole: bool, member: string|null, relationships: list<array{type: string, steps: list<array{0: string, 1: string, 2?: mixed, 3?: mixed, 4?: mixed}>}>}  $spec
     * @param  array{0: string, 1: string, 2?: mixed, 3?: mixed, 4?: mixed}  $step
     */
    private function step(Actor $as, RelationshipType $type, array $spec, ?PersonId $person, ?RelationshipManagementView $view, array $step): RelationshipManagementView
    {
        $kind = $step[0];
        if ($kind === 'record') {
            $status = self::str($step[2] ?? null);
            $role = $step[3] ?? null;
            $fields = self::map($step[4] ?? []);

            return ($this->container->make(EstablishRelationship::class))(
                $as, $type, $person, $person !== null, $status, $fields, $role !== null, $role,
                $person === null ? new NewPersonDetails($spec['name'], $spec['email']) : null,
            );
        }
        if ($view === null || $person === null) {
            throw new RuntimeException("A demo relationship of {$spec['name']} must be recorded before it is changed.");
        }

        return match ($kind) {
            'status' => ($this->container->make(ChangeRelationshipStatus::class))(
                $as, $type, $person, $view->id, $view->revision, self::str($step[2] ?? null), ($step[3] ?? null) !== null, $step[3] ?? null,
            ),
            'fields' => ($this->container->make(UpdateRelationshipFields::class))($as, $type, $person, $view->id, $view->revision, self::map($step[2] ?? [])),
            'decide' => ($this->container->make(DecideDefaultRole::class))(
                $as, $type, $person, $view->id, $view->revision, DefaultRoleChoice::fromRequest($step[2] ?? null),
            ),
            'basics' => ($this->container->make(UpdateRelationshipPersonBasics::class))($as, $type, $person, $view->id, array_map(
                static fn (mixed $change): array => is_array($change) ? ['from' => $change[0] ?? null, 'to' => $change[1] ?? null] : [],
                self::map($step[2] ?? []),
            )),
            default => throw new RuntimeException("Unknown demo step {$kind}."),
        };
    }

    /**
     * The Person intake's own lookup finds by this exact email, and whether they already hold the type.
     *
     * @return array{PersonId|null, bool}
     */
    private function lookup(Actor $as, RelationshipType $type, string $email): array
    {
        foreach (($this->container->make(ListRelationshipCandidates::class))($as, $type, $email)->candidates as $candidate) {
            if ($candidate->matchedOn === 'email') {
                return [$candidate->person->id, $candidate->status !== null];
            }
        }

        return [null, false];
    }

    /** Invites the Person to an Account, through the operator's own use case, unless they already have one. */
    private function invite(Actor $as, PersonId $person, string $email): void
    {
        try {
            ($this->container->make(InviteExistingPerson::class))($as, $person, $email);
        } catch (PersonHasAccount) {
            // invited before: left as it is (accepted or not)
        }
    }

    /**
     * The role an operator gives a trusted Guardian on the Account page, chosen from Access's own catalog as that page offers it: the
     * first role, in the catalog's order, that may manage Resources and does not administer access (today Guardian Full). No role key
     * is written here (AccessBoundariesTest keeps every key inside Access); a catalog with no such role stops the demo, and says so.
     */
    private function trustedGuardianRole(Actor $as): string
    {
        foreach (($this->container->make(ListRoleCatalog::class))($as) as $role) {
            if (in_array(Capability::ManageResources->value, $role->capabilities, true) && ! in_array(Capability::AssignRoles->value, $role->capabilities, true)) {
                return $role->key;
            }
        }

        throw new RuntimeException('Access offers no role that may manage Resources without administering access.');
    }

    /** The Account the Person was invited to (a read: Identity owns it). */
    private function accountOf(PersonId $person): AccountId
    {
        $id = DB::table('accounts')->where('person_id', $person->value)->value('id');

        return is_string($id) ? AccountId::fromString($id) : throw new RuntimeException('The demo Person has no Account to assign a role to.');
    }

    private function at(string $instant): void
    {
        Carbon::setTestNow(Carbon::parse($instant.' UTC'));
    }

    private function type(string $key): RelationshipType
    {
        return $this->container->make(RelationshipCatalog::class)->type($key)
            ?? throw new RuntimeException("The relationship type {$key} is needed for the demo.");
    }

    /**
     * The stable demo operator when it exists, otherwise the first active Account, by email, that holds every capability the demo
     * needs. Never one invented here.
     */
    private function operator(): Actor
    {
        $resolve = $this->container->make(ResolveActor::class);
        $authorize = $this->container->make(AuthorizeAction::class);
        $candidates = DB::table('accounts')->where('status', 'active')
            ->orderByRaw('case when email_canonical = ? then 0 else 1 end', [E2eAccountSeeder::DEMO_OPERATOR[0]])
            ->orderBy('email')->pluck('id');

        foreach ($candidates as $id) {
            $actor = is_string($id) ? $resolve(AccountId::fromString($id)) : null;
            if ($actor === null) {
                continue;
            }
            try {
                foreach (self::CAPABILITIES as $capability) {
                    $authorize($actor, $capability);
                }
            } catch (AccessDenied) {
                continue;
            }

            return $actor;
        }

        throw new RuntimeException('There is no active Account that may manage Guardians, Volunteers, roles, Membership and invitations to write the demo data as. Create an administrator first (identity:create-administrator).');
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('A demo step needs a status.');
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
