<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\DeliverInvitation;
use App\Modules\Identity\Application\InvitationNotIssuable;
use App\Modules\Identity\Application\ReissueInvitation;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator issues a FRESH invitation for an Account that is still invited (the old one expired, the message was
 * lost, or delivery failed) and it is mailed after the commit. Needs `identity.invitations.issue`. The old invitation
 * is deleted in the same transaction, so exactly one is ever usable. Never for an active Account.
 */
final readonly class ReissueOperatorInvitation
{
    public function __construct(
        private AuthorizeAction $authorize,
        private ReissueInvitation $reissue,
        private DeliverInvitation $deliver,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws InvitationNotIssuable
     */
    public function __invoke(Actor $actor, AccountId $account): OperatorInvitation
    {
        ($this->authorize)($actor, Capability::IssueInvitations);

        $issued = ($this->reissue)($account, $actor);
        $delivery = ($this->deliver)($issued, $actor, 'reissued');

        return new OperatorInvitation($this->views->of($account), $delivery);
    }
}
