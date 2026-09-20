<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;

/**
 * The store of password-reset tokens, as the use cases need it. A port: Laravel's token repository and
 * `password_reset_tokens` are an Infrastructure adapter, not the model. One live token per Account;
 * issuing another replaces it. Only a hash of a token is ever stored.
 *
 * Every method that touches the store must be called inside the caller's transaction when the caller
 * has one, and none of them may make a network call.
 */
interface PasswordResetTokens
{
    /** Issues a fresh token for the Account, replacing any earlier one. */
    public function issue(Account $account): IssuedPasswordReset;

    /** Whether a token was issued for the Account so recently that another must not be. */
    public function issuedRecently(Account $account): bool;

    /**
     * Whether $token is the Account's live, unexpired token. It does the same hashing work whether or
     * not the Account has a token, so its duration reveals nothing about that.
     */
    public function isValid(Account $account, #[\SensitiveParameter] string $token): bool;

    /**
     * Does the work of a check when there is no Account to check against (an unknown address), so that
     * path is not faster than the others.
     */
    public function spendDecoyCheck(#[\SensitiveParameter] string $token): void;

    /** Removes the Account's token, so it cannot be used again. */
    public function revoke(Account $account): void;
}
