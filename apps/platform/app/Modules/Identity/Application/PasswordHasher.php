<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PlainPassword;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only way an Account password is hashed or checked. It accepts a PlainPassword and nothing
 * else, so the text it hashes has necessarily been through the one normalisation boundary, and the
 * text it checks at sign-in is exactly the text that would have been hashed when the password was set.
 *
 * It is a second line of defence for the 72-byte limit. The policy refuses an over-long password
 * with a useful message; this refuses to hash one at all if the policy was somehow bypassed, and
 * refuses to VERIFY one (bcrypt would otherwise compare only its first 72 bytes, so a longer
 * candidate could match a stored password it does not equal). The framework's hasher is configured
 * with the same limit as a third: see config/hashing.php.
 *
 * It says nothing of the hash's algorithm: the stored value is opaque, so a later move off bcrypt
 * (rehash on sign-in) changes the hasher's configuration and nothing that calls this.
 */
final class PasswordHasher
{
    /** A hash of a random string at the application's own cost, for paths with no real hash to check. */
    private static ?string $decoy = null;

    public function __construct(private readonly Hasher $hasher) {}

    /**
     * @throws LogicException the password cannot be hashed safely: a caller skipped the policy
     */
    public function hash(PlainPassword $password): string
    {
        if (! $password->isHashable()) {
            throw new LogicException('A password that cannot be hashed safely reached the hasher: the password policy was bypassed.');
        }

        return $this->hasher->make($password->reveal());
    }

    /** False for anything that cannot be checked without part of it being ignored, and for a mismatch. */
    public function matches(PlainPassword $password, string $hash): bool
    {
        return $password->isHashable() && $this->hasher->check($password->reveal(), $hash);
    }

    /**
     * Something to check against when there is no real hash, so a path with no Account does the same
     * hashing work as one with an Account and its timing gives nothing away.
     */
    public function decoyHash(): string
    {
        return self::$decoy ??= $this->hasher->make(Str::random(40));
    }
}
