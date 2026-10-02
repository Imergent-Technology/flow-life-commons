<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Interaction;
use App\Modules\Identity\Application\PersonSummary;

/** An interaction with its author and last editor named. A name is null only if Identity no longer holds that Person. */
final readonly class InteractionView
{
    public function __construct(
        public Interaction $interaction,
        public ?PersonSummary $author,
        public ?PersonSummary $updatedBy,
    ) {}
}
