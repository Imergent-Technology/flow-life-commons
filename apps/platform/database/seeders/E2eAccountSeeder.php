<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
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
 * that is a separate, later, server-access-rooted command (ADR 0020). Nor is granting the
 * role: no grant use case exists yet, so this writes the assignment through the port.
 */
final class E2eAccountSeeder extends Seeder
{
    public const string EMAIL = 'e2e.guardian@example.org';

    public const string PASSWORD = 'e2e-fixture-password-not-a-secret';

    public function run(AccountRepository $accounts, PersonRepository $people, Hasher $hasher, RoleAssignmentRepository $roles): void
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

        // The Console's ordinary role, so the e2e can see capabilities come back through the
        // real gateway. Idempotent: an account seeded before roles existed gains it on the next run.
        $held = array_map(fn (RoleAssignment $a): string => $a->roleKey, $roles->forPerson($account->personId));
        if (! in_array(Role::Guardian->value, $held, true)) {
            $roles->add(RoleAssignment::grant($account->personId, Role::Guardian->value, null, $now));
        }
    }
}
