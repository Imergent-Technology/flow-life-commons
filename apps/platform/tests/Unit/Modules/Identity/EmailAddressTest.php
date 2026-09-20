<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;

it('keeps the presented form and derives a lowercase canonical form', function () {
    $email = EmailAddress::fromString('Ada.Lovelace@Example.ORG');

    expect($email->value)->toBe('Ada.Lovelace@Example.ORG')
        ->and($email->canonical)->toBe('ada.lovelace@example.org');
});

it('treats every case variant, and surrounding whitespace, as the same address', function (string $variant) {
    expect(EmailAddress::fromString($variant)->canonical)->toBe('person@example.org')
        ->and(EmailAddress::fromString($variant)->equals(EmailAddress::fromString('person@example.org')))->toBeTrue();
})->with(['person@example.org', 'Person@Example.org', 'PERSON@EXAMPLE.ORG', 'pErSoN@eXaMpLe.OrG', "  person@example.org\n"]);

it('does not apply mailbox-provider rewriting, which would merge distinct people', function () {
    $addresses = ['a.b@example.org', 'ab@example.org', 'a+b@example.org', 'a-b@example.org', 'a_b@example.org'];
    $canonical = array_map(fn (string $a): string => EmailAddress::fromString($a)->canonical, $addresses);

    expect($canonical)->toBe($addresses)
        ->and(array_unique($canonical))->toHaveCount(count($addresses));
});

it('is deterministic regardless of the process locale', function () {
    // strtolower is byte-wise ASCII in PHP 8; this pins that no locale-sensitive
    // function (mb_strtolower, ucfirst tricks) creeps in, e.g. Turkish dotless i.
    $previous = setlocale(LC_CTYPE, null);
    setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR', 'C');

    try {
        expect(EmailAddress::fromString('INFO@EXAMPLE.ORG')->canonical)->toBe('info@example.org');
    } finally {
        setlocale(LC_CTYPE, $previous === false ? 'C' : $previous);
    }
});

it('rejects addresses that could behave differently on the two engines or are not addresses', function (string $invalid) {
    EmailAddress::fromString($invalid);
})->with([
    'empty' => '',
    'blank' => '   ',
    'no at sign' => 'person.example.org',
    'no domain' => 'person@',
    'no local part' => '@example.org',
    'two at signs' => 'a@b@example.org',
    'inner space' => 'per son@example.org',
    'quoted local part with a space' => '"per son"@example.org',
    'non-ASCII local part (accent folding differs per engine)' => 'josé@example.org',
    'non-ASCII domain (must be punycode)' => 'person@exämple.org',
    'interior control character' => "per\x01son@example.org",
    'over 254 characters' => str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.org',
])->throws(InvalidEmailAddress::class);

it('accepts an internationalised domain given as punycode', function () {
    expect(EmailAddress::fromString('person@xn--exmple-cua.org')->canonical)->toBe('person@xn--exmple-cua.org');
});

it('does not consider different addresses equal', function () {
    expect(EmailAddress::fromString('a@example.org')->equals(EmailAddress::fromString('b@example.org')))->toBeFalse();
});
