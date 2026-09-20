<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The authenticated subject of a request: identity and provenance only.
 *
 * - It exists only on authenticated requests. There is no anonymous Actor: the absence
 *   of one is not an authorization outcome, and none is ever fabricated.
 * - It carries NO capabilities, roles or any snapshot of privilege. Authorization is
 *   always evaluated against current persisted state at the moment of the check, so a
 *   captured Actor (for example in a queued job) can never preserve revoked privileges.
 * - Shipped shape: one, `user()`. Service and delegated forms are designed but not built.
 *
 * It lives in Shared because Identity records events through Audit; anywhere else it
 * would make one of them import the other and close a cycle.
 */
final readonly class Actor
{
    private function __construct(
        public AccountId $accountId,
        public PersonId $personId,
        public AuthenticationMethod $authenticatedVia,
    ) {}

    /** A signed-in human, via their Console session. */
    public static function user(AccountId $accountId, PersonId $personId): self
    {
        return new self($accountId, $personId, AuthenticationMethod::Session);
    }
}
