<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

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
}
