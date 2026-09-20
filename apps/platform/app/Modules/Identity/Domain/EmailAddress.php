<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * An email address as an Account's mutable login identifier (ADR 0015).
 *
 * `value` is presentation data, kept as entered (trimmed). `canonical` is the sole key
 * for lookup and uniqueness: it is deterministic and computed here, in application code,
 * so MariaDB and PostgreSQL behave identically whatever their collations do.
 *
 * Canonicalisation is deliberately just "trim, then lowercase". No provider-specific
 * rewriting (dots, +tags) is done: those rules belong to the mailbox provider, and
 * guessing them would merge distinct people.
 *
 * Only printable ASCII is accepted (internationalised domains must be given as
 * punycode). That restriction is what keeps the two engines identical: MariaDB's
 * utf8mb4_unicode_ci also folds accents, so `josé@x` and `jose@x` would collide there
 * and not on PostgreSQL. Lowercasing ASCII is unambiguous on both.
 */
final readonly class EmailAddress
{
    /** RFC 5321 path limit; also the width of the columns that store it. */
    public const int MAX_LENGTH = 254;

    private function __construct(
        public string $value,
        public string $canonical,
    ) {}

    public static function fromString(string $email): self
    {
        $trimmed = trim($email);

        if ($trimmed === ''
            || strlen($trimmed) > self::MAX_LENGTH
            || preg_match('/^[\x21-\x7E]+$/D', $trimmed) !== 1
            || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidEmailAddress('The email address is not valid.');
        }

        return new self($trimmed, strtolower($trimmed));
    }

    /** Same address regardless of case: equality is on the canonical form. */
    public function equals(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }
}
