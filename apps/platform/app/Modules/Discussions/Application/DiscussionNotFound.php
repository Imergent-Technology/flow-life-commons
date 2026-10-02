<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use RuntimeException;

final class DiscussionNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such discussion.');
    }
}
