<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mail;

use App\Modules\Identity\Application\IssuedPasswordReset;
use App\Modules\Identity\Application\PasswordResetDelivery;
use App\Modules\Identity\Application\PasswordResetNotifier;
use App\Modules\Identity\Domain\EmailAddress;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the recovery message through Laravel's mail system, on whatever transport the environment
 * configures (Mailpit in development; production's is host-supplied configuration, and no provider is
 * baked in here).
 *
 * It never throws. A delivery failure is logged (the failure's class and message, never the link or token) and
 * reported as `Failed`; the public flow ignores that, the operator flow shows it. The token stays valid, and a new
 * request can be made after the one-a-minute limit.
 */
final readonly class MailPasswordResetNotifier implements PasswordResetNotifier
{
    public function __construct(
        private Config $config,
        private LoggerInterface $log,
    ) {}

    public function send(EmailAddress $to, IssuedPasswordReset $reset): PasswordResetDelivery
    {
        $fragment = http_build_query(['token' => $reset->revealToken(), 'email' => $to->value], '', '&', PHP_QUERY_RFC3986);
        $link = rtrim($this->config->string('app.url'), '/').$this->config->string('identity.password_reset.console_path').'#'.$fragment;
        $minutes = max(1, (int) round(($reset->expiresAt->getTimestamp() - now()->getTimestamp()) / 60));

        try {
            Mail::to($to->value)->send(new PasswordResetMail($link, $minutes));
        } catch (Throwable $e) {
            $this->log->error('A password reset message could not be sent.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return PasswordResetDelivery::Failed;
        }

        return PasswordResetDelivery::Sent;
    }
}
