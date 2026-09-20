<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\AccountId;

final readonly class MfaStatuses
{
    public function __construct(
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
    ) {}

    public function for(AccountId $account): MfaStatus
    {
        $enrolled = $this->factors->findByAccount($account)?->isActive() === true;

        return new MfaStatus($enrolled, $enrolled ? $this->recoveryCodes->remaining($account) : 0);
    }
}
