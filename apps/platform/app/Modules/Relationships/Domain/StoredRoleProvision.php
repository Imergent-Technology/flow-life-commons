<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * The current default-role decision for one relationship (ADR 0038, K3).
 * `role` is the provisionable role's value as stored. It is not an Access role,
 * and it is not evidence that a grant exists: Access owns the grant.
 */
final readonly class StoredRoleProvision
{
    public const string Granted = 'granted';

    public const string Declined = 'declined';

    public function __construct(
        public string $role,
        public string $decision,
        public DateTimeImmutable $decidedAt,
        public PersonId $decidedBy,
    ) {}
}
