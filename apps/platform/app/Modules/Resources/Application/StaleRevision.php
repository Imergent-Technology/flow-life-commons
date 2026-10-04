<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

/**
 * An authored edit was based on a revision that is no longer current (ADR 0037, decision 56): someone else's edit won. Carries the
 * current state so the editor can see what changed and decide, instead of overwriting it.
 */
final class StaleRevision extends RuntimeException
{
    public function __construct(public readonly ManagedPackView|ManagedCardView $current)
    {
        parent::__construct('Someone else changed this since you opened it. Review their version before saving yours.');
    }
}
