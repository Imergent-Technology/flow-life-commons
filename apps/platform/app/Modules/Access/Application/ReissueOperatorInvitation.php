<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\AttemptThrottle;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CredentialAudit;
use App\Modules\Identity\Application\DeliverInvitation;
use App\Modules\Identity\Application\InvitationNotIssuable;
use App\Modules\Identity\Application\ReissueInvitation;
use App\Modules\Identity\Application\ThrottledAction;
use App\Modules\Identity\Application\TooManyAttempts;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator issues a FRESH invitation for an Account that is still invited (the old one expired, the message was
 * lost, or delivery failed) and it is mailed after the commit. Needs `identity.invitations.issue`. The old invitation
 * is deleted in the same transaction, so exactly one is ever usable. Never for an active Account.
 *
 * It is also RATE LIMITED, which the other administration mutations are not, and for a different reason than the
 * credential endpoints. The three controls on the caller — authentication, the capability, and recent
 * password-and-second-factor proof — are what make this safe, and they are enough to decide whether the CALLER may
 * do it. What they do not bound is how much MAIL lands in somebody else's inbox, and that inbox belongs to the one
 * person in this transaction who did not ask for any of it. The limit is generous enough that a legitimate resend is
 * never refused, and is keyed on the target Account so one person's invitation cannot delay another's.
 */
final readonly class ReissueOperatorInvitation
{
    public function __construct(
        private AuthorizeAction $authorize,
        private ReissueInvitation $reissue,
        private DeliverInvitation $deliver,
        private AccountViews $views,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws InvitationNotIssuable
     * @throws TooManyAttempts too many invitations have been mailed to this Account lately
     */
    public function __invoke(Actor $actor, AccountId $account, ClientContext $client): OperatorInvitation
    {
        // Authorize FIRST: a caller who may not do this is refused without their attempt counting
        // against the target's allowance, so one cannot be used to exhaust the other's.
        ($this->authorize)($actor, Capability::IssueInvitations);

        $block = $this->throttle->block(ThrottledAction::InvitationReissue, $client->ip, $account->value);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited(ThrottledAction::InvitationReissue, $block, null, $client);
            }

            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::InvitationReissue, $client->ip, $account->value);

        $issued = ($this->reissue)($account, $actor);
        $delivery = ($this->deliver)($issued, $actor, 'reissued');

        return new OperatorInvitation($this->views->of($account), $delivery);
    }
}
