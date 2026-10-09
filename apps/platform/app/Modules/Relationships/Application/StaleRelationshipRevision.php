<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** The instance id or the revision is not the current one. Nothing was written (ADR 0038, F10). */
final class StaleRelationshipRevision extends RuntimeException
{
    public function __construct(public readonly RelationshipManagementView $current)
    {
        parent::__construct('This relationship was changed. Reload it and try again.');
    }
}
