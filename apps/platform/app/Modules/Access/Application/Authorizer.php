<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\Actor;

/**
 * Decides whether an Actor may do something. Authentication answered WHO; this answers
 * WHAT that person may do. Whether a business decision has been approved is Workflow's
 * question (ADR 0009) and is not answered here.
 *
 * The decision is always evaluated against CURRENT persisted state:
 *
 * 1. **The Account must still be able to authenticate.** The Actor is re-resolved through
 *    Identity's Application layer. A disabled or otherwise ineligible Account has no
 *    authority, however recently it authenticated and whatever a stale Actor says.
 * 2. **The Person is the one the Account belongs to now**, as resolved, not whatever the
 *    Actor object claims. An Actor that disagrees with the resolved identity is denied.
 * 3. **The Person's assignments are read fresh** on every call, so revoking a role takes
 *    effect on the very next decision, with no need to recreate the Actor or the session.
 * 4. **Each stored role key goes through the code-owned catalog.** A key the catalog does
 *    not know (corrupt, or a role since removed) grants nothing: it fails closed, and
 *    never affects the person's other, valid roles.
 * 5. **Default deny.** Nothing is granted unless a role in the catalog grants it.
 *
 * Nothing is cached, and nothing is ever taken from the client: capabilities in a request
 * or a session are not read, because this is the only place they are decided.
 */
final readonly class Authorizer
{
    public function __construct(
        private ResolveActor $resolveActor,
        private RoleAssignmentRepository $assignments,
    ) {}

    public function allows(Actor $actor, Capability $capability): bool
    {
        return in_array($capability, $this->capabilitiesOf($actor), true);
    }

    /**
     * Everything the Actor may currently do, sorted by identifier so the order is stable.
     * `allows` is defined in terms of this, so the two can never disagree.
     *
     * @return list<Capability>
     */
    public function capabilitiesOf(Actor $actor): array
    {
        $current = ($this->resolveActor)($actor->accountId);
        if ($current === null || ! $current->personId->equals($actor->personId)) {
            return [];
        }

        $held = [];
        foreach ($this->assignments->forPerson($current->personId) as $assignment) {
            $role = Role::tryFrom($assignment->roleKey);
            if ($role === null) {
                continue; // unknown role key: grants nothing
            }
            foreach ($role->capabilities() as $capability) {
                $held[$capability->value] = $capability;
            }
        }

        ksort($held, SORT_STRING);

        return array_values($held);
    }
}
