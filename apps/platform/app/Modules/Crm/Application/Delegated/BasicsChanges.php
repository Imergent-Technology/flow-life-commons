<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

/**
 * The only changes `UpdatePersonBasics` accepts (ADR 0038, P5): the display name, the primary email and the primary phone,
 * each optional. A detail that is null is not sent and is left alone. There is no other field to name.
 */
final readonly class BasicsChanges
{
    public function __construct(
        public ?FieldChange $displayName = null,
        public ?FieldChange $primaryEmail = null,
        public ?FieldChange $primaryPhone = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->displayName === null && $this->primaryEmail === null && $this->primaryPhone === null;
    }
}
