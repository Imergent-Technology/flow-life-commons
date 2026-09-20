<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;

interface AccountInvitationRepository
{
    /** Insert or update. */
    public function save(AccountInvitation $invitation): void;

    public function find(AccountInvitationId $id): ?AccountInvitation;

    /** Looks up by the hash of the presented token; the secret itself is never stored. */
    public function findByToken(InvitationToken $token): ?AccountInvitation;

    /**
     * As findByToken, but takes a row lock (SELECT ... FOR UPDATE) held until the caller's transaction
     * ends, and reads the latest COMMITTED state. This is what makes acceptance one-time: a second
     * acceptance of the same token waits here, then sees the first one's `accepted_at`. Inside a
     * transaction only.
     */
    public function findByTokenForUpdate(InvitationToken $token): ?AccountInvitation;

    /**
     * Takes row locks (SELECT ... FOR UPDATE, ordered by id, held until the caller's transaction ends) on every
     * invitation of the Account. Acceptance locks the invitation FIRST and the Account second, so anything that
     * locks both takes them in that same order (reissue does): the two then queue behind one another and can
     * never deadlock. Inside a transaction only.
     */
    public function lockAllFor(AccountId $account): void;

    /**
     * Deletes the Account's invitations that were never accepted, so none of them can be used again (revocation
     * is deleting the row). Returns how many. Call after lockAllFor, inside the same transaction.
     */
    public function deleteUnacceptedFor(AccountId $account): int;
}
