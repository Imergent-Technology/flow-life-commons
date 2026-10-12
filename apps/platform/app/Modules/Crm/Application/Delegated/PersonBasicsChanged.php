<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use RuntimeException;

/**
 * An expected value no longer matches what is stored (ADR 0038, P5). Nothing was written, in any field. It carries the three
 * current values, which the caller's own scope already lets it read, and nothing else.
 */
final class PersonBasicsChanged extends RuntimeException
{
    public function __construct(public readonly PersonBasics $current)
    {
        parent::__construct('The details have changed since they were read.');
    }
}
