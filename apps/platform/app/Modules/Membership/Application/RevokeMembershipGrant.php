<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * Revokes a membership grant, on behalf of an authenticated Actor. One-way: a revoked grant
 * can never become unrevoked (ADR 0028), and revoking an already-revoked grant is refused
 * rather than treated as a no-op, so a caller always knows whether ITS call was the one that
 * changed anything.
 *
 * - **Authorization is not optional.** The Actor must hold `membership.records.manage`.
 * - **`revoked_by_account_id` is derived from the Actor**, never an independent input.
 * - **The mutation itself is race-safe at the database**, not merely checked in PHP first:
 *   `MembershipGrantRepository::revoke()` is one conditional `UPDATE ...  WHERE revoked_at IS
 *   NULL` (ADR 0025's precedent). This use case only classifies a zero-row result afterward,
 *   distinguishing "no such grant" from "already revoked" — it never retries and never
 *   overwrites the revocation that got there first.
 */
final readonly class RevokeMembershipGrant
{
    public function __construct(
        private AuthorizeAction $authorize,
        private MembershipGrantRepository $grants,
    ) {}

    /**
     * @throws AccessDenied the Actor may not manage membership records
     * @throws GrantNotFound no grant with this id exists
     * @throws GrantAlreadyRevoked the grant exists but a revocation already committed
     */
    public function __invoke(Actor $actor, MembershipGrantId $id): void
    {
        ($this->authorize)($actor, Capability::ManageMembershipRecords);

        $revoked = $this->grants->revoke($id, $actor->accountId, DateTimeImmutable::createFromInterface(now()));
        if ($revoked) {
            return;
        }

        if ($this->grants->find($id) === null) {
            throw new GrantNotFound;
        }

        throw new GrantAlreadyRevoked;
    }
}
