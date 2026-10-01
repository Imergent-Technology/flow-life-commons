<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * The light profile information CRM holds ABOUT a Person: how Flow Life knows them and who they are affiliated with,
 * both free text. A Person's name is Identity's, not stored here; Membership, Account and Volunteer status are not
 * here either (ADR 0034). The row is created by the first CRM write for a Person and may be entirely empty.
 */
final readonly class ContactProfile
{
    public const int MAX_HOW_WE_KNOW_LENGTH = 2000;

    public const int MAX_AFFILIATION_LENGTH = 255;

    private function __construct(
        public PersonId $personId,
        public ?string $howWeKnow,
        public ?string $affiliation,
        public ?AccountId $updatedBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function reconstitute(
        PersonId $personId,
        ?string $howWeKnow,
        ?string $affiliation,
        ?AccountId $updatedBy,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($personId, $howWeKnow, $affiliation, $updatedBy, $createdAt, $updatedAt);
    }

    /**
     * Applies only the fields the caller sent: a field absent from `$changes` is left alone, a field set to null (or
     * blank) is cleared.
     *
     * @param  array{how_we_know?: ?string, affiliation?: ?string}  $changes
     *
     * @throws InvalidContactInput
     */
    public function with(array $changes, ?AccountId $by, DateTimeImmutable $now): self
    {
        $how = array_key_exists('how_we_know', $changes)
            ? self::text($changes['how_we_know'], self::MAX_HOW_WE_KNOW_LENGTH, 'how_we_know')
            : $this->howWeKnow;
        $affiliation = array_key_exists('affiliation', $changes)
            ? self::text($changes['affiliation'], self::MAX_AFFILIATION_LENGTH, 'affiliation')
            : $this->affiliation;

        return new self($this->personId, $how, $affiliation, $by, $this->createdAt, $now);
    }

    private static function text(?string $value, int $max, string $field): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidContactInput($field, "That is limited to {$max} characters.");
        }

        return $value;
    }
}
