<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/** An operator asked for a password-reset email the Account cannot be sent. Nothing was issued, sent or recorded as issued. */
final class PasswordResetNotIssuable extends RuntimeException
{
    public function __construct(public readonly PasswordResetRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
