<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * How an invitation reached the person it is for (ADR 0024). It is the smallest honest answer to "does
 * accepting this show the holder controls the mailbox?", and it is decided when the invitation is issued,
 * never by whoever accepts it.
 *
 * Stored as a plain VARCHAR backed by this enum; a database ENUM would fail the portability guardrail
 * (ADR 0005).
 */
enum InvitationChannel: string
{
    /** Somebody handed the token over (the administrator bootstrap prints it to a server operator). Nothing shows the mailbox was reached. */
    case Operator = 'operator';

    /** The platform mailed the token to the Account's own address, so a holder who presents it has read that mailbox. */
    case Email = 'email';

    public function provesMailbox(): bool
    {
        return $this === self::Email;
    }
}
