<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/** The upload is over the configured limit (`413 file_too_large`). Says the limit, never anything about the file. */
final class FileTooLarge extends RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct('That file is too large: the limit is '.AssetLimits::describe($maxBytes).'.');
    }
}
