<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\ConsoleUserFixture;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Seeder;
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

    public function run(AccountRepository $accounts, PersonRepository $people, Hasher $hasher, ConsoleUserFixture $consoleUser): void
    {
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
    }
}
