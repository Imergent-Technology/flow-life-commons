<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An INDEPENDENT RFC 4226 / 6238 implementation for tests (HMAC-SHA1, 6 digits, 30-second step), so that
 * the platform's TOTP adapter is checked against something that is not the library it wraps. It is a test
 * double for an authenticator app: it lives here and nowhere in the application.
 *
 * `next()` returns a code that is valid NOW and has not been returned before for this secret, moving the
 * (frozen) test clock forward one step when it must, because the platform accepts each time step once.
 */
final class Totp
{
    public const int PERIOD = 30;

    /** The RFC 6238 test secret ("12345678901234567890"), base32. */
    public const string RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** A secret for tests that do not care which. */
    public const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** @var array<string, int> the last step handed out, per secret */
    private static array $last = [];

    public static function forget(): void
    {
        self::$last = [];
    }

    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    /** The code for the step containing `$at` (Unix seconds; default now). */
    public static function code(string $base32Secret, ?int $at = null): string
    {
        return self::forStep($base32Secret, self::step($at ?? Carbon::now()->getTimestamp()));
    }

    public static function forStep(string $base32Secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($base32Secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** A valid code for now that this secret has not been given before: moves the test clock a step if needed. */
    public static function next(string $base32Secret): string
    {
        $step = self::step(Carbon::now()->getTimestamp());
        $last = self::$last[$base32Secret] ?? null;
        if ($last !== null && $step <= $last) {
            Carbon::setTestNow(Carbon::createFromTimestampUTC(($last + 1) * self::PERIOD + 1));
            $step = $last + 1;
        }
        self::$last[$base32Secret] = $step;

        return self::forStep($base32Secret, $step);
    }

    /** RFC 4648 base32 (upper case, unpadded or padded). */
    private static function decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(rtrim($base32, '=')) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Not base32.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr((int) bindec($byte));
            }
        }

        return $bytes;
    }
}
