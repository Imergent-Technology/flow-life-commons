<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use DateTimeImmutable;
use DateTimeZone;

/** Instants the relationship use cases persist: UTC, from the application clock. */
final class RelationshipTime
{
    public static function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now())->setTimezone(new DateTimeZone('UTC'));
    }
}
