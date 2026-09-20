<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mail;

use App\Modules\Identity\Application\IssuedPasswordReset;
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
 * It never throws. The public answer to "I forgot my password" is the same whether or not a message
 * went out, so a delivery failure is recorded in the log (the failure's class and message, never the
 * link or token) and the caller carries on. The token stays valid, and the owner can ask again after
 * the one-a-minute throttle.
 */
final readonly class MailPasswordResetNotifier implements PasswordResetNotifier
{
    public function __construct(
        private Config $config,
        private LoggerInterface $log,
    ) {}

    public function send(EmailAddress $to, IssuedPasswordReset $reset): void
    {
        $fragment = http_build_query(['token' => $reset->revealToken(), 'email' => $to->value], '', '&', PHP_QUERY_RFC3986);
        $link = rtrim($this->config->string('app.url'), '/').$this->config->string('identity.password_reset.console_path').'#'.$fragment;
        $minutes = max(1, (int) round(($reset->expiresAt->getTimestamp() - now()->getTimestamp()) / 60));

        try {
            Mail::to($to->value)->send(new PasswordResetMail($link, $minutes));
        } catch (Throwable $e) {
            $this->log->error('A password reset message could not be sent.', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}
