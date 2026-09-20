<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\InvalidRecoveryCode;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Shared\Domain\AccountId;

it('is 16 characters from a 32-character alphabet, shown in four groups', function () {
    $code = RecoveryCode::generate();

    expect($code->formatted())->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/D');
});

it('is random: a thousand codes do not repeat, and use the whole alphabet', function () {
    $seen = [];
    $characters = [];
    for ($i = 0; $i < 1000; $i++) {
        $formatted = RecoveryCode::generate()->formatted();
        $seen[$formatted] = true;
        foreach (str_split(str_replace('-', '', $formatted)) as $c) {
            $characters[$c] = true;
        }
    }

    expect($seen)->toHaveCount(1000)->and($characters)->toHaveCount(32);
});

it('is read back the way people type it: case, hyphens, spaces and look-alikes are forgiven', function () {
    $account = AccountId::generate();
    $code = RecoveryCode::fromPresented('ABCD-EFGH-JKMN-PQRS');

    foreach (['abcd-efgh-jkmn-pqrs', 'ABCDEFGHJKMNPQRS', ' abcd efgh  jkmn pqrs ', 'ABCD-EFGH-JKMN-PQRS'] as $typed) {
        expect(RecoveryCode::fromPresented($typed)->digest($account))->toBe($code->digest($account));
    }
    // O reads as 0, and I and L as 1, as in Crockford's base32.
    expect(RecoveryCode::fromPresented('OOOO-IIII-LLLL-0000')->digest($account))
        ->toBe(RecoveryCode::fromPresented('0000-1111-1111-0000')->digest($account));
});

it('refuses anything that is not the shape of a code', function (string $typed) {
    RecoveryCode::fromPresented($typed);
})->with([
    'too short' => 'ABCD-EFGH',
    'too long' => 'ABCD-EFGH-JKMN-PQRS-TVWX',
    'a letter outside the alphabet' => 'ABCD-EFGH-JKMN-PQRU',
    'punctuation' => 'ABCD-EFGH-JKMN-PQR!',
    'empty' => '',
])->throws(InvalidRecoveryCode::class);

it('stores a one-way digest bound to the Account, never the code', function () {
    $code = RecoveryCode::generate();
    $one = AccountId::generate();
    $two = AccountId::generate();

    expect($code->digest($one))->toMatch('/^[0-9a-f]{64}$/D')
        ->and($code->digest($one))->toBe($code->digest($one))
        // The same text is a different digest for someone else, so it can never be looked up for them.
        ->and($code->digest($one))->not->toBe($code->digest($two))
        ->and($code->digest($one))->not->toContain(str_replace('-', '', $code->formatted()));
});

it('keeps itself out of dumps and logs', function () {
    $code = RecoveryCode::generate();

    expect(print_r($code, true))->not->toContain(str_replace('-', '', $code->formatted()))
        ->and(var_export($code, true))->not->toContain($code->formatted());
});
