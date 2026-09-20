<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;

/**
 * A single-use recovery code: 16 characters from a 32-character alphabet, so 80 bits from the
 * system's CSPRNG, shown as `ABCD-EFGH-JKMN-PQRS`.
 *
 * What is stored is only `digest()`. With 80 bits of entropy a fast one-way hash cannot be searched,
 * so a stolen table gives nothing usable, and a fast hash is what lets consumption be a single
 * atomic, indexed conditional UPDATE. The digest is bound to the Account (and to this scheme's
 * version) so that identical text can never be looked up for someone else. It deliberately does NOT
 * depend on the application key: rotating that key must not silently kill every recovery code.
 *
 * The alphabet leaves out I, L, O and U, and typed input is normalised the way Crockford's base32
 * is (case, hyphens, spaces, and the look-alikes O, I and L), so a code read off a printout works.
 */
final readonly class RecoveryCode
{
    public const int LENGTH = 16;

    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct(private string $normalised) {}

    public static function generate(): self
    {
        // 16 characters x 5 bits = 80 bits = 10 bytes; 32 is a power of two, so there is no modulo bias.
        $bits = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $code = '';
        foreach (str_split($bits, 5) as $group) {
            $code .= self::ALPHABET[(int) bindec($group)];
        }

        return new self($code);
    }

    /**
     * @throws InvalidRecoveryCode not a well-formed code (so it cannot be one that was issued)
     */
    public static function fromPresented(#[\SensitiveParameter] string $presented): self
    {
        $normalised = strtr(strtoupper((string) preg_replace('/[\s-]+/', '', $presented)), ['O' => '0', 'I' => '1', 'L' => '1']);
        if (preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/D', $normalised) !== 1) {
            throw new InvalidRecoveryCode('Not a recovery code.');
        }

        return new self($normalised);
    }

    /** What is stored, and all that is: lowercase hex SHA-256. */
    public function digest(AccountId $account): string
    {
        return hash('sha256', 'flowlife:recovery-code:v1:'.$account->value.':'.$this->normalised);
    }

    /** For showing once: groups of four. */
    public function formatted(): string
    {
        return implode('-', str_split($this->normalised, 4));
    }

    /** @return array<string, string> a code must not reach a dump, a log or an exception trace by accident */
    public function __debugInfo(): array
    {
        return ['code' => '[redacted]'];
    }
}
