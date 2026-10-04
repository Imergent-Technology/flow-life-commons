<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/**
 * The security events Resources records (ADR 0037, decision 55): the PERMANENT deletion of a Pack or a Card, and nothing else.
 * Types are defined by the calling module and stored as plain strings (ADR 0019). Routine content work records no event.
 */
enum ResourceEvent: string
{
    case PackDeleted = 'resource.pack_deleted';
    case CardDeleted = 'resource.card_deleted';
}
