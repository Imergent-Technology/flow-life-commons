<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Laravel's database token repository (hashed tokens in `password_reset_tokens`, expiry, the
 * one-row-per-address rule), with one change: verifying a token does the same hashing work whether
 * or not the address has a token row.
 *
 * The framework's `exists()` short-circuits when there is no row, so it is measurably faster for an
 * address that never asked for a reset than for one that did. On the public reset-completion endpoint
 * that difference would say which addresses have accounts. Here a check always runs, against a
 * throwaway hash when there is nothing to check against, and the result is decided afterwards.
 *
 * Nothing else is altered: creation, hashing, expiry and deletion are the framework's own; the one
 * addition is a counted form of the framework's `deleteExpired()`, so scheduled maintenance can report
 * what it removed instead of saying nothing.
 */
final class AccountResetTokenRepository extends DatabaseTokenRepository
{
    /** A hash of a random string at the application's own cost, for the no-such-token path. */
    private static ?string $decoy = null;

    public function exists(CanResetPassword $user, #[\SensitiveParameter] $token): bool
    {
        $record = (array) $this->getTable()->where('email', $user->getEmailForPasswordReset())->first();
        $stored = $record['token'] ?? null;
        $createdAt = $record['created_at'] ?? null;

        // A check always runs, on the token's hash or on a decoy, and only then is the answer decided.
        $matches = $this->hasher->check($token, is_string($stored) ? $stored : $this->decoy());

        return is_string($stored) && is_string($createdAt) && ! $this->tokenExpired($createdAt) && $matches;
    }

    /**
     * The framework's `deleteExpired()`, reporting how many rows went. Same expiry, same table, same
     * statement: an expired token is already unusable (`exists()` refuses it), so this frees rows and
     * changes no behaviour, and running it again removes nothing.
     */
    public function deleteExpiredTokens(): int
    {
        return $this->getTable()->where('created_at', '<', Carbon::now()->subSeconds($this->expires))->delete();
    }

    /** The work of a check, against nothing. */
    public function spendDecoyCheck(#[\SensitiveParameter] string $token): void
    {
        $this->hasher->check($token, $this->decoy());
    }

    private function decoy(): string
    {
        return self::$decoy ??= $this->hasher->make(Str::random(40));
    }
}
