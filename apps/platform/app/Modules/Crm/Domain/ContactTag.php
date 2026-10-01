<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\AccountId;
use DateTimeImmutable;

/**
 * A user-managed label for People (ADR 0034). A label ONLY: it carries no capability and establishes no Membership,
 * Volunteer or Guardian status, and nothing in the platform reads its name to decide anything.
 */
final readonly class ContactTag
{
    public const int MAX_NAME_LENGTH = 64;

    private function __construct(
        public ContactTagId $id,
        public string $name,
        public string $canonical,
        public ?AccountId $createdBy,
        public DateTimeImmutable $createdAt,
    ) {}

    /** @throws InvalidContactInput */
    public static function create(ContactTagId $id, string $name, ?AccountId $createdBy, DateTimeImmutable $now): self
    {
        $clean = self::clean($name);

        return new self($id, $clean, self::canonical($clean), $createdBy, $now);
    }

    public static function reconstitute(ContactTagId $id, string $name, string $canonical, ?AccountId $createdBy, DateTimeImmutable $createdAt): self
    {
        return new self($id, $name, $canonical, $createdBy, $createdAt);
    }

    /** @throws InvalidContactInput */
    public function renamed(string $name): self
    {
        $clean = self::clean($name);

        return new self($this->id, $clean, self::canonical($clean), $this->createdBy, $this->createdAt);
    }

    /**
     * What tag names are compared as: lower-cased, so "Lead" and "lead" are one tag on both engines. Whitespace is
     * already collapsed by `clean`. This is deliberately NOT accent folding, and it is portable for ASCII and case only:
     * for non-ASCII text the database column's collation decides what is equal (MariaDB's `utf8mb4_unicode_ci` treats
     * "Café" and "Cafe" as one tag, PostgreSQL as two). CrmCollationTest pins that difference.
     */
    public static function canonical(string $cleanName): string
    {
        return mb_strtolower($cleanName);
    }

    /** @throws InvalidContactInput */
    public static function clean(string $name): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($clean === '' || mb_strlen($clean) > self::MAX_NAME_LENGTH || preg_match('/[\p{C}]/u', $clean) === 1) {
            throw new InvalidContactInput('name', 'A tag name is 1 to 64 characters, with no control characters.');
        }

        return $clean;
    }
}
