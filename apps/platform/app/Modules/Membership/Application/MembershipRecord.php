<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * One Person's membership state, derived at the instant it was read, plus their complete grant
 * history. Carries no payment facts: there are none to carry (ADR 0029).
 */
final readonly class MembershipRecord
{
    /**
     * @param  list<MembershipGrant>  $grants  the Person's complete history, oldest first, including revoked grants
     */
    public function __construct(
        public PersonId $personId,
        public bool $active,
        public ?DateTimeImmutable $currentAccessEndsAt,
        public bool $openEnded,
        public array $grants,
    ) {}
}
