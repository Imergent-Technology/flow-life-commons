<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auth;

use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use App\Modules\Identity\Infrastructure\Persistence\AccountMapper;
use App\Modules\Identity\Infrastructure\Persistence\AccountRecord;
use App\Shared\Domain\AccountId;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use InvalidArgumentException;

/**
 * Laravel's view of Identity's Accounts, so its session guard can authenticate them.
 *
 * - Lookups by credentials go through EmailAddress and the `email_canonical` column,
 *   never `email` (ADR 0015).
 * - Only an Account that may authenticate is ever returned, by id or by credentials. A
 *   disabled (or never-activated) Account therefore stops resolving on its next request,
 *   whatever its session says. Identity's Domain stays the owner of what "may
 *   authenticate" means (Account::canAuthenticate); this only asks it.
 * - There is no remember-me: the token methods are inert.
 */
final readonly class AccountUserProvider implements UserProvider
{
    public function __construct(
        private Hasher $hasher,
        private AccountMapper $mapper,
    ) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        if (! is_string($identifier)) {
            return null;
        }

        try {
            $id = AccountId::fromString($identifier);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->eligible(AccountRecord::query()->find($id->value));
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    /** @param  array<string, mixed>  $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if (! is_string($email)) {
            return null;
        }

        try {
            $canonical = EmailAddress::fromString($email)->canonical;
        } catch (InvalidEmailAddress) {
            return null;
        }

        return $this->eligible(AccountRecord::query()->where('email_canonical', $canonical)->first());
    }

    /** @param  array<string, mixed>  $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        return $user instanceof AccountRecord
            && is_string($password)
            && $this->mayAuthenticate($user)
            && $this->hasher->check($password, (string) $user->getAuthPassword());
    }

    /** @param  array<string, mixed>  $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Rehashing on login arrives with the credential lifecycle (password change/reset).
    }

    private function eligible(?AccountRecord $record): ?AccountRecord
    {
        return $record !== null && $this->mayAuthenticate($record) ? $record : null;
    }

    private function mayAuthenticate(AccountRecord $record): bool
    {
        return $this->mapper->toDomain($record)->canAuthenticate();
    }
}
