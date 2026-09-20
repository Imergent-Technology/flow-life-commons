<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * An invitation could not be accepted. Deliberately ONE outcome for every reason (unknown,
 * malformed, expired, already used, revoked, or its Account no longer in a state that can accept),
 * so a caller cannot use the difference to probe for tokens.
 */
final class InvitationRejected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The invitation is not valid.');
    }
}
