<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactTag;

/** A tag and how many People currently hold it. */
final readonly class TagWithCount
{
    public function __construct(public ContactTag $tag, public int $people) {}
}
