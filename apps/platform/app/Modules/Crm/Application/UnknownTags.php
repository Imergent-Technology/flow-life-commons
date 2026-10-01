<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

/** A set of tag ids included ones that name no tag. Nothing was changed. */
final class UnknownTags extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('One or more of those tags do not exist.');
    }
}
