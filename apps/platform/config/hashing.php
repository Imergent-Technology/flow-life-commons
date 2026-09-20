<?php

declare(strict_types=1);

/*
 * Password hashing (docs/adr/0022). bcrypt, because it is what every target host has; Laravel
 * rehashes on sign-in if the algorithm changes later, and the stored value is opaque to Identity.
 *
 * `limit` is the important line. bcrypt reads only the first 72 bytes of its input, so with no
 * limit a longer password is silently weakened to its first 72 bytes. With one, the hasher REFUSES
 * to hash a value over it (an InvalidArgumentException) instead of truncating. It is the last of
 * three lines of defence, behind the password policy and PasswordHasher, and it must equal
 * PlainPassword::MAX_BYTES (a test asserts that). Do not raise it, and do not work around it by
 * pre-hashing the password.
 *
 * `rounds` is the work factor; the test suite lowers it through BCRYPT_ROUNDS so hashing is fast.
 */
return [
    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'limit' => 72,
    ],
];
