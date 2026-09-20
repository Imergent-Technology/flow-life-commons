<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;

interface TotpFactorRepository
{
    /** Insert or update. */
    public function save(TotpFactor $factor): void;

    public function findByAccount(AccountId $account): ?TotpFactor;

    /**
     * Removes the Account's authenticator enrolment: the active secret, and any pending one. Returns whether
     * there was one. It is the only way a factor stops existing (a person never switches one off themselves).
     */
    public function delete(AccountId $account): bool;
}
