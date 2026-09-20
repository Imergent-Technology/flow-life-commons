<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\Account;
use App\Shared\Domain\Actor;

/**
 * The multi-factor events (ADR 0023), in one place so their shape and their "no secrets" discipline are
 * decided once. Internal to the use cases, like CredentialAudit.
 *
 * Contexts hold reason classes, method names and counts. NEVER a TOTP secret, a provisioning URI, a code
 * (authenticator or recovery), a recovery-code digest, or a password: none of those is even in scope
 * here, and Audit refuses secret-named keys and secret-shaped values on top of that.
 *
 * The catalog is deliberately short. There is no event for starting an enrolment or for a bad code while
 * the caller is rate limited: an engaged limit is audited once per interval, and each refused code is one
 * event, so the trail cannot grow faster than the limits allow.
 */
final readonly class MfaAudit
{
    public function __construct(private RecordSecurityEvent $record) {}

    /** An authenticator was proved for the first time. `$actor` is set when the person is signed in. */
    public function enabled(Account $account, ?Actor $actor, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::MfaEnabled->value, SecurityEventOutcome::Success,
            $actor, $account->personId, $account->id, $client->ip, $client->userAgent,
            ['method' => 'totp'],
        );
    }

    /**
     * A second-factor step was refused. `$during` is `sign_in` or `security_verification`. The Account is
     * the subject where it exists; nobody is authenticated for a sign-in, so there is no actor.
     */
    public function challengeFailed(?Account $account, SecondFactorFailure $reason, string $during, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::MfaChallengeFailed->value, SecurityEventOutcome::Failure,
            null, $account?->personId, $account?->id, $client->ip, $client->userAgent,
            ['reason' => $reason->value, 'during' => $during],
        );
    }

    /** A recovery code was spent. Says how many remain, never which code. */
    public function recoveryCodeUsed(Account $account, int $remaining, string $during, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::MfaRecoveryCodeUsed->value, SecurityEventOutcome::Success,
            null, $account->personId, $account->id, $client->ip, $client->userAgent,
            ['remaining' => $remaining, 'during' => $during],
        );
    }

    public function recoveryCodesRegenerated(Actor $actor, int $count, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::MfaRecoveryCodesRegenerated->value, SecurityEventOutcome::Success,
            $actor, $actor->personId, $actor->accountId, $client->ip, $client->userAgent,
            ['count' => $count],
        );
    }

    /** @param  int  $signedOut  how many of the Account's OTHER sessions were ended */
    public function replaced(Actor $actor, int $signedOut, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::MfaReplaced->value, SecurityEventOutcome::Success,
            $actor, $actor->personId, $actor->accountId, $client->ip, $client->userAgent,
            ['signed_out' => $signedOut],
        );
    }

    /** Recent security verification was established by password and second factor. */
    public function reverified(Actor $actor, SecondFactorMethod $method, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::SecurityReverified->value, SecurityEventOutcome::Success,
            $actor, $actor->personId, $actor->accountId, $client->ip, $client->userAgent,
            ['method' => $method->value],
        );
    }

    /** A session was ended because its Account now needs a second factor the session never proved. */
    public function sessionSecondFactorRequired(Account $account, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::SessionSecondFactorRequired->value, SecurityEventOutcome::Blocked,
            null, $account->personId, $account->id, $client->ip, $client->userAgent,
        );
    }
}
