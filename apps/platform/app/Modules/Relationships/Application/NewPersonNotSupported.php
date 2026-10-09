<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** Intake of a new Person is the CRM seam (WP3). WP1 records a relationship for a Person who already exists. */
final class NewPersonNotSupported extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Recording a new Person is not available here.');
    }
}
