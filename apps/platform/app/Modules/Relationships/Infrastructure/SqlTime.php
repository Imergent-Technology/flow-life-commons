<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

/** Instants in the database are UTC DATETIME columns written from the domain (charter rule 14). */
final class SqlTime
{
    public static function to(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function from(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
