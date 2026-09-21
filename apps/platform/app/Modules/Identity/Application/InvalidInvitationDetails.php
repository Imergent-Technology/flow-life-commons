<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

/** The email address or display name given for an invitation is not acceptable. `field` says which, for a client. */
final class InvalidInvitationDetails extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = 'email')
    {
        parent::__construct($message);
    }
}
