<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/** Builders and helpers shared by the Membership tests. */
final class Membership
{
    /** Persists a grant directly through the repository, for scenario setup. */
    public static function savedGrant(
        PersonId $person,
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
        MembershipGrantSource $source = MembershipGrantSource::Operator,
        ?string $sourceReference = null,
        ?AccountId $grantedBy = null,
    ): MembershipGrant {
        $starts = $startsAt ?? Identity::now();

        $grant = MembershipGrant::grant(
            MembershipGrantId::generate(), $person, $starts, $endsAt, $source, $sourceReference, $grantedBy, $starts,
        );
        app(MembershipGrantRepository::class)->add($grant);

        return $grant;
    }
}
