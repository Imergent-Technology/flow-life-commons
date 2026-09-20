<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;
use DateTimeImmutable;

/**
 * An Account's recovery codes, by digest only. The raw code is never here.
 */
interface RecoveryCodeRepository
{
    /**
     * Makes exactly these the Account's recovery codes: every earlier one, used or not, stops
     * existing. Call inside the caller's transaction.
     *
     * @param  list<string>  $digests
     */
    public function replaceAll(AccountId $account, array $digests, DateTimeImmutable $now): void;

    /**
     * Spends the code with this digest, ATOMICALLY: one conditional update ("this code, unused"), so
     * of any number of simultaneous attempts exactly one returns true, whatever else is locked.
     */
    public function consume(AccountId $account, string $digest, DateTimeImmutable $now): bool;

    /** How many are still unused. */
    public function remaining(AccountId $account): int;

    /** Removes every one of the Account's recovery codes, used or not. Returns how many rows were removed. */
    public function deleteAll(AccountId $account): int;
}
