<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * Draft or Published, for a Pack and for a Card alike, and nothing else (ADR 0037, decisions 12 and 22). Reversible: there
 * is no Archive, Trash or schedule. A validated string in the database, never a database enum (charter rule 13).
 */
enum PublicationState: string
{
    case Draft = 'draft';
    case Published = 'published';
}
