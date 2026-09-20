<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The canonical human (ADR 0015). Deliberately thin: an id and a display name.
 *
 * Contact and profile attributes must NOT accumulate here. CRM will own rich contact
 * data keyed by `person_id`; a Person is an identity anchor, not a profile.
 */
final readonly class Person
{
    public const int MAX_DISPLAY_NAME_LENGTH = 255;

    private function __construct(
        public PersonId $id,
        public string $displayName,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($displayName === '' || mb_strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A display name must be 1 to %d characters.',
                self::MAX_DISPLAY_NAME_LENGTH,
            ));
        }
    }

    public static function create(PersonId $id, string $displayName, DateTimeImmutable $now): self
    {
        return new self($id, trim($displayName), $now, $now);
    }

    public static function reconstitute(
        PersonId $id,
        string $displayName,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $displayName, $createdAt, $updatedAt);
    }
}
