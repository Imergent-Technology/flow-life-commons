<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/**
 * Identity's authentication events, in one place, so their shape and their "no secrets"
 * discipline are decided once. Internal to the use cases; other code goes through them.
 *
 * Contexts hold reason classes and the identifier a caller CLAIMED (a syntactically valid
 * email, never anything else the caller typed). Never a password, token, hash, session id
 * or CSRF value: those are not even in scope here, and Audit refuses secret-shaped values.
 */
final readonly class AuthenticationAudit
{
    public function __construct(private RecordSecurityEvent $record) {}

    /** @param  SecondFactorMethod|null  $secondFactor  what followed the password, if anything did */
    public function succeeded(Actor $actor, ClientContext $client, ?SecondFactorMethod $secondFactor = null): void
    {
        $context = ['method' => 'password'];
        if ($secondFactor !== null) {
            $context['second_factor'] = $secondFactor->value;
        }

        ($this->record)(
            IdentityEvent::AuthenticationSucceeded->value, SecurityEventOutcome::Success,
            $actor, $actor->personId, $actor->accountId, $client->ip, $client->userAgent,
            $context,
        );
    }

    /**
     * @param  Account|null  $account  the Account that exists for the address, if any. Recorded as the
     *                                 SUBJECT for the trail; there is no actor, and none is invented
     *                                 when no Account exists.
     */
    public function failed(FailureReason $reason, EmailAddress $attempted, ?Account $account, ClientContext $client): void
    {
        $context = ['reason' => $reason->value, 'attempted_identifier' => $attempted->canonical];
        if ($reason === FailureReason::AccountNotActive && $account !== null) {
            $context['account_status'] = $account->status->value;
        }

        ($this->record)(
            IdentityEvent::AuthenticationFailed->value, SecurityEventOutcome::Failure,
            null, $account?->personId, $account?->id, $client->ip, $client->userAgent, $context,
        );
    }

    public function loggedOut(?Actor $actor, ?PersonId $person, AccountId $account, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::AuthenticationLogout->value, SecurityEventOutcome::Success,
            $actor, $person, $account, $client->ip, $client->userAgent,
        );
    }

    public function rateLimited(EmailAddress $attempted, ThrottleBlock $block, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::AuthenticationRateLimited->value, SecurityEventOutcome::Blocked,
            null, null, null, $client->ip, $client->userAgent,
            [
                'scope' => $block->scope,
                'retry_after_seconds' => $block->retryAfterSeconds,
                'attempted_identifier' => $attempted->canonical,
            ],
        );
    }

    /**
     * An authenticated session was ended because the Account's security generation had moved on
     * (ADR 0025). The two counters are recorded because they are what an operator needs to see that
     * the session predates a reset, a disable or a credential replacement; neither is a secret, and
     * neither says which operation advanced it (the operation recorded its own event).
     */
    public function sessionSuperseded(?PersonId $person, AccountId $account, ?int $held, ?int $current, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::SessionSuperseded->value, SecurityEventOutcome::Blocked,
            null, $person, $account, $client->ip, $client->userAgent,
            ['held_generation' => $held, 'current_generation' => $current],
        );
    }

    public function sessionExpired(?PersonId $person, AccountId $account, ExpiryReason $reason, int $lifetimeMinutes, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::SessionAbsoluteExpired->value, SecurityEventOutcome::Blocked,
            null, $person, $account, $client->ip, $client->userAgent,
            ['reason' => $reason->value, 'lifetime_minutes' => $lifetimeMinutes],
        );
    }
}
