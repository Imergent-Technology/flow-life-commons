<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The password-recovery message, and nothing else it might be tempted to say. It carries the link and
 * how long it lasts: no account or person identifier, no status, no address history. It is plain text
 * (nothing to render, nothing to track) and is NOT queued: a queued message would put the raw token in
 * the jobs table, and the token must never be stored.
 *
 * The link puts the secrets in the URL FRAGMENT, which a browser never sends to a server, so they stay
 * out of access logs and Referer headers. The Console's reset page (a later phase) reads them from
 * `location.hash` and posts them to `POST /api/v1/password/reset`.
 */
final class PasswordResetMail extends Mailable
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $link,
        private readonly int $validForMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(text: 'identity::password-reset', with: ['link' => $this->link, 'minutes' => $this->validForMinutes]);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['link' => '[redacted]'];
    }
}
