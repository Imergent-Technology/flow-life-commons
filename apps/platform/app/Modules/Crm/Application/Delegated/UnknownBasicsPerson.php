<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use RuntimeException;

/** The Person a delegated operation named does not exist. The caller has already put the Person in scope, so this is not an expected outcome. */
final class UnknownBasicsPerson extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such person.');
    }
}
