<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\IssueAdministrativePasswordReset;
use App\Modules\Identity\Application\PasswordResetNotIssuable;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator helps an Account holder recover their password by sending them the normal password-reset email. Needs
 * `identity.accounts.manage`, and (at the route) a recent password-and-second-factor proof. The operator never chooses,
 * sees or receives the password, the token or the link, and this does not reset the authenticator: the Account holder
 * follows the usual link and sets their own password. Identity's IssueAdministrativePasswordReset does the work through
 * the existing reset issuance and mail.
 */
final readonly class SendManagedPasswordReset
{
    public function __construct(
        private AuthorizeAction $authorize,
        private IssueAdministrativePasswordReset $issue,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws PasswordResetNotIssuable the Account is invited or disabled, or was sent a reset a moment ago
     */
    public function __invoke(Actor $actor, AccountId $target): ManagedPasswordReset
    {
        ($this->authorize)($actor, Capability::ManageAccounts);

        $delivery = ($this->issue)($target, $actor);

        return new ManagedPasswordReset($this->views->of($target), $delivery);
    }
}
