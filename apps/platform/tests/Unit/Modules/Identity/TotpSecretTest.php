<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\InvalidTotpSecret;
use App\Modules\Identity\Domain\TotpSecret;

it('holds a base32 secret of at least 80 bits', function () {
    expect(TotpSecret::fromBase32('JBSWY3DPEHPK3PXP')->reveal())->toBe('JBSWY3DPEHPK3PXP');
});

it('refuses text that is not a base32 secret', function (string $text) {
    TotpSecret::fromBase32($text);
})->with([
    'lower case' => 'jbswy3dpehpk3pxp',
    'too short' => 'JBSWY3DP',
    'a character outside base32' => 'JBSWY3DPEHPK3PX1',
    'padding' => 'JBSWY3DPEHPK3PX=',
    'empty' => '',
])->throws(InvalidTotpSecret::class);

it('keeps itself out of dumps and logs', function () {
    $secret = TotpSecret::fromBase32('JBSWY3DPEHPK3PXP');

    expect(print_r($secret, true))->not->toContain('JBSWY3DPEHPK3PXP');
});
