<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * An expected value no longer matches what CRM or Identity stores (ADR 0038, P5). Nothing was written, in any field.
 * It carries the three current values, which the Actor's scope already lets them read.
 */
final class StaleBasicDetails extends RuntimeException
{
    public function __construct(public readonly BasicDetails $current)
    {
        parent::__construct('The details have changed since they were read.');
    }
}
