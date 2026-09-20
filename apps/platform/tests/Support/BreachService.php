<?php

declare(strict_types=1);

namespace Tests\Support;

/** What the Pwned Passwords range service says, for tests that fake it with Http::fake(). */
final class BreachService
{
    public const string HOST = 'api.pwnedpasswords.com';

    /**
     * The service's answer for the password's prefix: its own suffix with a count, plus an unrelated
     * entry and a padding row (count zero), as the real service returns.
     */
    public static function answer(string $password, int $count = 12345): string
    {
        $hash = strtoupper(sha1($password));

        return implode("\r\n", [
            '0018A45C4D1DEF81644B54AB7F969B88D65:1',
            substr($hash, 5).':'.$count,
            'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:0',
        ]);
    }

    /** An answer that does NOT contain the password: what the service says for a clean one. */
    public static function cleanAnswer(): string
    {
        return "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\nFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:0";
    }
}
