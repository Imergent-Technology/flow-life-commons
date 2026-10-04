<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/** The Pack lacks something it needs to be Published; `unmet` names each (`category`, `audience`, `published_card`). */
final class PackNotPublishable extends RuntimeException
{
    /** @param  list<string>  $unmet */
    public function __construct(public readonly array $unmet)
    {
        parent::__construct('That Pack cannot be published yet: it needs '.implode(', ', array_map(static fn (string $u): string => str_replace('_', ' ', $u), $unmet)).'.');
    }
}
