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
