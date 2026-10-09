<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\SourcedRoleGrant;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * One sourced grant as a caller that checks integrity may see it (ADR 0038, F15, K4).
 * `sourceType` and `roleKey` are the stored strings, including a value the catalog no
 * longer knows, so a check can see it instead of the read failing closed by exception.
 */
final readonly class SourcedRoleGrantRecord
{
    public function __construct(
        public PersonId $personId,
        public string $roleKey,
        public string $sourceType,
        public string $sourceId,
        public ?AccountId $grantedByAccountId,
        public DateTimeImmutable $grantedAt,
    ) {}

    public static function from(SourcedRoleGrant $grant): self
    {
        return new self(
            $grant->personId,
            $grant->roleKey,
            $grant->sourceType,
            $grant->sourceId,
            $grant->grantedByAccountId,
            $grant->grantedAt,
        );
    }
}
