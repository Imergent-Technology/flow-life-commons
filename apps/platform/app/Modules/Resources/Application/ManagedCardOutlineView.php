<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardOutline;

/** A Card in a Pack's contents list, for management: no content, but its state, audience and provenance. */
final readonly class ManagedCardOutlineView
{
    public function __construct(public CardOutline $card, public ResourcePerson $createdBy, public ResourcePerson $updatedBy) {}
}
