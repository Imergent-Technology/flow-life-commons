<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\SourcedGrantAlreadyRecorded;
use App\Modules\Access\Domain\SourcedRoleGrant;
use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Grants one provisionable role because of one source instance (ADR 0038, K4, K5).
 *
 * Authorization is not optional. The Actor must hold `access.roles.assign`, decided here,
 * before any write. There is no parameter that skips it.
 *
 * Idempotent for the same Person: an existing row is left as it was and records nothing.
 * The same source and role already bound to a different Person is refused, and the row
 * is not moved. Other grants the Person holds are ignored.
 *
 * The audit event is written in the caller's transaction. Context is the role and the
 * source identity only: no relationship field, name or contact.
 */
final readonly class GrantSourcedRole
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private SourcedRoleGrantRepository $grants,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws SourcedGrantBoundToAnotherPerson
     */
    public function __invoke(Actor $actor, PersonId $person, ProvisionableRole $role, RoleGrantSource $source): RoleMutation
    {
        ($this->authorize)($actor, Capability::AssignRoles);

        if (! ($this->personExists)($person)) {
            throw new UnknownPerson;
        }

        $roleKey = $role->role()->value;

        return $this->database->transaction(function () use ($actor, $person, $roleKey, $source): RoleMutation {
            $this->grants->lockSource($source->type->value, $source->id);

            try {
                // Insert first, inside a savepoint. A lock of a missing row would gap-lock the
                // unique index on MariaDB and make an unrelated source wait. The unique key is
                // what serializes two grants of the same source; the lock is taken only once a
                // row exists, to see which Person it belongs to.
                $this->database->transaction(function () use ($actor, $person, $roleKey, $source): void {
                    $this->grants->add(SourcedRoleGrant::grant(
                        $person,
                        $roleKey,
                        $source->type->value,
                        $source->id,
                        $actor->accountId,
                        DateTimeImmutable::createFromInterface(now()),
                    ));
                    $this->recordGranted($actor, $person, $roleKey, $source);
                }, 3);

                return RoleMutation::Changed;
            } catch (SourcedGrantAlreadyRecorded) {
                $existing = $this->grants->lockBySourceAndRole($source->type->value, $source->id, $roleKey);
                if ($existing !== null && $existing->personId->equals($person)) {
                    return RoleMutation::Unchanged;
                }

                throw new SourcedGrantBoundToAnotherPerson;
            }
        }, 3);
    }

    private function recordGranted(Actor $actor, PersonId $person, string $roleKey, RoleGrantSource $source): void
    {
        ($this->record)(
            AccessEvent::RoleGranted->value,
            SecurityEventOutcome::Success,
            $actor,
            $person,
            null,
            null,
            null,
            ['role' => $roleKey, 'source_type' => $source->type->value, 'source_id' => $source->id],
        );
    }
}
