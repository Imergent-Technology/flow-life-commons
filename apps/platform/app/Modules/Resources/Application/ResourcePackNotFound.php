<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/**
 * The ONE answer a delivery route gives for a Pack the viewer cannot have, whatever the reason: it does not exist, it is a Draft,
 * it is not Published to this viewer's audience, or it has no Card the viewer may see (ADR 0037, decision 46). It carries no reason
 * because there must be none to read.
 */
final class ResourcePackNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such resource.');
    }
}
