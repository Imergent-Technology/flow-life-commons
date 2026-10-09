<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Shared\Domain\UlidIdentifier;

/** The stable identity of one relationship instance (ADR 0038, F6). A re-created relationship is a new id. */
final readonly class RelationshipId extends UlidIdentifier {}
