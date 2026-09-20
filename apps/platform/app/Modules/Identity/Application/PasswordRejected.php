<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PasswordViolation;
use RuntimeException;

/** A proposed password does not meet the policy. Carries reason classes only, never the text. */
final class PasswordRejected extends RuntimeException
{
    /** @param  non-empty-list<PasswordViolation>  $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The password does not meet the password policy.');
    }
}
