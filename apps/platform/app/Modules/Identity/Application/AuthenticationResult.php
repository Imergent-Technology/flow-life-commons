<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * The outcome of a login attempt. Failure carries no detail on purpose: unknown address,
 * wrong password, invited and disabled Accounts are indistinguishable to the caller.
 */
final readonly class AuthenticationResult
{
    private function __construct(
        public AuthenticationStatus $status,
        public ?CurrentAccount $account,
        public ?int $retryAfterSeconds,
    ) {}

    public static function authenticated(CurrentAccount $account): self
    {
        return new self(AuthenticationStatus::Authenticated, $account, null);
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
