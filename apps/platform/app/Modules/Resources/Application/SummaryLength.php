<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/** The configured allowance for a derived summary (`resources.summary.derived_length`), read where use cases need it. */
final class SummaryLength
{
    public static function current(): int
    {
        return config()->integer('resources.summary.derived_length', 200);
    }
}
