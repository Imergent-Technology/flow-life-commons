<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * Removes every grant sourced from one instance (ADR 0038, K4, K6).
 *
 * It authorizes nothing. Withdrawal only removes authority, and the lifecycle that calls
 * it has already been authorized. It must not be blocked by a missing `access.roles.assign`.
 *
 * Independent `role_assignments` are never touched, and neither are grants of any other
 * source. No rows is a quiet no-op. Each deleted row records `role.revoked` with the same
 * source context a grant recorded, and nothing about the relationship's content.
 */
final readonly class WithdrawSourcedRoles
{
    public function __construct(
        private SourcedRoleGrantRepository $grants,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    public function __invoke(Actor $actor, RoleGrantSource $source): RoleMutation
    {
        return $this->database->transaction(function () use ($actor, $source): RoleMutation {
            $existing = $this->grants->lockForSource($source->type->value, $source->id);
            if ($existing === []) {
                return RoleMutation::Unchanged;
            }

            foreach ($existing as $grant) {
                $this->grants->delete($grant->id);
                ($this->record)(
                    AccessEvent::RoleRevoked->value,
                    SecurityEventOutcome::Success,
                    $actor,
                    $grant->personId,
                    null,
                    null,
                    null,
                    ['role' => $grant->roleKey, 'source_type' => $source->type->value, 'source_id' => $source->id],
                );
            }

            return RoleMutation::Changed;
        }, 3);
    }
}
