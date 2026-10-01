<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\AttemptThrottle;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CredentialAudit;
use App\Modules\Identity\Application\IssueAdministrativePasswordReset;
use App\Modules\Identity\Application\PasswordResetNotIssuable;
use App\Modules\Identity\Application\ThrottledAction;
use App\Modules\Identity\Application\TooManyAttempts;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator helps an Account holder recover their password by sending them the normal password-reset email. Needs
 * `identity.accounts.manage`, and (at the route) a recent password-and-second-factor proof. The operator never chooses,
 * sees or receives the password, the token or the link, and this does not reset the authenticator: the Account holder
 * follows the usual link and sets their own password. Identity's IssueAdministrativePasswordReset does the work through
 * the existing reset issuance and mail.
 *
 * It is also RATE LIMITED per target Account (and per source address), as `ReissueOperatorInvitation` is, for the same
 * reason: authentication, the capability and recent proof decide whether the CALLER may do this, but not how much mail
 * lands in the one inbox that belongs to somebody who did not ask. It coexists with the token store's one-a-minute rule,
 * which bounds live tokens and would still allow about sixty messages an hour.
 */
final readonly class SendManagedPasswordReset
{
    public function __construct(
        private AuthorizeAction $authorize,
        private IssueAdministrativePasswordReset $issue,
        private AccountViews $views,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws PasswordResetNotIssuable the Account is invited or disabled, or was sent a reset a moment ago
     * @throws TooManyAttempts too many reset emails have been sent to this Account lately
     */
    public function __invoke(Actor $actor, AccountId $target, ClientContext $client): ManagedPasswordReset
    {
        // Authorize FIRST: a caller who may not do this is refused without their attempt counting against the target's
        // allowance, so a refused caller cannot be used to exhaust the allowance of the person being recovered.
        ($this->authorize)($actor, Capability::ManageAccounts);

        $block = $this->throttle->block(ThrottledAction::PasswordResetByOperator, $client->ip, $target->value);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited(ThrottledAction::PasswordResetByOperator, $block, null, $client);
            }

            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::PasswordResetByOperator, $client->ip, $target->value);

        $delivery = ($this->issue)($target, $actor);

        return new ManagedPasswordReset($this->views->of($target), $delivery);
    }
}
