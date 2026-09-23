<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Identity\Application\RegisterPerson;
use App\Modules\Membership\Domain\InvalidMembershipTerm;
use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Registers a new Person and grants them membership access, atomically: the Membership
 * Foundation's first orchestration across module boundaries (ADR 0028).
 *
 * - **Authorization happens BEFORE any side effect**, including before Identity is asked to
 *   create anything: a caller without `membership.records.manage` never causes a Person to
 *   exist.
 * - **One outer transaction.** `Identity\Application\RegisterPerson` is called inside it, the
 *   same nested-transaction/savepoint pattern `Access\Application\BootstrapAdministrator`
 *   already uses around `InviteAccount`: if creating the grant fails for any reason, the new
 *   Person is rolled back with it, so no orphan Person is left behind.
 * - **Membership does not persist Person itself.** It calls Identity's own use case and holds
 *   nothing but the resulting PersonId and display name afterward; Identity remains the only
 *   module that creates People (ADR 0015).
 */
final readonly class RegisterPersonWithMembershipAccess
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RegisterPerson $register,
        private MembershipGrantRepository $grants,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied the Actor may not manage membership records
     * @throws InvalidArgumentException the display name is invalid (Identity's own rule), or sourceReference is too long
     * @throws InvalidMembershipTerm `endsAt` is not strictly after `startsAt`
     */
    public function __invoke(
        Actor $actor,
        string $displayName,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        MembershipGrantSource $source,
        ?string $sourceReference = null,
    ): MembershipRegistration {
        ($this->authorize)($actor, Capability::ManageMembershipRecords);

        return $this->database->transaction(function () use ($actor, $displayName, $startsAt, $endsAt, $source, $sourceReference): MembershipRegistration {
            $person = ($this->register)($displayName);

            $grant = MembershipGrant::grant(
                MembershipGrantId::generate(), $person->id, $startsAt, $endsAt, $source, $sourceReference,
                $actor->accountId, DateTimeImmutable::createFromInterface(now()),
            );
            $this->grants->add($grant);

            return new MembershipRegistration($person->id, $person->displayName, $grant);
        });
    }
}
