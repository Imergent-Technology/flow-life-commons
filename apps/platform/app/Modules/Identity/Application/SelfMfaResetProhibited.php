<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * An operator tried to reset their OWN second factor through the administrative route. Refused, and nothing was
 * changed. Self-service replacement exists for someone who still holds a factor; someone who has lost every factor
 * is recovered by another operator, or by the server (ADR 0024).
 */
final class SelfMfaResetProhibited extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You cannot reset your own second factor here. Ask another administrator, or replace it from your account security page.');
    }
}
