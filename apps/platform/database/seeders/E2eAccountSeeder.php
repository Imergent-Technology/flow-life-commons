<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\ConsoleUserFixture;
use App\Modules\Identity\Application\EnrollTotpFixture;
use App\Modules\Identity\Application\ResolveActor;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Membership\Application\GrantMembershipAccess;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipManagementView;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A known active Account for the browser end-to-end test (`./flow test e2e` runs this).
 *
 * DEVELOPMENT ONLY. It refuses to run outside the local and testing environments, and
 * it is not part of DatabaseSeeder. The credentials are public, in this file, and are
 * worth nothing anywhere real. This is a test fixture, not the administrator bootstrap:
 * that is the `identity:create-administrator` command (ADR 0020). It grants nothing itself:
 * it asks Access for a Console user.
 */
final class E2eAccountSeeder extends Seeder
{
    public const string EMAIL = 'e2e.guardian@example.org';

    public const string PASSWORD = 'e2e-fixture-password-not-a-secret';

    /**
     * The credential-lifecycle journey (e2e/credentials.spec.ts) starts from these. Both are RESET on
     * every run, because acceptance and reset are one-time: a pending invitation for an invited Account,
     * and an active Account with a known password. The token and passwords are public and worth
     * nothing: the invitation token is a fixed 43-character value, not a secret.
     */
    public const string INVITEE_EMAIL = 'e2e.invitee@example.org';

    public const string INVITATION_TOKEN = 'e2e-invitation-token-not-a-secret-000000000';

    public const string RECOVERY_EMAIL = 'e2e.recovery@example.org';

    public const string RECOVERY_PASSWORD = 'e2e-recovery-password-not-a-secret';

    /**
     * The Console's browser journeys (e2e/console.spec.ts) start from these, so they do not compete with the
     * API-level journey above for the same one-time steps. All are reset on every run except the
     * no-access account, which no journey changes. Public, worthless, and not secrets.
     */
    public const string NO_ACCESS_EMAIL = 'e2e.noaccess@example.org';

    public const string NO_ACCESS_PASSWORD = 'e2e-noaccess-password-not-a-secret';

    /**
     * The Member self-service surface's own password-change journey (e2e/member.spec.ts), reset on every
     * run like `UI_CHANGE_*` is for the Console's own change-password journey. It needs its own Account,
     * separate from `NO_ACCESS_*`: changing a password ends every OTHER session on that Account, and
     * `NO_ACCESS_*` is read by several spec files in parallel that must never have their sessions cut out
     * from under them. No authenticator: the test it serves exercises an unenrolled Account.
     */
    public const string MEMBER_CHANGE_EMAIL = 'e2e.member.change@example.org';

    public const string MEMBER_CHANGE_PASSWORD = 'e2e-member-change-password-not-a-secret';

    /**
     * The member-foundation journey's OWN Account (e2e/member-foundation.spec.ts, Journey B): active, a known
     * password, NO console.access and NO authenticator — a password-only session is legitimate for it exactly
     * as `NO_ACCESS_*`'s and `SESSION_*`'s are. Unlike those two, this journey GRANTS it console.access mid-test
     * and enrols an authenticator, which is why it needs its own Account rather than sharing one of theirs:
     * `NO_ACCESS_*` and `SESSION_*` are read, unenrolled and without Console access, by several other spec files
     * in parallel, and this journey's own mutations (a role assignment, then an authenticator) must not reach them.
     * No TOTP secret constant: Journey B's authenticator is enrolled live in the browser, the same way any
     * first-time Console enrolment is (its secret comes from the server at that moment, not from a fixture).
     */
    public const string PROMOTION_EMAIL = 'e2e.member.promotion@example.org';

    public const string PROMOTION_PASSWORD = 'e2e-member-promotion-password-not-a-secret';

    public const string UI_INVITEE_EMAIL = 'e2e.ui.invitee@example.org';

    public const string UI_INVITATION_TOKEN = 'e2e-ui-invitation-token-not-a-secret-000000';

    public const string UI_LINK_INVITEE_EMAIL = 'e2e.ui.linkinvitee@example.org';

    public const string UI_LINK_INVITATION_TOKEN = 'e2e-ui-link-invitation-token-not-a-secret-0';

    public const string UI_RECOVERY_EMAIL = 'e2e.ui.recovery@example.org';

    public const string UI_RECOVERY_PASSWORD = 'e2e-ui-recovery-password-not-a-secret';

    public const string UI_CHANGE_EMAIL = 'e2e.ui.change@example.org';

    public const string UI_CHANGE_PASSWORD = 'e2e-ui-change-password-not-a-secret';

    /**
     * Multi-factor authentication (ADR 0023). Every Console user needs a second factor, so the fixtures a journey
     * signs in as are ENROLLED with a KNOWN secret and known recovery codes (the browser cannot see an
     * authenticator app; the journeys compute codes from the secret). Public and worthless, like the passwords.
     * The recovery codes are `E2E<tag>-RC00-0000-000<n>`, n = 0..9, one tag per fixture (see e2e/support.ts).
     */
    public const string GUARDIAN_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public const string UI_RECOVERY_SECRET = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U';

    public const string UI_CHANGE_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** A Console user with a known second factor, for the journeys that sign in with one. */
    public const string MFA_LATER_EMAIL = 'e2e.mfa.later@example.org';

    public const string MFA_LATER_PASSWORD = 'e2e-mfa-later-password-not-a-secret';

    public const string MFA_LATER_SECRET = 'KRSXG5CTMVRXEZLUKN2XGZLSMVZG65DI';

    public const string MFA_RECOVERY_EMAIL = 'e2e.mfa.recovery@example.org';

    public const string MFA_RECOVERY_PASSWORD = 'e2e-mfa-recovery-password-not-a-secret';

    public const string MFA_RECOVERY_SECRET = 'ONSWG4TFOQZDCNRTGEZTQMZQGYYDCMZQ';

    public const string MFA_MANAGE_EMAIL = 'e2e.mfa.manage@example.org';

    public const string MFA_MANAGE_PASSWORD = 'e2e-mfa-manage-password-not-a-secret';

    public const string MFA_MANAGE_SECRET = 'MZXW6YTBOI4TQOJQGEZDGNBVGY3TQOJQ';

    /** A Console user whose replacement is finished from a second tab, leaving the first tab's setup stale. */
    public const string MFA_STALE_EMAIL = 'e2e.mfa.stale@example.org';

    public const string MFA_STALE_PASSWORD = 'e2e-mfa-stale-password-not-a-secret';

    public const string MFA_STALE_SECRET = 'ON2GC3DFFVZWK5DVOAWWKMTFFVTGS6BB';

    public const string MFA_PENDING_EMAIL = 'e2e.mfa.pending@example.org';

    public const string MFA_PENDING_PASSWORD = 'e2e-mfa-pending-password-not-a-secret';

    public const string MFA_PENDING_SECRET = 'NBSWY3DPFQQHO33SNRSCCIBAEBAGCAQA';

    /** Console users for the two Console-spec journeys that sign in with a second factor (one secret per test). */
    public const string CONSOLE_A_EMAIL = 'e2e.console.a@example.org';

    public const string CONSOLE_A_PASSWORD = 'e2e-console-a-password-not-a-secret';

    public const string CONSOLE_A_SECRET = 'MJQXGZJTGIYTCMRSGA4DGNZUGEZDMOBQ';

    public const string CONSOLE_B_EMAIL = 'e2e.console.b@example.org';

    public const string CONSOLE_B_PASSWORD = 'e2e-console-b-password-not-a-secret';

    public const string CONSOLE_B_SECRET = 'NRSWC43FONSXEZLSMFZGK43FNVSXG5DP';

    /** A signed-in-by-password-alone account: no Console access, so no second factor. For session mechanics. */
    public const string SESSION_EMAIL = 'e2e.session@example.org';

    public const string SESSION_PASSWORD = 'e2e-session-password-not-a-secret';

    /**
     * Operator administration (ADR 0024). Each journey has its OWN administrator, because a step-up rotates a session and
     * the platform accepts each authenticator time step once. The sessions they start from are minted by
     * E2eSessionSeeder through the real sign-in, so these journeys do not spend the public login rate budget.
     * Administrators (with a second factor), one plain Console user (a guardian), and the people they act on. Public, worthless.
     */
    public const string ADMIN_READ_EMAIL = 'e2e.admin.read@example.org';

    public const string ADMIN_READ_PASSWORD = 'e2e-admin-read-password-not-a-secret';

    public const string ADMIN_READ_SECRET = 'OXYIMCFIPNZB575Y7MZF7OBR26YHQGEY';

    public const string ADMIN_STALE_EMAIL = 'e2e.admin.stale@example.org';

    public const string ADMIN_STALE_PASSWORD = 'e2e-admin-stale-password-not-a-secret';

    public const string ADMIN_STALE_SECRET = 'YGDVPF7NJEC7MJ54SW3IKGUW7MTJWSEO';

    public const string ADMIN_STORY_EMAIL = 'e2e.admin.story@example.org';

    public const string ADMIN_STORY_PASSWORD = 'e2e-admin-story-password-not-a-secret';

    public const string ADMIN_STORY_SECRET = 'WPASVORC3QYT2LZHKBMKTHAYVK3OI242';

    public const string ADMIN_RECOVER_EMAIL = 'e2e.admin.recover@example.org';

    public const string ADMIN_RECOVER_PASSWORD = 'e2e-admin-recover-password-not-a-secret';

    public const string ADMIN_RECOVER_SECRET = '2FESGCFGXJ7JEMBIVYHTWUXA7NDCLLV3';

    public const string PLAIN_GUARDIAN_EMAIL = 'e2e.admin.guardian@example.org';

    public const string PLAIN_GUARDIAN_PASSWORD = 'e2e-admin-guardian-password-not-a-secret';

    public const string PLAIN_GUARDIAN_SECRET = '4HFMO76JYHG4I4F6ZX5ODMD4HGNABX3F';

    /**
     * A Console ADMINISTRATOR of its own, for the production browser-security journey
     * (e2e/security.spec.ts), which runs against the production-equivalent origin. It has its own
     * account and its own secret so that journey never competes with another for a time step, a code,
     * or an account's state.
     */
    public const string CSP_ADMIN_EMAIL = 'e2e.security.admin@example.org';

    public const string CSP_ADMIN_PASSWORD = 'e2e-security-admin-password-not-a-secret';

    public const string CSP_ADMIN_SECRET = 'PB2XQZ3EMF2GK43UNBSWY3DPFQQHO33S';

    /** A second one, because the two signing-in security journeys run in parallel and each secret's time steps are single-use. */
    public const string CSP_CODES_EMAIL = 'e2e.security.codes@example.org';

    public const string CSP_CODES_PASSWORD = 'e2e-security-codes-password-not-a-secret';

    public const string CSP_CODES_SECRET = 'GIYDCMBSGE3TQMRSGE3DAOBSGIYDCMBS';

    /**
     * A Console administrator of its own for the Relationships journeys (e2e/relationships.spec.ts). Guardian recognition, status
     * changes, default-role decisions and deletion need `guardians.manage` and recent verification, which only an administrator holds
     * today, and each journey records and deletes its own relationships. It has its own account so that no other journey shares
     * its sessions, its recovery codes or its time steps.
     */
    public const string RELATIONSHIPS_ADMIN_EMAIL = 'e2e.relationships.admin@example.org';

    public const string RELATIONSHIPS_ADMIN_PASSWORD = 'e2e-relationships-admin-password-not-a-secret';

    public const string RELATIONSHIPS_ADMIN_SECRET = 'KRUGKIDROVUWG2ZAMJZG653OEBTYPHLI';

    /**
     * The Resource Library's AUDIENCE reader (G10 WP6, e2e/resources-modes.spec.ts): a real Console user who holds the Console and
     * nothing else (Guardian Initiate, ADR 0038 A3), so `resources.view` is not among their capabilities and the library opens as
     * policy C. The role alone makes them eligible for nothing: their Guardian eligibility comes from an ACTIVE Guardian relationship
     * that {@see self::recordAsActiveGuardian()} records through the real Relationships use case, and `forget()` removes it when the
     * fixture Accounts are recreated. Their own account, so no other journey shares their sessions, recovery codes or time steps.
     */
    public const string AUDIENCE_READER_EMAIL = 'e2e.audience.reader@example.org';

    public const string AUDIENCE_READER_PASSWORD = 'e2e-audience-reader-password-not-a-secret';

    public const string AUDIENCE_READER_SECRET = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U';

    /**
     * The G10 personas (ADR 0038, testing strategy; e2e/g10-*.spec.ts). Each is a real Account whose authority comes from a real role
     * assignment, and whose audiences come from real relationships (recorded through the Relationships use cases as the Relationships
     * administrator) and real Membership (granted through Membership's own use case). No journey intercepts `/me` to make one. Each has
     * its own Account, sessions, recovery codes and time steps, and the two that a journey CHANGES (`LIFECYCLE_*`) are used by that
     * journey alone, so no parallel journey ever meets a persona another journey is mutating. All are recreated on every run.
     *
     * [email, display name, password, TOTP secret, recovery-code tag].
     */
    public const array PERSONA_ROLE_ONLY = ['e2e.persona.roleonly@example.org', 'E2E Role Only', 'e2e-persona-roleonly-password-not-a-secret', '4GWYMN5FVYW5LCFPILJJX4D2HQBI5YDO', 'E'];

    public const array PERSONA_INITIATE = ['e2e.persona.initiate@example.org', 'E2E Restricted Guardian', 'e2e-persona-initiate-password-not-a-secret', 'XG6B2HFNAV4IBDDJNRSRAXRZLN6X3O7A', 'F'];

    public const array PERSONA_PARTICIPANT = ['e2e.persona.participant@example.org', 'E2E Console Participant', 'e2e-persona-participant-password-not-a-secret', 'JBNUTG3VY5EOY4GOTAB2VBQOTDTQED4P', 'P1'];

    public const array PERSONA_VOLUNTEER = ['e2e.persona.volunteer@example.org', 'E2E Participant Volunteer', 'e2e-persona-volunteer-password-not-a-secret', 'WDJEIXUSH4L6MLIXVV73UKKBRWN4FTMP', 'P2'];

    public const array PERSONA_MULTI = ['e2e.persona.multi@example.org', 'E2E Guardian Volunteer Member', 'e2e-persona-multi-password-not-a-secret', 'A4HRVYQ23ECHHVSIT322PK5TFS4HJ6PO', 'P3'];

    public const array PERSONA_UNADMITTED = ['e2e.persona.unadmitted@example.org', 'E2E Unadmitted Guardian', 'e2e-persona-unadmitted-password-not-a-secret', 'V2G2DFICPCYV3UWNMSVZHREMIJOH5BC4', 'P4'];

    public const array PERSONA_FORMER = ['e2e.persona.former@example.org', 'E2E Former Guardian', 'e2e-persona-former-password-not-a-secret', '7CHMJ5GPLLNVG3OIFQ26LA7LAWHBA4WS', 'P5'];

    public const array PERSONA_LIFECYCLE_KEPT = ['e2e.persona.lifecycle.kept@example.org', 'E2E Lifecycle Kept', 'e2e-persona-lifecycle-kept-password-not-a-secret', '7TUTEHS2WBO5CVAGPPOP4EBGL2H6GQGY', 'P6'];

    public const array PERSONA_LIFECYCLE_SOLE = ['e2e.persona.lifecycle.sole@example.org', 'E2E Lifecycle Sole', 'e2e-persona-lifecycle-sole-password-not-a-secret', 'CWAJRELWEQAC4ICYHYQG6AKPU7Z2JMY5', 'P7'];

    /**
     * The person the administrator's Guardian Initiate journey records, grants, withdraws and deletes (e2e/relationships.spec.ts): an
     * Account that already holds the Console through the Guardian role, used by that journey alone, so recording them as a Guardian
     * mid-run never changes a persona another journey reads. It never signs in.
     */
    public const string INITIATE_HOLDER_EMAIL = 'e2e.persona.holder@example.org';

    /**
     * The Volunteer manager whose journey deletes a Volunteer (e2e/relationships.spec.ts). Deleting a Volunteer needs a recent proof, so
     * its session is minted STALE and the journey proves in it (the prompt is required, never probed for). That proof rotates the id of
     * THIS Account's session and no other, so no journey that shares a minted session (`plain-guardian`, `relationships-admin`) is
     * ever signed out by it. The Console user role, as the plain Guardian holds, and no relationship.
     */
    public const array VOLUNTEER_MANAGER = ['e2e.persona.volmanager@example.org', 'E2E Volunteer Manager', 'e2e-persona-volmanager-password-not-a-secret', 'T2DMZVACSB32R6KLLIJASUSSQRHTK3AW', 'PA'];

    /**
     * Resource cleanup's own regression probe (e2e/resources-cleanup.spec.ts): a Resources manager whose session is minted STALE, so
     * the cleanup must establish recent verification itself, with this Account's authenticator, before it can delete a Pack.
     */
    public const array CLEANUP_PROBE = ['e2e.cleanup.probe@example.org', 'E2E Cleanup Probe', 'e2e-cleanup-probe-password-not-a-secret', 'HIGRQXHHM2TI3UZQKLY2JRM22RWF65XE', 'P8'];

    /**
     * One Console administrator per browser worker (e2e/verified.ts). Work that needs a RECENT proof (deleting a Pack, recognising a
     * Guardian, removing a relationship) is done in a session of the worker's own, signed in for real with its own authenticator, so
     * no worker ever proves on a session another journey holds (proving rotates the session id, which would sign that journey out)
     * and no two workers ever spend the same authenticator time step. Eight, for up to eight workers.
     *
     * @var list<array{string, string, string, string, string}>
     */
    public const array WORKERS = [
        ['e2e.worker.0@example.org', 'E2E Worker 0', 'e2e-worker-0-password-not-a-secret', 'GCWIAIBLAWGEZ4LAJ27G6J2WZ347EVSG', 'W0'],
        ['e2e.worker.1@example.org', 'E2E Worker 1', 'e2e-worker-1-password-not-a-secret', 'XI2IBMBBLREHJQPRAHCS3O3PL5PWFZA2', 'W1'],
        ['e2e.worker.2@example.org', 'E2E Worker 2', 'e2e-worker-2-password-not-a-secret', 'D4QB7BJ5OJRSIIBVU5NV3S5YQLKLNT3U', 'W2'],
        ['e2e.worker.3@example.org', 'E2E Worker 3', 'e2e-worker-3-password-not-a-secret', 'RR2BYXPVIGKOWSYP2C63UWYLNE5W4EF7', 'W3'],
        ['e2e.worker.4@example.org', 'E2E Worker 4', 'e2e-worker-4-password-not-a-secret', 'ZL5ILBG33EMLMBW3TFY4RZQXIRZYZNK4', 'W4'],
        ['e2e.worker.5@example.org', 'E2E Worker 5', 'e2e-worker-5-password-not-a-secret', 'F44R6U5HK3265QFEY5L77EXHPFSRCJMD', 'W5'],
        ['e2e.worker.6@example.org', 'E2E Worker 6', 'e2e-worker-6-password-not-a-secret', 'E3XFIBY43P4WCBKNV5EYPEWXNZV6RNNS', 'W6'],
        ['e2e.worker.7@example.org', 'E2E Worker 7', 'e2e-worker-7-password-not-a-secret', 'XEX6JB2NWWILCXSHEDWUY3JZSPPQUIWR', 'W7'],
    ];

    /**
     * The demo seeders' operator (RelationshipsDemoSeeder). A Console administrator that is created once and, unlike every other
     * fixture here, NEVER recreated: the demo records who recognised, changed and decided what, and a recreated operator would leave
     * that history naming an Account that no longer exists. It never signs in to a journey.
     */
    public const array DEMO_OPERATOR = ['e2e.demo.operator@example.org', 'E2E Demo Operator', 'e2e-demo-operator-password-not-a-secret', 'YGQYSTW2MCT6IZYV3TWX7QQRWGVCPJTJ', 'P9'];

    /** An ordinary active Account the step-up journey disables. It holds no access. */
    public const string STALE_TARGET_EMAIL = 'e2e.admin.target@example.org';

    public const string STALE_TARGET_PASSWORD = 'e2e-admin-target-password-not-a-secret';

    /**
     * An ordinary active Account that holds no access, and the one an operator sends a password reset email to
     * (e2e/password-recovery.spec.ts). Its OWN fixture: a reset token is per-Account state, so no other journey reads it.
     */
    public const string RESET_TARGET_EMAIL = 'e2e.admin.resettarget@example.org';

    public const string RESET_TARGET_PASSWORD = 'e2e-admin-resettarget-password-not-a-secret';

    /** A Console user with a known second factor, whose second factor the recovery journey resets. */
    public const string RECOVER_TARGET_EMAIL = 'e2e.admin.recovertarget@example.org';

    public const string RECOVER_TARGET_PASSWORD = 'e2e-admin-recovertarget-password-not-a-secret';

    public const string RECOVER_TARGET_SECRET = 'MOUYMASSFUZOSF2XUTWSGM7XYTNSZYWX';

    public function run(
        AccountRepository $accounts,
        PersonRepository $people,
        AccountInvitationRepository $invitations,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
        EnrollTotpFixture $totp,
    ): void {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The e2e fixture account may only be seeded in a local or testing environment.');
        }

        $email = EmailAddress::fromString(self::EMAIL);
        $now = new DateTimeImmutable('now');

        $account = $accounts->findByEmail($email);
        if ($account === null) {
            $person = Person::create(PersonId::generate(), 'E2E Guardian', $now);
            $people->save($person);
            $account = Account::invite(AccountId::generate(), $person->id, $email, $now)->activate($hasher->make(self::PASSWORD), $now);
            $accounts->save($account);
        }

        // A Console user, so the e2e can see capabilities come back through the real gateway. Access
        // decides what that means; this seeder does not know, and must not name, any role.
        // Idempotent: an account seeded earlier gains it on the next run.
        $consoleUser($account->personId);
        // A Console user needs a second factor: enrolled with a known secret (idempotent: reset every run).
        $totp($account->id, self::GUARDIAN_SECRET, self::recoveryCodes('G'));

        $this->resetAdministrationFixtures($accounts, $people, $hasher, $consoleUser, $totp, $now);
        $this->resetCredentialFixtures($accounts, $people, $invitations, $hasher, $consoleUser, $now);
        $this->resetConsoleFixtures($accounts, $people, $invitations, $hasher, $consoleUser, $totp, $now);
    }

    /**
     * The accounts the Console's browser journeys use. What each proves is in e2e/console.spec.ts; what
     * matters here is that none names a role: a Console user is asked of Access, and an account that is
     * NOT one is simply never given anything.
     */
    private function resetConsoleFixtures(
        AccountRepository $accounts,
        PersonRepository $people,
        AccountInvitationRepository $invitations,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
        EnrollTotpFixture $totp,
        DateTimeImmutable $now,
    ): void {
        // Signed in, but with nothing that grants Console access: the "forbidden" experience.
        $this->activeAccount($accounts, $people, $hasher, $now, self::NO_ACCESS_EMAIL, 'E2E No Access', self::NO_ACCESS_PASSWORD);
        $this->activeAccount($accounts, $people, $hasher, $now, self::SESSION_EMAIL, 'E2E Session', self::SESSION_PASSWORD);
        // The Member surface's OWN password-change journey: never read by any other spec, so changing
        // its password (which ends every other session on the Account) races no one.
        $this->activeAccount($accounts, $people, $hasher, $now, self::MEMBER_CHANGE_EMAIL, 'E2E Member Change', self::MEMBER_CHANGE_PASSWORD);
        // The member-foundation journey's OWN Account (Journey B): also never read by any other spec, for the
        // same reason MEMBER_CHANGE isn't — this one's mutations are bigger still (a role grant, then MFA).
        $this->activeAccount($accounts, $people, $hasher, $now, self::PROMOTION_EMAIL, 'E2E Member Promotion', self::PROMOTION_PASSWORD);

        // Two pending invitations for the two ways a person reaches the acceptance page: typing the token,
        // and following a link that carries it in the fragment. Console users once they accept.
        foreach ([
            [self::UI_INVITEE_EMAIL, 'E2E UI Invitee', self::UI_INVITATION_TOKEN],
            [self::UI_LINK_INVITEE_EMAIL, 'E2E UI Link Invitee', self::UI_LINK_INVITATION_TOKEN],
        ] as [$email, $name, $token]) {
            $this->forget($email);
            $person = Person::create(PersonId::generate(), $name, $now);
            $people->save($person);
            $invited = Account::invite(AccountId::generate(), $person->id, EmailAddress::fromString($email), $now);
            $accounts->save($invited);
            $invitations->save(AccountInvitation::issue(
                AccountInvitationId::generate(), $invited->id, InvitationToken::fromPresented($token), $now->modify('+7 days'), $now,
            ));
            $consoleUser($person->id);
        }

        // Console users with a known password AND a known second factor, for the reset, the change, and the
        // multi-factor journeys (e2e/mfa.spec.ts). Each secret is used by one journey only: the platform accepts
        // each authenticator time step once, so sharing a secret between parallel journeys would make them race.
        foreach ([
            [self::UI_RECOVERY_EMAIL, 'E2E UI Recovery', self::UI_RECOVERY_PASSWORD, self::UI_RECOVERY_SECRET, 'R'],
            [self::UI_CHANGE_EMAIL, 'E2E UI Change', self::UI_CHANGE_PASSWORD, self::UI_CHANGE_SECRET, 'C'],
            [self::MFA_LATER_EMAIL, 'E2E MFA Later', self::MFA_LATER_PASSWORD, self::MFA_LATER_SECRET, 'D'],
            [self::MFA_RECOVERY_EMAIL, 'E2E MFA Recovery', self::MFA_RECOVERY_PASSWORD, self::MFA_RECOVERY_SECRET, 'K'],
            [self::MFA_MANAGE_EMAIL, 'E2E MFA Manage', self::MFA_MANAGE_PASSWORD, self::MFA_MANAGE_SECRET, 'M'],
            [self::MFA_PENDING_EMAIL, 'E2E MFA Pending', self::MFA_PENDING_PASSWORD, self::MFA_PENDING_SECRET, 'P'],
            [self::MFA_STALE_EMAIL, 'E2E MFA Stale', self::MFA_STALE_PASSWORD, self::MFA_STALE_SECRET, 'V'],
            [self::CONSOLE_A_EMAIL, 'E2E Console A', self::CONSOLE_A_PASSWORD, self::CONSOLE_A_SECRET, 'A'],
            [self::CONSOLE_B_EMAIL, 'E2E Console B', self::CONSOLE_B_PASSWORD, self::CONSOLE_B_SECRET, 'B'],
        ] as [$email, $name, $password, $secret, $tag]) {
            $account = $this->activeAccount($accounts, $people, $hasher, $now, $email, $name, $password);
            $consoleUser($account->personId);
            $totp($account->id, $secret, self::recoveryCodes($tag));
        }
    }

    /**
     * The people the administration journeys (e2e/administration.spec.ts) act as and on. Everyone is recreated on every run.
     * Administrators come from Access ("a Console administrator"), and this seeder still names no role.
     */
    private function resetAdministrationFixtures(
        AccountRepository $accounts,
        PersonRepository $people,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
        EnrollTotpFixture $totp,
        DateTimeImmutable $now,
    ): void {
        foreach ([
            [self::ADMIN_READ_EMAIL, 'E2E Admin Read', self::ADMIN_READ_PASSWORD, self::ADMIN_READ_SECRET, 'H'],
            [self::ADMIN_STALE_EMAIL, 'E2E Admin Stale', self::ADMIN_STALE_PASSWORD, self::ADMIN_STALE_SECRET, 'J'],
            [self::ADMIN_STORY_EMAIL, 'E2E Admin Story', self::ADMIN_STORY_PASSWORD, self::ADMIN_STORY_SECRET, 'N'],
            [self::ADMIN_RECOVER_EMAIL, 'E2E Admin Recover', self::ADMIN_RECOVER_PASSWORD, self::ADMIN_RECOVER_SECRET, 'Q'],
            [self::CSP_ADMIN_EMAIL, 'E2E Security Admin', self::CSP_ADMIN_PASSWORD, self::CSP_ADMIN_SECRET, 'W'],
            [self::CSP_CODES_EMAIL, 'E2E Security Codes', self::CSP_CODES_PASSWORD, self::CSP_CODES_SECRET, 'X'],
            [self::RELATIONSHIPS_ADMIN_EMAIL, 'E2E Relationships Admin', self::RELATIONSHIPS_ADMIN_PASSWORD, self::RELATIONSHIPS_ADMIN_SECRET, 'Y'],
        ] as [$email, $name, $password, $secret, $tag]) {
            $account = $this->activeAccount($accounts, $people, $hasher, $now, $email, $name, $password);
            $consoleUser->administrator($account->personId);
            $totp($account->id, $secret, self::recoveryCodes($tag));
        }

        foreach ([
            [self::PLAIN_GUARDIAN_EMAIL, 'E2E Plain Guardian', self::PLAIN_GUARDIAN_PASSWORD, self::PLAIN_GUARDIAN_SECRET, 'S'],
            [self::RECOVER_TARGET_EMAIL, 'E2E Recover Target', self::RECOVER_TARGET_PASSWORD, self::RECOVER_TARGET_SECRET, 'T'],
        ] as [$email, $name, $password, $secret, $tag]) {
            $account = $this->activeAccount($accounts, $people, $hasher, $now, $email, $name, $password);
            $consoleUser($account->personId);
            $totp($account->id, $secret, self::recoveryCodes($tag));
        }

        // The restricted Console user: admitted, with no Resources capability, so the library opens as the audience reader.
        $reader = $this->activeAccount($accounts, $people, $hasher, $now, self::AUDIENCE_READER_EMAIL, 'E2E Audience Reader', self::AUDIENCE_READER_PASSWORD);
        $consoleUser->initiate($reader->personId);
        $totp($reader->id, self::AUDIENCE_READER_SECRET, self::recoveryCodes('Z'));
        $this->recordAsActiveGuardian($reader->personId);

        $this->activeAccount($accounts, $people, $hasher, $now, self::STALE_TARGET_EMAIL, 'E2E Stale Target', self::STALE_TARGET_PASSWORD);
        $this->activeAccount($accounts, $people, $hasher, $now, self::RESET_TARGET_EMAIL, 'E2E Reset Target', self::RESET_TARGET_PASSWORD);

        foreach (self::WORKERS as $worker) {
            $consoleUser->administrator($this->persona($accounts, $people, $hasher, $totp, $now, $worker));
        }
        $this->demoOperator($accounts, $people, $hasher, $consoleUser, $totp, $now);
        $this->resetPersonas($accounts, $people, $hasher, $consoleUser, $totp, $now);
    }

    /**
     * The G10 personas (see {@see self::PERSONA_ROLE_ONLY}). Roles come from Access's fixture; everything a persona IS (a Guardian, a
     * Volunteer, a Member) comes from the real use cases, so each relationship has its attributed history, each relationship-managed
     * grant is made (and withdrawn) by Access through the relationship, and the Member's grant is Membership's own.
     */
    private function resetPersonas(
        AccountRepository $accounts,
        PersonRepository $people,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
        EnrollTotpFixture $totp,
        DateTimeImmutable $now,
    ): void {
        // A. The Guardian ROLE and no Guardian relationship: its capabilities, and no Guardian audience.
        $consoleUser($this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_ROLE_ONLY));

        // B. The restricted Guardian: an Active Guardian whose Console admission is the relationship-managed Guardian Initiate grant.
        $this->establish($this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_INITIATE), 'guardian', 'active', 'grant');

        // C. A Console Participant: admitted by an independent role, with no relationship and no Membership.
        $consoleUser->participant($this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_PARTICIPANT));

        // D. A Console Participant who is an Active Volunteer.
        $volunteer = $this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_VOLUNTEER);
        $consoleUser->participant($volunteer);
        $this->establish($volunteer, 'volunteer', 'active');

        // E. An Active Guardian (admitted through the relationship-managed grant), an Active Volunteer and an Active Member.
        $multi = $this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_MULTI);
        $this->establish($multi, 'guardian', 'active', 'grant');
        $this->establish($multi, 'volunteer', 'active');
        ($this->container->make(GrantMembershipAccess::class))(
            $this->relationshipsOperator(), $multi, $now->modify('-1 day'), null, MembershipGrantSource::Operator, 'e2e persona',
        );

        // An Active Guardian whose operator declined Guardian Initiate access, with no role: a Guardian, and not a Console user.
        $this->establish($this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_UNADMITTED), 'guardian', 'active', 'decline');

        // A Console Participant who WAS a Guardian (granted, then inactivated, which withdrew the grant) and is a Pending Volunteer.
        $former = $this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_FORMER);
        $consoleUser->participant($former);
        $this->changeStatus($former, 'guardian', $this->establish($former, 'guardian', 'active', 'grant'), 'inactive');
        $this->establish($former, 'volunteer', 'pending');

        // The two personas the lifecycle journey changes: one whose admission is independent of the relationship, one whose is not.
        $kept = $this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_LIFECYCLE_KEPT);
        $consoleUser->participant($kept);
        $this->establish($kept, 'guardian', 'active', 'grant');
        $this->establish($kept, 'volunteer', 'active');
        $this->establish($this->persona($accounts, $people, $hasher, $totp, $now, self::PERSONA_LIFECYCLE_SOLE), 'guardian', 'active', 'grant');

        // The Volunteer manager whose deletion journey proves in a stale session of its own: the Console user role, no relationship.
        $consoleUser($this->persona($accounts, $people, $hasher, $totp, $now, self::VOLUNTEER_MANAGER));

        // The cleanup probe: a Resources manager (the Console user role), with no relationship.
        $consoleUser($this->persona($accounts, $people, $hasher, $totp, $now, self::CLEANUP_PROBE));

        $consoleUser($this->activeAccount($accounts, $people, $hasher, $now, self::INITIATE_HOLDER_EMAIL, 'E2E Initiate Holder', 'e2e-persona-holder-password-not-a-secret')->personId);
    }

    /**
     * The demo operator: found by its email and kept (only created when missing), made a Console administrator if it is not one, and
     * given its known second factor. See {@see self::DEMO_OPERATOR}.
     */
    private function demoOperator(
        AccountRepository $accounts,
        PersonRepository $people,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
        EnrollTotpFixture $totp,
        DateTimeImmutable $now,
    ): void {
        [$email, $name, $password, $secret, $tag] = self::DEMO_OPERATOR;
        $account = $accounts->findByEmail(EmailAddress::fromString($email));
        if ($account === null) {
            $person = Person::create(PersonId::generate(), $name, $now);
            $people->save($person);
            $account = Account::invite(AccountId::generate(), $person->id, EmailAddress::fromString($email), $now)->activate($hasher->make($password), $now);
            $accounts->save($account);
        }
        $consoleUser->administrator($account->personId);
        $totp($account->id, $secret, self::recoveryCodes($tag));
    }

    /**
     * An active Account with a known password and second factor, created afresh.
     *
     * @param  array{string, string, string, string, string}  $spec  [email, display name, password, TOTP secret, recovery-code tag]
     */
    private function persona(AccountRepository $accounts, PersonRepository $people, Hasher $hasher, EnrollTotpFixture $totp, DateTimeImmutable $now, array $spec): PersonId
    {
        [$email, $name, $password, $secret, $tag] = $spec;
        $account = $this->activeAccount($accounts, $people, $hasher, $now, $email, $name, $password);
        $totp($account->id, $secret, self::recoveryCodes($tag));

        return $account->personId;
    }

    /** Records a relationship the way an operator does: through the Relationships use case, as the Relationships administrator. */
    private function establish(PersonId $person, string $type, string $status, ?string $defaultRole = null): RelationshipManagementView
    {
        return ($this->container->make(EstablishRelationship::class))(
            $this->relationshipsOperator(), $this->relationshipType($type), $person, true, $status, [], $defaultRole !== null, $defaultRole,
        );
    }

    /** Changes a relationship's status through the use case (which withdraws a relationship-managed grant on leaving Active). */
    private function changeStatus(PersonId $person, string $type, RelationshipManagementView $current, string $status): void
    {
        ($this->container->make(ChangeRelationshipStatus::class))(
            $this->relationshipsOperator(), $this->relationshipType($type), $person, $current->id, $current->revision, $status, false,
        );
    }

    private function relationshipsOperator(): Actor
    {
        $administrator = DB::table('accounts')->where('email_canonical', self::RELATIONSHIPS_ADMIN_EMAIL)->value('id');
        $actor = is_string($administrator) ? $this->container->make(ResolveActor::class)(AccountId::fromString($administrator)) : null;

        return $actor ?? throw new RuntimeException('The Relationships administrator is needed to record the personas\' relationships.');
    }

    private function relationshipType(string $key): RelationshipType
    {
        return $this->container->make(RelationshipCatalog::class)->type($key)
            ?? throw new RuntimeException("The relationship type {$key} is needed to record the personas' relationships.");
    }

    /**
     * Records the audience reader as an ACTIVE Guardian, the way an operator does: through the Relationships use case, as the
     * Relationships administrator, with the default role declined (so no relationship-managed grant is made and the Account still
     * holds the Console and nothing else). Eligibility for the `guardian` audience comes from this relationship and from nothing the
     * Account holds (ADR 0038, R10, R12): they are Console-admitted with no Resources capability and eligible to read Guardian content.
     */
    private function recordAsActiveGuardian(PersonId $person): void
    {
        $this->establish($person, 'guardian', 'active', 'decline');
    }

    /** An active Account with a known password, created afresh (a previous run's copy is removed first). */
    private function activeAccount(
        AccountRepository $accounts,
        PersonRepository $people,
        Hasher $hasher,
        DateTimeImmutable $now,
        string $email,
        string $displayName,
        string $password,
    ): Account {
        $this->forget($email);
        $person = Person::create(PersonId::generate(), $displayName, $now);
        $people->save($person);
        $account = Account::invite(AccountId::generate(), $person->id, EmailAddress::fromString($email), $now)
            ->activate($hasher->make($password), $now);
        $accounts->save($account);

        return $account;
    }

    private function resetCredentialFixtures(
        AccountRepository $accounts,
        PersonRepository $people,
        AccountInvitationRepository $invitations,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
        DateTimeImmutable $now,
    ): void {
        // An invited Account with a pending invitation whose token is known to the test.
        $this->forget(self::INVITEE_EMAIL);
        $invitee = Person::create(PersonId::generate(), 'E2E Invitee', $now);
        $people->save($invitee);
        $invited = Account::invite(AccountId::generate(), $invitee->id, EmailAddress::fromString(self::INVITEE_EMAIL), $now);
        $accounts->save($invited);
        $invitations->save(AccountInvitation::issue(
            AccountInvitationId::generate(), $invited->id, InvitationToken::fromPresented(self::INVITATION_TOKEN), $now->modify('+7 days'), $now,
        ));
        // Deliberately NOT a Console user: this API-level journey measures the credential lifecycle, and a
        // Console user's sign-in is two steps. (The Console's own invitation journey uses the UI invitees.)

        // An active Account with a known password, for the reset and the change.
        $this->forget(self::RECOVERY_EMAIL);
        $person = Person::create(PersonId::generate(), 'E2E Recovery', $now);
        $people->save($person);
        $accounts->save(
            Account::invite(AccountId::generate(), $person->id, EmailAddress::fromString(self::RECOVERY_EMAIL), $now)
                ->activate($hasher->make(self::RECOVERY_PASSWORD), $now),
        );
    }

    /**
     * The ten recovery codes of a fixture: `E2E<tag>-RC00-0000-000<n>` for a one-character tag, and `E2E<a>-<b>C00-0000-000<n>` for
     * a two-character tag `<a><b>` (the single-character tags ran out; `<b>` is never `R`, so the two forms never meet). Every character
     * is in the recovery-code alphabet (no I, L, O or U), so they are valid codes. e2e/support.ts builds the same list.
     *
     * @return list<string>
     */
    public static function recoveryCodes(string $tag): array
    {
        [$first, $second] = strlen($tag) === 2 ? [$tag[0], $tag[1]] : [$tag, 'R'];

        return array_map(static fn (int $n): string => sprintf('E2E%s-%sC00-0000-000%d', $first, $second, $n), range(0, 9));
    }

    /**
     * Removes a previous run's fixture (rows only this seeder created, children first) so a one-time
     * step can be taken again. Development only, like the whole seeder.
     */
    private function forget(string $email): void
    {
        $account = DB::table('accounts')->where('email_canonical', $email)->first();
        if ($account !== null) {
            DB::table('sessions')->where('user_id', $account->id)->delete();
            DB::table('account_recovery_codes')->where('account_id', $account->id)->delete();
            DB::table('account_totp_factors')->where('account_id', $account->id)->delete();
            DB::table('account_invitations')->where('account_id', $account->id)->delete();
            DB::table('role_assignments')->where('person_id', $account->person_id)->delete();
            DB::table('sourced_role_grants')->where('person_id', $account->person_id)->delete();
            // Whatever a previous run recorded about this Person in a relationship, children first (they restrict the Person's deletion).
            foreach (DB::table('person_relationships')->where('person_id', $account->person_id)->pluck('id') as $relationship) {
                foreach (['person_relationship_role_provisions', 'person_relationship_field_values', 'person_relationship_status_changes'] as $table) {
                    DB::table($table)->where('relationship_id', $relationship)->delete();
                }
            }
            DB::table('person_relationships')->where('person_id', $account->person_id)->delete();
            DB::table('membership_grants')->where('person_id', $account->person_id)->delete();
            // What a journey recorded about this Person in CRM (the lifecycle journey's scoped phone correction adds a contact method
            // to a persona), which restricts the Person's deletion: without these the NEXT run's seeding stopped here and left the
            // Person behind with no Account.
            foreach (['contact_interactions', 'contact_methods', 'contact_profiles', 'contact_tag_assignments'] as $table) {
                DB::table($table)->where('person_id', $account->person_id)->delete();
            }
            DB::table('accounts')->where('id', $account->id)->delete();
            DB::table('people')->where('id', $account->person_id)->delete();
        }
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }
}
