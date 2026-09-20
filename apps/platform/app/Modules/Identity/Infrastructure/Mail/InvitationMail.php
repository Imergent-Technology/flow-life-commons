<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation message, and nothing else it might be tempted to say: the link and how long it lasts. No account
 * or person identifier, no status, no role, no name of who invited. Plain text (nothing to render or track) and NOT
 * queued: a queued message would put the raw token in the jobs table, and the token must never be stored.
 *
 * The link carries the token in the URL FRAGMENT, which a browser never sends to a server, so it stays out of
 * access logs and Referer headers. The Console's acceptance page reads it from `location.hash` and posts it to
 * `POST /api/v1/invitations/accept`.
 */
final class InvitationMail extends Mailable
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $link,
        private readonly int $validForDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to the Flow Life Guardian Console');
    }

    public function content(): Content
    {
        return new Content(text: 'identity::invitation', with: ['link' => $this->link, 'days' => $this->validForDays]);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['link' => '[redacted]'];
    }
}
