<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Instants cross the persistence boundary in UTC. Eloquent formats a date in the zone
 * the object carries, so a non-UTC DateTimeImmutable would otherwise be stored as its
 * local wall-clock time. Seconds precision: the columns are DATETIME(0).
 */
final class Utc
{
    public static function toColumn(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(new DateTimeZone('UTC'))->startOfSecond();
    }

    public static function toColumnOrNull(?DateTimeInterface $instant): ?CarbonImmutable
    {
        return $instant === null ? null : self::toColumn($instant);
    }

    public static function fromColumn(CarbonInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromColumnOrNull(?CarbonInterface $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::fromColumn($value);
    }
}
