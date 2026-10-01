<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

/**
 * The profile fields a caller wants changed. A field is in `$fields` only if the caller sent it: an absent field is
 * left alone, a null or blank one clears it.
 */
final readonly class ProfileChanges
{
    /** @param  array{how_we_know?: ?string, affiliation?: ?string}  $fields */
    public function __construct(public array $fields = []) {}

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }
}
