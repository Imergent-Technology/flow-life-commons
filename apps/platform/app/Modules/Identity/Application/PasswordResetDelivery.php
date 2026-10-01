<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * What became of the message that carries a password-reset token. The token is stored either way: delivery is a network
 * call and is never part of the transaction that issued it. The public "I forgot my password" answer ignores this (it is
 * the same either way); an operator who asked for the message to be sent is TOLD.
 */
enum PasswordResetDelivery: string
{
    /** The mail system accepted it. That is all this can know: it says nothing about the inbox. */
    case Sent = 'sent';

    /** It was not sent. The token is stored and valid, but nobody has it; a new request issues another. */
    case Failed = 'failed';
}
