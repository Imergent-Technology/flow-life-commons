<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Card;

/** One Card with its content, for management (Drafts included), and who created and last edited it. */
final readonly class ManagedCardView
{
    public function __construct(public Card $card, public ResourcePerson $createdBy, public ResourcePerson $updatedBy) {}
}
