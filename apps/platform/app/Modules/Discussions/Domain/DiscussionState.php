<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

/**
 * Open or resolved, and nothing else (ADR 0035). A validated string in the database, never a database enum (charter rule 13).
 */
enum DiscussionState: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
