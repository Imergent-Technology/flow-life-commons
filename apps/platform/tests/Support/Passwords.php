<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Application\CompromisedPasswords;

/** Passwords and breach-check set-up shared by the credential tests. */
final class Passwords
{
    /** Acceptable to the policy (15+ code points, under 72 bytes) and not in any fake breach list. */
    public const string STRONG = 'a long enough passphrase';

    public const string OTHER = 'another perfectly fine passphrase';

    /** Replaces the breach check for this test with a fake that reports these passwords as breached. */
    public static function breached(string ...$passwords): FakeBreachCheck
    {
        $fake = new FakeBreachCheck(array_values($passwords));
        app()->instance(CompromisedPasswords::class, $fake);

        return $fake;
    }

    /** Replaces the breach check with one whose service is down. */
    public static function checkerDown(): FakeBreachCheck
    {
        $fake = new FakeBreachCheck([], true);
        app()->instance(CompromisedPasswords::class, $fake);

        return $fake;
    }

    /** "é" as two code points (e + combining acute): the same text as one precomposed code point. */
    public static function decomposed(string $text): string
    {
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        assert(is_string($decomposed));

        return $decomposed;
    }
}
