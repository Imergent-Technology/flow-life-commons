<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;

/**
 * DEVELOPMENT AND TESTING ONLY. Gives a person the Console's ordinary role so the browser
 * end-to-end tests have a signed-in user with a capability to observe.
 *
 * It exists so that code outside Access (the development seeder) never has to name a role: it
 * asks for "a Console user" (or "a Console administrator", for the operator-administration journeys), and
 * Access decides what that means. It bypasses authorization and audit on purpose, and so it refuses to run
 * anywhere but the local and testing environments. It is not the administrator bootstrap: nothing in
 * production can reach it.
 */
final readonly class ConsoleUserFixture
{
    public function __construct(
        private RoleAssignmentRepository $assignments,
        private Config $config,
    ) {}

    public function __invoke(PersonId $person): void
    {
        $this->give($person, Role::Guardian);
    }

    /** A Console user who may also administer operators: what the administration journeys sign in as. */
    public function administrator(PersonId $person): void
    {
        $this->give($person, Role::PlatformAdministrator);
    }

    private function give(PersonId $person, Role $role): void
    {
        if (! in_array($this->config->string('app.env'), ['local', 'testing'], true)) {
            throw new LogicException('The Console user fixture may only run in a local or testing environment.');
        }

        foreach ($this->assignments->forPerson($person) as $existing) {
            if ($existing->roleKey === $role->value) {
                return;
            }
        }

        $this->assignments->add(RoleAssignment::grant($person, $role->value, null, DateTimeImmutable::createFromInterface(now())));
    }
}
