<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\InteractionKind;
use DateTimeImmutable;

/** An interaction as a caller asks for it to be recorded: not yet validated. No `occurredAt` means "just now". */
final readonly class NewInteraction
{
    public function __construct(
        public InteractionKind $kind,
        public string $body,
        public ?DateTimeImmutable $occurredAt = null,
    ) {}
}
