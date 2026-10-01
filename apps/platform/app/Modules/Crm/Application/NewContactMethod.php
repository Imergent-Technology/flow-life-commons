<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactMethodKind;

/** A contact method as a caller asks for it to be recorded: not yet validated or normalised. */
final readonly class NewContactMethod
{
    public function __construct(
        public ContactMethodKind $kind,
        public string $value,
        public ?string $label = null,
        public bool $isPrimary = false,
    ) {}
}
