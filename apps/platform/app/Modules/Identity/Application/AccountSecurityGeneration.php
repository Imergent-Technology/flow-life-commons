<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;

/**
 * The Account's **security generation** (ADR 0025): a monotonic counter that says which security state
 * an authentication proof was made against.
 *
 * The invariant it exists for, in one sentence:
 *
 *     An authenticated session carries the security generation its proof was checked against, and has
 *     authority only while that is still the Account's current generation.
 *
 * Every operation that ends an Account's sessions advances it, in the SAME transaction and while
 * holding the same Account row lock every authentication proof takes. So an operation that finishes a
 * sign-in either sees the advance (and its own proof fails on current state) or commits before it (and
 * the generation it read is superseded a moment later, which every subsequent request detects). Deleting
 * session rows cannot do this on its own: a row the transport has not written yet is not there to delete,
 * and a request already in flight writes its row back afterwards.
 *
 * It is deliberately NOT part of the Account aggregate: an aggregate is saved as a whole, and a
 * whole-row save of a copy read earlier would undo a concurrent advance — the precise class of bug this
 * closes. `advance()` is a single atomic increment on the locked row instead.
 */
interface AccountSecurityGeneration
{
    /** The Account's current generation, or null if there is no such Account. */
    public function current(AccountId $account): ?int;

    /**
     * Advances the generation and returns the new value. Called inside the transaction of an operation
     * that has just invalidated the Account's authentication state, beside the session revocation.
     */
    public function advance(AccountId $account): int;
}
