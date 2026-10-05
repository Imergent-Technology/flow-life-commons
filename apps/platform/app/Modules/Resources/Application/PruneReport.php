<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/**
 * What one prune of the Resources store decided, file by file. Lists hold storage keys (or, for `ignored`, the raw names found),
 * each in name order. In a dry run `removed` is what WOULD have been removed.
 */
final class PruneReport
{
    public int $kept = 0;

    /** @var list<string> orphans removed (or, in a dry run, that would be) */
    public array $removed = [];

    /** @var list<string> orphans whose removal failed; they stay, and the next run tries again */
    public array $failed = [];

    /** @var list<string> unreferenced files younger than the grace period, left alone */
    public array $recent = [];

    /** @var list<string> names in the store that are not Resources storage keys, left alone */
    public array $ignored = [];

    /** @var list<string> storage keys an asset row refers to whose file is not in the store */
    public array $missing = [];

    public function __construct(public readonly int $graceHours, public readonly bool $dryRun) {}
}
