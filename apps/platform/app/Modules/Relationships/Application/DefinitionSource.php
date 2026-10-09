<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

/**
 * Where relationship definitions come from (ADR 0038, F3). Production reads the code-owned
 * directory. A test binds another source, through the container, before routes are registered.
 * Every source is validated by the same schema. A source returns data only.
 */
interface DefinitionSource
{
    /**
     * @return list<array<string, mixed>>
     */
    public function documents(): array;
}
