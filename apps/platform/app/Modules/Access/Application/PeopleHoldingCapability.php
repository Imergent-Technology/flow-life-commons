<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Shared\Domain\PersonId;

/**
 * People who currently hold a capability through an independent assignment or a sourced
 * grant (ADR 0038, M4). It names no role to its caller. An unknown stored key grants
 * nothing, so it is not a holder. Read-only, and it has no route.
 */
final readonly class PeopleHoldingCapability
{
    public function __construct(
        private RoleAssignmentRepository $assignments,
        private SourcedRoleGrantRepository $grants,
    ) {}

    /**
     * @return list<PersonId>
     */
    public function __invoke(Capability $capability): array
    {
        $keys = [];
        foreach (Role::cases() as $role) {
            if ($role->grants($capability)) {
                $keys[] = $role->value;
            }
        }

        $people = [];
        $seen = [];
        $add = static function (PersonId $person) use (&$people, &$seen): void {
            if (isset($seen[$person->value])) {
                return;
            }
            $seen[$person->value] = true;
            $people[] = $person;
        };

        foreach ($keys as $key) {
            foreach ($this->assignments->holdersOf($key) as $person) {
                $add($person);
            }
        }
        foreach ($this->grants->personIdsHoldingRoles($keys) as $person) {
            $add($person);
        }

        usort($people, static fn (PersonId $left, PersonId $right): int => $left->value <=> $right->value);

        return $people;
    }
}
