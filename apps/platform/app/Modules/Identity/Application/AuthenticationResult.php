<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * The outcome of a login attempt. Failure carries no detail on purpose: unknown address,
 * wrong password, invited and disabled Accounts are indistinguishable to the caller.
 */
final readonly class AuthenticationResult
{
    /**
     * @param  int|null  $securityGeneration  the Account's security generation as the sign-in read it under
     *                                        the Account's row lock (ADR 0025); set only when authenticated
     */
    private function __construct(
        public AuthenticationStatus $status,
        public ?CurrentAccount $account,
        public ?int $retryAfterSeconds,
        public ?PendingLogin $pending = null,
        public ?int $securityGeneration = null,
    ) {}

    public static function authenticated(CurrentAccount $account, int $securityGeneration): self
    {
        return new self(AuthenticationStatus::Authenticated, $account, null, null, $securityGeneration);
    }

    /** The password was proved; the sign-in is not complete, and nothing here is authentication. */
    public static function secondFactorPending(PendingLogin $pending): self
    {
        return new self(AuthenticationStatus::SecondFactorPending, null, null, $pending);
    }

    public static function failed(): self
    {
        return new self(AuthenticationStatus::Failed, null, null);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(AuthenticationStatus::Throttled, null, $retryAfterSeconds);
    }
}
