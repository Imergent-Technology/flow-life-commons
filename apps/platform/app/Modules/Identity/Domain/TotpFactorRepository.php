<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;
use DateTimeImmutable;

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

    /**
     * Housekeeping: forgets every PENDING secret generated before `$startedBefore` and never proved.
     *
     * A pending secret older than TotpFactor::PENDING_LIFETIME_SECONDS can no longer be confirmed
     * (`hasFreshPending` refuses it), so this changes nothing anyone can do; it stops unprovable
     * ciphertext sitting at rest indefinitely. An enrolment that also has an ACTIVE secret keeps it and
     * simply loses the pending one; one that has only the pending secret was never an enrolment at all
     * and goes entirely, because the domain requires a factor to hold an active secret, a pending one,
     * or both. Returns how many enrolments were changed or removed.
     */
    public function forgetStalePending(DateTimeImmutable $startedBefore): int;
}
