<?php

declare(strict_types=1);

use App\Modules\Identity\Application\CompromisedPasswordCheckUnavailable;
use App\Modules\Identity\Application\PasswordHasher;
use App\Modules\Identity\Application\PasswordPolicy;
use App\Modules\Identity\Application\PasswordRejected;
use App\Modules\Identity\Domain\PasswordViolation;
use App\Modules\Identity\Domain\PlainPassword;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CountingHasher;
use Tests\Support\Passwords;

/** @return list<string> the reason classes the policy refuses $text with, or [] when accepted */
function refusedWith(string $text): array
{
    try {
        app(PasswordPolicy::class)->assertAcceptable(PlainPassword::fromInput($text));
    } catch (PasswordRejected $e) {
        return array_map(fn (PasswordViolation $v): string => $v->value, $e->violations);
    }

    return [];
}

it('refuses 14 characters and accepts 15', function () {
    Passwords::breached();

    expect(refusedWith(str_repeat('x', 14)))->toBe(['too_short'])
        ->and(refusedWith(str_repeat('x', 15)))->toBe([]);
});

it('accepts spaces and asks for no composition', function () {
    Passwords::breached();

    expect(refusedWith('spaces are welcome here'))->toBe([])
        ->and(refusedWith('nothingbutlowercaseletters'))->toBe([]);
});

it('has a stable byte boundary at 72, measured after normalisation', function () {
    Passwords::breached();

    expect(refusedWith(str_repeat('a', 72)))->toBe([])
        ->and(refusedWith(str_repeat('a', 73)))->toBe(['too_long'])
        ->and(refusedWith(Passwords::decomposed(str_repeat('é', 36))))->toBe([])
        ->and(refusedWith(Passwords::decomposed(str_repeat('é', 37))))->toBe(['too_long']);
});

it('rejects a compromised password', function () {
    Passwords::breached('correct horse battery staple');

    expect(refusedWith('correct horse battery staple'))->toBe(['compromised'])
        ->and(refusedWith('correct horse battery staples'))->toBe([]);
});

it('checks the normalised form, so a differently spelled breached password is still caught', function () {
    Passwords::breached('crème brûlée à la façon');

    expect(refusedWith(Passwords::decomposed('crème brûlée à la façon')))->toBe(['compromised']);
});

it('does not ask the breach service about a password it already refuses', function () {
    // Nothing leaves the machine, not even a hash prefix, for a password that is plainly unacceptable.
    $fake = Passwords::breached();

    refusedWith('too short');
    refusedWith(str_repeat('a', 73));
    refusedWith("not valid \xC3\x28 utf eight at all");
    refusedWith("has a nul \0 in it, long enough");

    expect($fake->checked)->toBe([]);

    refusedWith(Passwords::STRONG);

    expect($fake->checked)->toHaveCount(1);
});

it('does NOT treat "could not check" as "safe": an outage is a retryable failure, not an acceptance', function () {
    Passwords::checkerDown();

    expect(fn () => app(PasswordPolicy::class)->assertAcceptable(PlainPassword::fromInput(Passwords::STRONG)))
        ->toThrow(CompromisedPasswordCheckUnavailable::class);
});

it('still gives the offline reasons during an outage, because they need no network', function () {
    $fake = Passwords::checkerDown();

    expect(refusedWith('too short'))->toBe(['too_short'])
        ->and($fake->checked)->toBe([]);
});

it('refuses to hash a password over 72 bytes even if the policy is bypassed', function () {
    // Second line of defence: PasswordHasher, which every path uses.
    $over = PlainPassword::fromInput(str_repeat('a', 73));

    expect(fn () => app(PasswordHasher::class)->hash($over))->toThrow(LogicException::class)
        ->and(fn () => app(PasswordHasher::class)->hash(PlainPassword::fromInput("nul \0 in the middle of it")))->toThrow(LogicException::class);
});

it('never verifies an over-long candidate, because bcrypt would compare only its first 72 bytes', function () {
    $hasher = app(PasswordHasher::class);
    $stored = $hasher->hash(PlainPassword::fromInput(str_repeat('a', 72)));

    expect($hasher->matches(PlainPassword::fromInput(str_repeat('a', 72)), $stored))->toBeTrue()
        // password_verify alone would say yes to this: it ignores everything after byte 72.
        ->and(password_verify(str_repeat('a', 73), $stored))->toBeTrue()
        ->and($hasher->matches(PlainPassword::fromInput(str_repeat('a', 73)), $stored))->toBeFalse();
});

it('has a framework hasher that itself refuses to truncate, should everything above be bypassed', function () {
    // Third line of defence: config/hashing.php. Skipping the policy AND PasswordHasher still cannot
    // silently weaken a password to its first 72 bytes.
    expect(fn () => Hash::make(str_repeat('a', 73)))->toThrow(InvalidArgumentException::class)
        ->and(Hash::check('a', Hash::make(str_repeat('a', 72))))->toBeFalse();
});

it('keeps the framework hasher limit equal to the policy limit', function () {
    expect(config('hashing.bcrypt.limit'))->toBe(PlainPassword::MAX_BYTES)
        ->and(config('hashing.driver'))->toBe('bcrypt');
});

it('does the same amount of hashing work whether or not there is a hash to check against', function () {
    $counting = new CountingHasher(app(Hasher::class));
    app()->instance(Hasher::class, $counting);
    $hasher = app(PasswordHasher::class);

    $hasher->matches(PlainPassword::fromInput(Passwords::STRONG), $hasher->decoyHash());

    expect($counting->checks)->toBe(1);
});

it('does no hashing work at all for a password refused before bcrypt', function () {
    // "Rejected before bcrypt": the policy refuses first, so no hash is computed for it.
    $counting = new CountingHasher(app(Hasher::class));
    app()->instance(Hasher::class, $counting);
    Passwords::breached();

    refusedWith(str_repeat('a', 73));

    expect($counting->makes)->toBe(0)->and($counting->checks)->toBe(0);
});
