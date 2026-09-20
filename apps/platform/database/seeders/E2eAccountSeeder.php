<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\ConsoleUserFixture;
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

    public function run(
        AccountRepository $accounts,
        PersonRepository $people,
        AccountInvitationRepository $invitations,
        Hasher $hasher,
        ConsoleUserFixture $consoleUser,
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

        $this->resetCredentialFixtures($accounts, $people, $invitations, $hasher, $consoleUser, $now);
        $this->resetConsoleFixtures($accounts, $people, $invitations, $hasher, $consoleUser, $now);
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
        DateTimeImmutable $now,
    ): void {
        // Signed in, but with nothing that grants Console access: the "forbidden" experience.
        $this->activeAccount($accounts, $people, $hasher, $now, self::NO_ACCESS_EMAIL, 'E2E No Access', self::NO_ACCESS_PASSWORD);

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

        // Console users with a known password, for the reset and the change.
        foreach ([
            [self::UI_RECOVERY_EMAIL, 'E2E UI Recovery', self::UI_RECOVERY_PASSWORD],
            [self::UI_CHANGE_EMAIL, 'E2E UI Change', self::UI_CHANGE_PASSWORD],
        ] as [$email, $name, $password]) {
            $account = $this->activeAccount($accounts, $people, $hasher, $now, $email, $name, $password);
            $consoleUser($account->personId);
        }
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
        // A Console user once it has accepted, so the journey can see capabilities come back at login.
        $consoleUser($invitee->id);

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
     * Removes a previous run's fixture (rows only this seeder created, children first) so a one-time
     * step can be taken again. Development only, like the whole seeder.
     */
    private function forget(string $email): void
    {
        $account = DB::table('accounts')->where('email_canonical', $email)->first();
        if ($account !== null) {
            DB::table('sessions')->where('user_id', $account->id)->delete();
            DB::table('account_invitations')->where('account_id', $account->id)->delete();
            DB::table('role_assignments')->where('person_id', $account->person_id)->delete();
            DB::table('accounts')->where('id', $account->id)->delete();
            DB::table('people')->where('id', $account->person_id)->delete();
        }
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }
}
