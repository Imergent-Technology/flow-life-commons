<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use RuntimeException;

final class DiscussionResolved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That discussion is resolved. Reopen it to reply.');
    }
}
