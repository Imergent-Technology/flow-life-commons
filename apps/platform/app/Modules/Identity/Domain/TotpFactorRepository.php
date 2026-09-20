<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;

interface TotpFactorRepository
{
    /** Insert or update. */
    public function save(TotpFactor $factor): void;

    public function findByAccount(AccountId $account): ?TotpFactor;
}
