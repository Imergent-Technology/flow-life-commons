<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

/**
 * One basic detail as `{from, to}` (ADR 0038, P5): what the caller last saw, compared as an exact string after the lock
 * is held, and what it asks for. `from` is null when the caller saw no primary of that kind; `to` is never null.
 */
final readonly class FieldChange
{
    public function __construct(public ?string $from, public string $to) {}
}
