<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/** A fresh invitation cannot be issued: the Account is not invited (it is active, or disabled). Nothing was changed. */
final class InvitationNotIssuable extends RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct('An invitation can only be issued for an Account that is still invited.');
    }
}
