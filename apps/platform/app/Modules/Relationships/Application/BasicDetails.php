<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

/**
 * A Person's three basic details as CRM stores them (ADR 0038, P2): the display name, and the value of the primary email and
 * of the primary phone, each null when there is none. A view shows the email and phone beside the Person's name. A
 * `stale_person_basics` refusal shows all three. No other contact method, label or CRM field is ever in it.
 */
final readonly class BasicDetails
{
    public function __construct(
        public string $displayName,
        public ?string $primaryEmail,
        public ?string $primaryPhone,
    ) {}
}
