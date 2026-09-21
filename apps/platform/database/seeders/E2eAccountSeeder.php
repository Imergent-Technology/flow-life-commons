<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\ConsoleUserFixture;
use App\Modules\Identity\Application\EnrollTotpFixture;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
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

    /** An ordinary active Account the step-up journey disables. It holds no access. */
    public const string STALE_TARGET_EMAIL = 'e2e.admin.target@example.org';

    public const string STALE_TARGET_PASSWORD = 'e2e-admin-target-password-not-a-secret';

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

        $this->activeAccount($accounts, $people, $hasher, $now, self::STALE_TARGET_EMAIL, 'E2E Stale Target', self::STALE_TARGET_PASSWORD);
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
     * The ten recovery codes of a fixture, `E2E<tag>-RC00-0000-000<n>`. Every character is in the recovery-code
     * alphabet (no I, L, O or U), so they are valid codes. e2e/support.ts builds the same list.
     *
     * @return list<string>
     */
    public static function recoveryCodes(string $tag): array
    {
        return array_map(static fn (int $n): string => sprintf('E2E%s-RC00-0000-000%d', $tag, $n), range(0, 9));
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
            DB::table('accounts')->where('id', $account->id)->delete();
            DB::table('people')->where('id', $account->person_id)->delete();
        }
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }
}
