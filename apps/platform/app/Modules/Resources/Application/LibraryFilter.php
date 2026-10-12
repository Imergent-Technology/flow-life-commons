<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PublicationFilter;

/**
 * What the privileged library may be narrowed by (ADR 0038, R8). Filters only narrow: none of them widens, and none replaces the
 * `resources.view` check. `audience` matches the Packs targeted at that audience (a Pack's own set, as management's filter does).
 */
final readonly class LibraryFilter
{
    public function __construct(
        public ?CategoryId $category = null,
        public ?string $text = null,
        public ?PublicationFilter $publication = null,
        public ?Audience $audience = null,
    ) {}
}
