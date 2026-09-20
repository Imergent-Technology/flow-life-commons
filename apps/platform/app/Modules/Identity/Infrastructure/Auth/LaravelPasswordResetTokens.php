<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auth;

use App\Modules\Identity\Application\IssuedPasswordReset;
use App\Modules\Identity\Application\PasswordResetTokens;
use App\Modules\Identity\Domain\Account;
use DateTimeImmutable;

/**
 * Identity's PasswordResetTokens over Laravel's token repository (AccountResetTokenRepository). The
 * framework is confined to here: the use cases see the port, and the Account never becomes a
 * framework object.
 *
 * The table, expiry and throttle come from `config/auth.php` (`passwords.accounts`), so there is one
 * place to change them.
 */
final readonly class LaravelPasswordResetTokens implements PasswordResetTokens
{
    public function __construct(
        private AccountResetTokenRepository $repository,
        private int $expiresInSeconds,
    ) {}

    public function issue(Account $account): IssuedPasswordReset
    {
        $token = $this->repository->create($this->subject($account));

        return new IssuedPasswordReset($token, DateTimeImmutable::createFromInterface(now())->modify("+{$this->expiresInSeconds} seconds"));
    }

    public function issuedRecently(Account $account): bool
    {
        return (bool) $this->repository->recentlyCreatedToken($this->subject($account));
    }

    public function isValid(Account $account, #[\SensitiveParameter] string $token): bool
    {
        return $this->repository->exists($this->subject($account), $token);
    }

    public function spendDecoyCheck(#[\SensitiveParameter] string $token): void
    {
        $this->repository->spendDecoyCheck($token);
    }

    public function revoke(Account $account): void
    {
        $this->repository->delete($this->subject($account));
    }

    private function subject(Account $account): ResetSubject
    {
        return new ResetSubject($account->email->canonical);
    }
}
