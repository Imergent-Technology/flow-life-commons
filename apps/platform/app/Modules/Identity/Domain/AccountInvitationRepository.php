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
}
