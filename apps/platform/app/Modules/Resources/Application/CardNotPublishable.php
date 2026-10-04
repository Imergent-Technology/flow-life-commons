<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/**
 * The Card lacks something its Type needs to be Published (`content` for a basic Card, `uri` for an external link), or an edit to a
 * Published Card would leave it lacking one.
 */
final class CardNotPublishable extends RuntimeException
{
    /** @param  list<string>  $unmet */
    public function __construct(public readonly array $unmet)
    {
        parent::__construct('That Card needs '.implode(', ', array_map(static fn (string $u): string => str_replace('_', ' ', $u), $unmet)).' to be published.');
    }
}
