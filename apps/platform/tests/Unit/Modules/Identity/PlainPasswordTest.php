<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\PasswordViolation;
use App\Modules\Identity\Domain\PlainPassword;

/**
 * The violations of $text, as their reason classes, so a failure reads plainly.
 *
 * @return list<string>
 */
function violationsOf(string $text): array
{
    return array_map(fn (PasswordViolation $v): string => $v->value, PlainPassword::fromInput($text)->violations());
}

it('requires at least 15 Unicode code points', function () {
    expect(violationsOf(str_repeat('a', 14)))->toBe(['too_short'])
        ->and(violationsOf(str_repeat('a', 15)))->toBe([]);
});

it('counts code points, not bytes', function () {
    // 15 two-byte characters is 30 bytes but 15 code points: long enough, and under the byte limit.
    expect(violationsOf(str_repeat('é', 15)))->toBe([])
        // 14 of them is 28 bytes: many bytes, too few characters.
        ->and(violationsOf(str_repeat('é', 14)))->toBe(['too_short'])
        // Four-byte characters count once each.
        ->and(violationsOf(str_repeat('😀', 15)))->toBe([])
        ->and(violationsOf(str_repeat('😀', 14)))->toBe(['too_short']);
});

it('has no composition rules, and permits spaces and Unicode', function () {
    expect(violationsOf('all lowercase letters and spaces'))->toBe([])
        ->and(violationsOf(str_repeat(' ', 15)))->toBe([])
        ->and(violationsOf('ALLUPPERCASELETTERS'))->toBe([])
        ->and(violationsOf('1234567890123456'))->toBe([])
        ->and(violationsOf('日本語のパスフレーズですよこれは長い'))->toBe([]);
});

it('limits the password to 72 bytes, and refuses rather than truncates', function () {
    expect(violationsOf(str_repeat('a', 72)))->toBe([])
        ->and(violationsOf(str_repeat('a', 73)))->toBe(['too_long'])
        // The limit is in BYTES: 36 two-byte characters is exactly 72, 37 is over.
        ->and(violationsOf(str_repeat('é', 36)))->toBe([])
        ->and(violationsOf(str_repeat('é', 37)))->toBe(['too_long']);
});

it('normalises to NFC before judging length, so the limit applies to what would be hashed', function () {
    $decomposed = str_repeat("e\u{0301}", 36); // 108 bytes as typed, 72 once normalised

    expect(strlen($decomposed))->toBe(108)
        ->and(violationsOf($decomposed))->toBe([])
        ->and(PlainPassword::fromInput($decomposed)->byteLength())->toBe(72)
        ->and(violationsOf(str_repeat("e\u{0301}", 37)))->toBe(['too_long'])
        // 15 characters typed as 30 code points still count as 15.
        ->and(PlainPassword::fromInput(str_repeat("e\u{0301}", 15))->codePoints())->toBe(15);
});

it('treats two spellings of the same text as the same password', function () {
    $precomposed = 'crème brûlée à la façon';
    $decomposed = Normalizer::normalize($precomposed, Normalizer::FORM_D);
    assert(is_string($decomposed));

    expect($decomposed)->not->toBe($precomposed)
        ->and(PlainPassword::fromInput($decomposed)->equals(PlainPassword::fromInput($precomposed)))->toBeTrue()
        ->and(PlainPassword::fromInput($decomposed)->reveal())->toBe($precomposed)
        // Stable: normalising what is already normalised changes nothing.
        ->and(PlainPassword::fromInput(PlainPassword::fromInput($decomposed)->reveal())->reveal())->toBe($precomposed);
});

it('does no other transformation: spaces, case and edges are kept exactly', function () {
    $typed = "  Leading and trailing spaces\t ";

    expect(PlainPassword::fromInput($typed)->reveal())->toBe($typed);
});

it('does not consider different passwords equal', function () {
    expect(PlainPassword::fromInput('Correct horse battery')->equals(PlainPassword::fromInput('correct horse battery')))->toBeFalse();
});

it('refuses text that is not valid UTF-8, and text that would make bcrypt stop reading early', function () {
    expect(violationsOf("\xC3\x28 not valid utf eight at all"))->toBe(['not_utf8'])
        ->and(violationsOf("twenty characters long\0and then some"))->toBe(['contains_nul'])
        ->and(PlainPassword::fromInput("\xC3\x28")->isHashable())->toBeFalse()
        ->and(PlainPassword::fromInput("with a nul \0 inside")->isHashable())->toBeFalse();
});

it('is hashable up to the byte limit, and a short password stays checkable', function () {
    expect(PlainPassword::fromInput(str_repeat('a', 72))->isHashable())->toBeTrue()
        ->and(PlainPassword::fromInput(str_repeat('a', 73))->isHashable())->toBeFalse()
        // Too short for the policy, but a person with an old short password must still be able to sign in.
        ->and(PlainPassword::fromInput('short')->isHashable())->toBeTrue();
});

it('does not reveal itself to the ordinary ways of printing, dumping or serialising an object', function () {
    // Not a claim that PHP can hide a private property from everything (reflection, var_export and
    // a debugger's dumper read it): the guarantee is the routes a logger or an error page takes.
    $password = PlainPassword::fromInput('a secret passphrase here');

    expect(json_encode($password))->toBe('{}')
        ->and(print_r($password, true))->not->toContain('a secret passphrase here')
        ->and(fn () => serialize($password))->toThrow(LogicException::class);

    ob_start();
    var_dump($password);
    expect(ob_get_clean())->not->toContain('a secret passphrase here');
});
