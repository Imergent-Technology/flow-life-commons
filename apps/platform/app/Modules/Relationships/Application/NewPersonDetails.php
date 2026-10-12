<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

/**
 * The new Person an intake may record (ADR 0038, P7): a display name and at most one email and one phone, each becoming the
 * Person's primary. Not validated here: CRM's and Identity's own rules do that.
 */
final readonly class NewPersonDetails
{
    public function __construct(
        public string $displayName,
        public ?string $email = null,
        public ?string $phone = null,
        public bool $confirmDistinct = false,
    ) {}
}
