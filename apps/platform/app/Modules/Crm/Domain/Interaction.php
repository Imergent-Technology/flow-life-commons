<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * A Guardian's note about a Person, or a record of a conversation with them: CRM business data, not a security event
 * (ADR 0034). Phase 1 notes are visible to everyone who may view CRM data, so they are written as though the Person could
 * one day ask to read them.
 *
 * `occurredAt` is when the contact happened (what the list is ordered by); `createdAt` is when it was recorded. The author
 * and the last editor are Persons, held as provenance with no foreign key (ADR 0021); there is no edit history, so who last
 * changed it is the whole of the trail. The row is changed in place and removed outright.
 */
final readonly class Interaction
{
    public const int MAX_BODY_LENGTH = 5000;

    /** How far ahead of the server clock an `occurred_at` may be, so a Guardian's slightly fast clock is not an error. */
    public const int CLOCK_SKEW_SECONDS = 300;

    private function __construct(
        public InteractionId $id,
        public PersonId $personId,
        public InteractionKind $kind,
        public string $body,
        public DateTimeImmutable $occurredAt,
        public PersonId $authorPersonId,
        public ?PersonId $updatedByPersonId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** @throws InvalidContactInput */
    public static function record(
        InteractionId $id,
        PersonId $personId,
        InteractionKind $kind,
        string $body,
        ?DateTimeImmutable $occurredAt,
        PersonId $author,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $personId, $kind, self::body($body), self::occurredAt($occurredAt ?? $now, $now), $author, null, $now, $now);
    }

    public static function reconstitute(
        InteractionId $id,
        PersonId $personId,
        InteractionKind $kind,
        string $body,
        DateTimeImmutable $occurredAt,
        PersonId $authorPersonId,
        ?PersonId $updatedByPersonId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $personId, $kind, $body, $occurredAt, $authorPersonId, $updatedByPersonId, $createdAt, $updatedAt);
    }

    /**
     * Applies only the fields sent. The author and the Person never change.
     *
     * @param  array{kind?: InteractionKind, body?: string, occurred_at?: DateTimeImmutable}  $changes
     *
     * @throws InvalidContactInput
     */
    public function with(array $changes, PersonId $by, DateTimeImmutable $now): self
    {
        return new self(
            $this->id,
            $this->personId,
            $changes['kind'] ?? $this->kind,
            array_key_exists('body', $changes) ? self::body($changes['body']) : $this->body,
            array_key_exists('occurred_at', $changes) ? self::occurredAt($changes['occurred_at'], $now) : $this->occurredAt,
            $this->authorPersonId,
            $by,
            $this->createdAt,
            $now,
        );
    }

    /** @throws InvalidContactInput */
    private static function body(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body)); // CRLF, then a lone CR, become LF
        if ($body === '') {
            throw new InvalidContactInput('body', 'Write something to record.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidContactInput('body', 'That is limited to '.self::MAX_BODY_LENGTH.' characters.');
        }
        // Control characters only (Cc), other than LF and TAB. Formatting characters (Cf: ZWNJ, ZWJ, direction marks) are
        // ordinary text in many languages and in emoji sequences, so they are allowed.
        if (preg_match('/[^\P{Cc}\n\t]/u', $body) === 1) {
            throw new InvalidContactInput('body', 'That may not contain control characters.');
        }

        return $body;
    }

    /** @throws InvalidContactInput */
    private static function occurredAt(DateTimeImmutable $occurredAt, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($occurredAt->getTimestamp() > $now->getTimestamp() + self::CLOCK_SKEW_SECONDS) {
            throw new InvalidContactInput('occurred_at', 'An interaction cannot have happened in the future.');
        }

        return $occurredAt;
    }
}
