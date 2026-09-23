<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Identity\Application\PersonExists;
use App\Modules\Membership\Domain\InvalidMembershipTerm;
use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Grants membership access to an EXISTING Person, on behalf of an authenticated Actor.
 *
 * - **Authorization is not optional.** The Actor must hold `membership.records.manage`,
 *   decided by the real Authorizer against current state, checked before anything else.
 * - **No Account is required.** Membership identifies its subject by PersonId; whether that
 *   Person has ever signed in is irrelevant here (ADR 0028).
 * - **Provenance is derived from the Actor, never accepted as an independent input.** A future
 *   HTTP caller cannot choose `granted_by_account_id` on the request: it is always the acting
 *   Account.
 * - **Performs exactly one write**, so it opens no transaction of its own: the insert is
 *   already atomic, and there is nothing else in this operation to coordinate with it.
 */
final readonly class GrantMembershipAccess
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private MembershipGrantRepository $grants,
    ) {}

    /**
     * @throws AccessDenied the Actor may not manage membership records
     * @throws UnknownPerson there is no such Person
     * @throws InvalidMembershipTerm `endsAt` is not strictly after `startsAt`
     * @throws InvalidArgumentException `sourceReference` is too long
     */
    public function __invoke(
        Actor $actor,
        PersonId $person,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        MembershipGrantSource $source,
        ?string $sourceReference = null,
    ): MembershipGrant {
        ($this->authorize)($actor, Capability::ManageMembershipRecords);

        if (! ($this->personExists)($person)) {
            throw new UnknownPerson;
        }

        $grant = MembershipGrant::grant(
            MembershipGrantId::generate(), $person, $startsAt, $endsAt, $source, $sourceReference,
            $actor->accountId, DateTimeImmutable::createFromInterface(now()),
        );

        $this->grants->add($grant);

        return $grant;
    }
}
