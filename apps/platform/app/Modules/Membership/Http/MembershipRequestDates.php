<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use DateTimeImmutable;
use DateTimeZone;

/** Parses a validated `date`-rule string into the UTC instant the repository stores. */
final class MembershipRequestDates
{
    public static function parse(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
