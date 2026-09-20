<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mail;

use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Application\InvitationNotifier;
use App\Modules\Identity\Application\IssuedInvitation;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the invitation through Laravel's mail system, on whatever transport the environment configures (Mailpit in
 * development; production's is host-supplied configuration, and no provider is baked in here).
 *
 * Unlike the recovery message, whose public answer must not depend on delivery, an operator issued this and needs
 * to know whether it went: so it REPORTS failure. It never throws, and a failure is logged as the failure's class
 * and message only, never the link or the token. What the mail system accepted is all it can vouch for.
 */
final readonly class MailInvitationNotifier implements InvitationNotifier
{
    public function __construct(
        private Config $config,
        private LoggerInterface $log,
    ) {}

    public function send(IssuedInvitation $invitation): InvitationDelivery
    {
        $link = rtrim($this->config->string('app.url'), '/').$this->config->string('identity.invitation.console_path')
            .'#'.http_build_query(['token' => $invitation->revealToken()], '', '&', PHP_QUERY_RFC3986);
        $days = max(1, (int) ceil(($invitation->expiresAt->getTimestamp() - now()->getTimestamp()) / 86400));

        try {
            Mail::to($invitation->email)->send(new InvitationMail($link, $days));
        } catch (Throwable $e) {
            $this->log->error('An invitation message could not be sent.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return InvitationDelivery::Failed;
        }

        return InvitationDelivery::Sent;
    }
}
