<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AuthenticateAccount;
use App\Modules\Identity\Application\AuthenticationStatus;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Domain\EmailAddress;
use Illuminate\Contracts\Hashing\Hasher;
use Tests\Support\Console;
use Tests\Support\CountingHasher;
use Tests\Support\Identity;
use Tests\Support\Passwords;

/*
 * Login goes through the same normalisation boundary as every path that sets a password
 * (PlainPassword), so what is verified is exactly what would have been hashed.
 */

it('is unchanged for an ordinary ASCII password', function () {
    Identity::savedActiveAccount(password: 'correct horse battery staple');

    (new Console)->login('ada@example.org', 'correct horse battery staple')->assertOk();
    (new Console)->login('ada@example.org', 'correct horse battery stapl')->assertUnauthorized();
    (new Console)->login('ada@example.org', 'Correct horse battery staple')->assertUnauthorized();
});

it('signs in whichever way a Unicode password is spelled', function () {
    $precomposed = 'crème brûlée à la façon';
    Identity::savedActiveAccount(password: $precomposed);

    (new Console)->login('ada@example.org', $precomposed)->assertOk();
    (new Console)->login('ada@example.org', Passwords::decomposed($precomposed))->assertOk();
});

it('does not let a longer candidate match a stored 72-byte password by its first 72 bytes', function () {
    Identity::savedActiveAccount(password: str_repeat('a', 72));

    (new Console)->login('ada@example.org', str_repeat('a', 72))->assertOk();
    (new Console)->login('ada@example.org', str_repeat('a', 73))->assertUnauthorized();
    (new Console)->login('ada@example.org', str_repeat('a', 72).'anything at all')->assertUnauthorized();
});

it('does not let a candidate with a NUL byte match by what precedes it', function () {
    // Characterisation, and the reason for the rule: on the PHP we run, password_hash refuses a NUL
    // but password_verify quietly compares only what comes before it.
    $stored = password_hash(str_repeat('a', 20), PASSWORD_BCRYPT, ['cost' => 4]);
    expect(password_verify(str_repeat('a', 20)."\0anything", $stored))->toBeTrue()
        ->and(fn () => password_hash("has a \0 nul in it", PASSWORD_BCRYPT))->toThrow(ValueError::class);

    Identity::savedActiveAccount(password: str_repeat('a', 20));

    (new Console)->login('ada@example.org', str_repeat('a', 20))->assertOk();
    (new Console)->login('ada@example.org', str_repeat('a', 20)."\0 and then something else")->assertUnauthorized();
});

it('does not trim: a stored password without edge spaces is not matched with them', function () {
    Identity::savedActiveAccount(password: 'no spaces at the edges');

    (new Console)->login('ada@example.org', ' no spaces at the edges ')->assertUnauthorized();
});

it('still does exactly one password check on every path, so timing gives nothing away', function () {
    $counting = new CountingHasher(app(Hasher::class));
    app()->instance(Hasher::class, $counting);
    Identity::savedActiveAccount('active@example.org', 'correct horse battery staple');
    Identity::savedInvitedAccount('invited@example.org');
    $counting->checks = 0;

    $attempt = function (string $email, string $password) use ($counting): int {
        $counting->checks = 0;
        $result = app(AuthenticateAccount::class)(EmailAddress::fromString($email), $password, new ClientContext('127.0.0.1', 'test'));

        return $result->status === AuthenticationStatus::Authenticated || $result->status === AuthenticationStatus::Failed ? $counting->checks : -1;
    };

    expect($attempt('active@example.org', 'correct horse battery staple'))->toBe(1)
        ->and($attempt('active@example.org', 'wrong wrong wrong wrong'))->toBe(1)
        ->and($attempt('invited@example.org', 'wrong wrong wrong wrong'))->toBe(1)
        ->and($attempt('nobody@example.org', 'wrong wrong wrong wrong'))->toBe(1);
});
