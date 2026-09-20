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
 * asks for "a Console user", and Access decides what that means. It bypasses authorization and
 * audit on purpose, and so it refuses to run anywhere but the local and testing environments.
 * It is not the administrator bootstrap and grants no administrator authority.
 */
final readonly class ConsoleUserFixture
{
    public function __construct(
        private RoleAssignmentRepository $assignments,
        private Config $config,
    ) {}

    public function __invoke(PersonId $person): void
    {
        if (! in_array($this->config->string('app.env'), ['local', 'testing'], true)) {
            throw new LogicException('The Console user fixture may only run in a local or testing environment.');
        }

        foreach ($this->assignments->forPerson($person) as $existing) {
            if ($existing->roleKey === Role::Guardian->value) {
                return;
            }
        }

        $this->assignments->add(RoleAssignment::grant($person, Role::Guardian->value, null, DateTimeImmutable::createFromInterface(now())));
    }
}
