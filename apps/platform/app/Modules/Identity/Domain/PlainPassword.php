<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use Normalizer;

/**
 * A password as a person typed it, brought to the ONE form in which it is ever hashed or checked:
 * Unicode NFC, with nothing else done to it (no trimming, no case folding, no truncation).
 *
 * Every path that establishes or verifies an Account's password goes through this class, so the
 * bytes checked at sign-in are by construction the bytes that were hashed when the password was set.
 * Two spellings of the same text (a precomposed "é" and an "e" plus a combining accent) are the same
 * password on every path. Email addresses are a different thing with different rules (EmailAddress);
 * they must never be passed through here.
 *
 * The policy that lives here is the part that is a pure property of the text: a minimum length in
 * Unicode code points, and a maximum in BYTES. The byte ceiling is the bcrypt limit (72): bcrypt
 * ignores everything after it, so a longer password would be silently weakened to its first 72 bytes.
 * That limit is an acknowledged consequence of hosting portability (bcrypt is what every target
 * host has), not a design goal; it is refused, never truncated and never pre-hashed around. There
 * are deliberately NO composition rules: no required digits, cases or symbols, and spaces are fine.
 *
 * Whether a password is known to have been breached is not a property of the text and needs outside
 * knowledge, so it is the Application layer's question (PasswordPolicy), not this class's.
 *
 * The plaintext must never reach a log, an audit event, an error message or a serialised value.
 * There is no __toString, print_r and var_dump show a placeholder, json_encode shows nothing, and
 * serialising it throws. (PHP cannot hide a private property from reflection or var_export, so this is
 * defence in depth for the routes a logger or error page takes, not a guarantee against a debugger.)
 */
final readonly class PlainPassword
{
    /** Unicode code points, counted after normalisation. */
    public const int MIN_CODE_POINTS = 15;

    /** UTF-8 bytes, after normalisation: bcrypt's limit. Config `hashing.bcrypt.limit` must equal this. */
    public const int MAX_BYTES = 72;

    private function __construct(
        private string $value,
        private bool $wellFormed,
    ) {}

    /**
     * Input that is not well-formed UTF-8 cannot be normalised, so it is kept as it came and marked:
     * it fails the policy and can never verify, rather than being repaired into something else.
     */
    public static function fromInput(#[\SensitiveParameter] string $input): self
    {
        if (! mb_check_encoding($input, 'UTF-8')) {
            return new self($input, false);
        }

        /** @var string|false $normalised */
        $normalised = Normalizer::normalize($input, Normalizer::FORM_C);

        return $normalised === false ? new self($input, false) : new self($normalised, true);
    }

    /**
     * What is wrong with this text, if anything. Empty means acceptable as far as the text alone
     * can tell. Encoding is judged first because nothing else about malformed text is meaningful.
     *
     * @return list<PasswordViolation>
     */
    public function violations(): array
    {
        if (! $this->wellFormed) {
            return [PasswordViolation::NotUtf8];
        }

        $violations = [];
        if (str_contains($this->value, "\0")) {
            // bcrypt stops reading at a NUL byte, which would silently shorten the password.
            $violations[] = PasswordViolation::ContainsNul;
        }
        if (mb_strlen($this->value, 'UTF-8') < self::MIN_CODE_POINTS) {
            $violations[] = PasswordViolation::TooShort;
        }
        if (strlen($this->value) > self::MAX_BYTES) {
            $violations[] = PasswordViolation::TooLong;
        }

        return $violations;
    }

    /**
     * Whether this text can be hashed, or checked against a hash, without any part of it being
     * silently ignored. False for anything the policy would refuse for a reason that makes hashing
     * unsafe. (Being short is a policy matter, not a hashing hazard: an old, short password must
     * still be checkable so that its owner can sign in.)
     */
    public function isHashable(): bool
    {
        return $this->wellFormed && ! str_contains($this->value, "\0") && strlen($this->value) <= self::MAX_BYTES;
    }

    public function codePoints(): int
    {
        return $this->wellFormed ? mb_strlen($this->value, 'UTF-8') : 0;
    }

    public function byteLength(): int
    {
        return strlen($this->value);
    }

    /** The same password after normalisation, compared without leaking where it first differs. */
    public function equals(self $other): bool
    {
        return $this->wellFormed && $other->wellFormed && hash_equals($this->value, $other->value);
    }

    /** The normalised bytes, for the two places that must have them: hashing and the breach check. */
    public function reveal(): string
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A password must never be serialised.');
    }
}
