<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * What became of the message that carries an invitation. The invitation itself exists either way: delivery is a
 * network call and is never part of the transaction that created it (ADR 0024).
 */
enum InvitationDelivery: string
{
    /** The mail system accepted it. That is all this can know: it says nothing about the inbox. */
    case Sent = 'sent';

    /** It was not sent. The Account and its invitation are committed; the operator asks for a new one. */
    case Failed = 'failed';
}
