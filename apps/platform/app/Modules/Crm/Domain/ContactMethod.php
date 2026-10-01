<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * An email address or phone number a Guardian has recorded for a Person. Not unique across Persons (a shared household
 * address is legitimate); the Person-scoped uniqueness and the single-primary-per-kind rule live in the schema and are
 * serialised by the Person's profile row lock, not here.
 */
final readonly class ContactMethod
{
    public const int MAX_LABEL_LENGTH = 64;

    private function __construct(
        public ContactMethodId $id,
        public PersonId $personId,
        public ContactMethodKind $kind,
        public string $value,
        public string $searchValue,
        public ?string $label,
        public bool $isPrimary,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** @throws InvalidContactInput */
    public static function create(
        ContactMethodId $id,
        PersonId $personId,
        ContactMethodKind $kind,
        string $value,
        ?string $label,
        bool $isPrimary,
        DateTimeImmutable $now,
    ): self {
        $display = $kind->display($value);

        return new self($id, $personId, $kind, $display, $kind->searchValue($display), self::label($label), $isPrimary, $now, $now);
    }

    public static function reconstitute(
        ContactMethodId $id,
        PersonId $personId,
        ContactMethodKind $kind,
        string $value,
        string $searchValue,
        ?string $label,
        bool $isPrimary,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $personId, $kind, $value, $searchValue, $label, $isPrimary, $createdAt, $updatedAt);
    }

    /** @throws InvalidContactInput */
    public function withValue(string $value, DateTimeImmutable $now): self
    {
        $display = $this->kind->display($value);

        return new self($this->id, $this->personId, $this->kind, $display, $this->kind->searchValue($display), $this->label, $this->isPrimary, $this->createdAt, $now);
    }

    /** @throws InvalidContactInput */
    public function withLabel(?string $label, DateTimeImmutable $now): self
    {
        return new self($this->id, $this->personId, $this->kind, $this->value, $this->searchValue, self::label($label), $this->isPrimary, $this->createdAt, $now);
    }

    public function withPrimary(bool $isPrimary, DateTimeImmutable $now): self
    {
        return new self($this->id, $this->personId, $this->kind, $this->value, $this->searchValue, $this->label, $isPrimary, $this->createdAt, $now);
    }

    /** @throws InvalidContactInput */
    private static function label(?string $label): ?string
    {
        $label = $label === null ? '' : trim($label);
        if ($label === '') {
            return null;
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH || preg_match('/[\p{C}]/u', $label) === 1) {
            throw new InvalidContactInput('label', 'A label is up to 64 characters, with no control characters.');
        }

        return $label;
    }
}
