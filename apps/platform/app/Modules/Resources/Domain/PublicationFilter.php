<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The privileged library's publication filter (ADR 0038, R8). It only narrows what `resources.view` already reads.
 *
 * - `published`: Published Packs.
 * - `draft`: Draft Packs, and Published Packs that hold at least one Draft Card (there is unpublished work in them).
 */
enum PublicationFilter: string
{
    case Published = 'published';
    case Draft = 'draft';

    public function admits(Pack $pack, int $draftCards): bool
    {
        return match ($this) {
            self::Published => $pack->isPublished(),
            self::Draft => ! $pack->isPublished() || $draftCards > 0,
        };
    }
}
