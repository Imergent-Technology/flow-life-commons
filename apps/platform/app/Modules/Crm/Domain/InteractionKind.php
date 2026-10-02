<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * What sort of contact an interaction records. A note is the general case; the others say how a conversation happened.
 * Stored as its string value; a new kind is a deliberate addition here, not a free-text column.
 */
enum InteractionKind: string
{
    case Note = 'note';
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
}
