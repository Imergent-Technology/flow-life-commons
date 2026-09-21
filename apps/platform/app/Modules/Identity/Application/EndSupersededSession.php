<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\AccountId;

/**
 * Is this authenticated session still bound to the Account's current security generation (ADR 0025)?
 *
 * Asked on every request an authenticated session makes. It is the enforcement half of the invariant:
 * whatever happened at the instant the session was created, an authentication established against a
 * superseded security state can never obtain authority, because this is passed before authentication is.
 *
 * It FAILS SAFE, like the absolute-lifetime check. A session with no generation, a generation that is
 * not an integer, or one whose Account has gone, is superseded. Nothing is cached: the current
 * generation is read from committed state every time.
 */
final readonly class EndSupersededSession
{
    public function __construct(
        private AccountSecurityGeneration $generations,
        private AccountRepository $accounts,
        private AuthenticationAudit $audit,
    ) {}

    /**
     * @param  mixed  $held  whatever the session stored, so anything odd fails safe
     * @return bool true when the session must end (and the event has been recorded)
     */
    public function __invoke(AccountId $accountId, mixed $held, ClientContext $client): bool
    {
        $current = $this->generations->current($accountId);
        if ($current !== null && is_int($held) && $held === $current) {
            return false;
        }

        $this->audit->sessionSuperseded(
            $this->accounts->find($accountId)?->personId, $accountId,
            is_int($held) ? $held : null, $current, $client,
        );

        return true;
    }
}
