<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\EmailAddress;

/**
 * Identity's credential-lifecycle events (invitation, reset, change), in one place so their shape and
 * their "no secrets" discipline are decided once. Internal to the use cases, like AuthenticationAudit.
 *
 * Contexts hold reason classes, counts and the identifier a caller CLAIMED. Never a password (plain,
 * normalised or hashed), an invitation or reset token, a session id or a CSRF value: those are not
 * even in scope here, and Audit refuses secret-named keys and secret-shaped values on top of that.
 *
 * Where the acting person is not authenticated (accepting an invitation, resetting a forgotten
 * password) there is no Actor and none is invented: the Account concerned is the subject.
 */
final readonly class CredentialAudit
{
    public function __construct(private RecordSecurityEvent $record) {}

    /**
     * @param  bool  $issuedByPlatform  the invitation had no inviting Account: the platform itself issued
     *                                  it (the administrator bootstrap). Recorded because it says what
     *                                  vouched for the address: the server operator, not a mailbox.
     */
    public function invitationAccepted(Account $account, bool $issuedByPlatform, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::InvitationAccepted->value, SecurityEventOutcome::Success,
            null, $account->personId, $account->id, $client->ip, $client->userAgent,
            ['issued_by' => $issuedByPlatform ? 'platform' : 'account'],
        );
    }

    /**
     * A reset was requested and a token issued. The Account is the subject; nobody is authenticated, so
     * there is no actor.
     */
    public function passwordResetRequested(Account $account, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::PasswordResetRequested->value, SecurityEventOutcome::Success,
            null, $account->personId, $account->id, $client->ip, $client->userAgent,
        );
    }

    /**
     * A reset was requested and NO token was issued. Recorded with a reason class and the identifier
     * the caller claimed, exactly like a failed sign-in; for an address with no Account there is no
     * subject, and none is invented.
     */
    public function passwordResetNotIssued(ResetFailure $reason, EmailAddress $attempted, ?Account $account, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::PasswordResetRequested->value, SecurityEventOutcome::Failure,
            null, $account?->personId, $account?->id, $client->ip, $client->userAgent,
            ['reason' => $reason->value, 'attempted_identifier' => $attempted->canonical],
        );
    }

    /** @param  int  $signedOut  how many of the Account's sessions were ended */
    public function passwordResetCompleted(Account $account, int $signedOut, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::PasswordResetCompleted->value, SecurityEventOutcome::Success,
            null, $account->personId, $account->id, $client->ip, $client->userAgent,
            ['signed_out' => $signedOut],
        );
    }

    public function passwordResetFailed(ResetFailure $reason, EmailAddress $attempted, ?Account $account, ClientContext $client): void
    {
        ($this->record)(
            IdentityEvent::PasswordResetFailed->value, SecurityEventOutcome::Failure,
            null, $account?->personId, $account?->id, $client->ip, $client->userAgent,
            ['reason' => $reason->value, 'attempted_identifier' => $attempted->canonical],
        );
    }

    /** A credential endpoint's limit is engaged. `attempted` is the identifier the caller claimed, if any. */
    public function rateLimited(ThrottledAction $action, ThrottleBlock $block, ?EmailAddress $attempted, ClientContext $client): void
    {
        $context = ['action' => $action->value, 'scope' => $block->scope, 'retry_after_seconds' => $block->retryAfterSeconds];
        if ($attempted !== null) {
            $context['attempted_identifier'] = $attempted->canonical;
        }

        ($this->record)(
            IdentityEvent::AuthenticationRateLimited->value, SecurityEventOutcome::Blocked,
            null, null, null, $client->ip, $client->userAgent, $context,
        );
    }
}
