<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/**
 * A change that would leave a PUBLISHED Pack without something it must always keep: its Category, an audience, or a Published
 * Card (ADR 0037, decision 14). Unpublish the Pack first, or publish another Card.
 */
final class PublishedPackRequirement extends RuntimeException
{
    /** @param  string  $requirement  `category`, `audience` or `published_card` */
    public function __construct(public readonly string $requirement)
    {
        parent::__construct('That would leave a published Pack without its '.str_replace('_', ' ', $requirement).'. Unpublish the Pack first.');
    }
}
