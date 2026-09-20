<?php

declare(strict_types=1);

use App\Modules\Identity\Application\CompromisedPasswordCheckUnavailable;
use App\Modules\Identity\Application\CompromisedPasswords;
use App\Modules\Identity\Domain\PlainPassword;
use App\Modules\Identity\Infrastructure\Password\NoCompromisedPasswordCheck;
use App\Modules\Identity\Infrastructure\Password\PwnedPasswordsRange;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\Support\BreachService;

/*
 * The remote breach check (PwnedPasswordsRange), against a faked service. Nothing here reaches the
 * network: TestCase forbids stray requests.
 */

const PWNED_PASSWORD = 'correct horse battery staple';

/** The service's answer for a password's prefix: its own suffix with a count, plus filler and padding. */
function rangeAnswer(string $password, int $count = 12345): string
{
    return BreachService::answer($password, $count);
}

function checker(): PwnedPasswordsRange
{
    return app(PwnedPasswordsRange::class);
}

it('finds a breached password and lets a clean one through', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(rangeAnswer(PWNED_PASSWORD))]);

    expect(checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD)))->toBeTrue();

    // A different password shares no suffix with the answer, whatever the prefix.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(rangeAnswer(PWNED_PASSWORD))]);
    expect(checker()->contains(PlainPassword::fromInput('an entirely different passphrase')))->toBeFalse();
});

it('sends only the first five characters of the SHA-1, never the password or the full hash', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(rangeAnswer(PWNED_PASSWORD))]);

    checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD));

    $hash = strtoupper(sha1(PWNED_PASSWORD));
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($hash): bool {
        $everything = $request->url().json_encode($request->headers()).$request->body();

        return $request->method() === 'GET'
            && $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
            // Padding is requested, so the size of the answer does not hint at the prefix.
            && $request->hasHeader('Add-Padding', 'true')
            && ! str_contains($everything, PWNED_PASSWORD)
            && ! str_contains(strtolower($everything), rawurlencode(PWNED_PASSWORD))
            // Not even the rest of the hash: five characters is all that leaves the machine.
            && ! str_contains($everything, substr($hash, 5, 10))
            && ! str_contains(strtolower($everything), strtolower(substr($hash, 5, 10)));
    });
});

it('checks the normalised form of the password', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(rangeAnswer('crème brûlée à la façon'))]);

    $decomposed = Normalizer::normalize('crème brûlée à la façon', Normalizer::FORM_D);
    assert(is_string($decomposed));

    expect(checker()->contains(PlainPassword::fromInput($decomposed)))->toBeTrue();
});

it('ignores padding rows, which have a count of zero', function () {
    // The service pads answers with fake entries. One that happens to equal our suffix is not a hit.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(rangeAnswer(PWNED_PASSWORD, 0))]);

    expect(checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD)))->toBeFalse();
});

it('fails closed when the service answers with an error status', function (int $status) {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('nope', $status)]);

    expect(fn () => checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD)))
        ->toThrow(CompromisedPasswordCheckUnavailable::class);
})->with([400, 404, 429, 500, 502, 503]);

it('fails closed on an error status even when the body looks like a perfectly good answer', function () {
    // The status check is its own guard. Without it, a well-formed body on a 5xx (a proxy's canned
    // page, a truncated response) would be read as "not found" and the password waved through.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(rangeAnswer('an entirely different passphrase'), 503)]);

    expect(fn () => checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD)))
        ->toThrow(CompromisedPasswordCheckUnavailable::class);
});

it('fails closed when the service cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    expect(fn () => checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD)))
        ->toThrow(CompromisedPasswordCheckUnavailable::class);
});

it('fails closed on an empty answer, a malformed one, or one with a single bad line', function (string $body) {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response($body)]);

    expect(fn () => checker()->contains(PlainPassword::fromInput(PWNED_PASSWORD)))
        ->toThrow(CompromisedPasswordCheckUnavailable::class);
})->with([
    'empty' => [''],
    'whitespace' => ["  \r\n "],
    'html error page' => ['<html><body>Bad gateway</body></html>'],
    'wrong shape' => ['not-a-suffix:12'],
    'one bad line among good ones' => ["0018A45C4D1DEF81644B54AB7F969B88D65:1\r\ngarbage\r\nFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:0"],
]);

it('is what the platform uses by default in production configuration, and switches off only where it is safe', function () {
    config(['identity.password.compromised_check.driver' => 'pwned_passwords']);
    expect(app(CompromisedPasswords::class))->toBeInstanceOf(PwnedPasswordsRange::class);

    config(['identity.password.compromised_check.driver' => 'none']);
    expect(app(CompromisedPasswords::class))->toBeInstanceOf(NoCompromisedPasswordCheck::class);

    // The one way to turn the check off is refused outside local and testing.
    app()->instance('env', 'production');
    expect(fn () => app(CompromisedPasswords::class))->toThrow(LogicException::class);

    config(['identity.password.compromised_check.driver' => 'something-else']);
    app()->instance('env', 'testing');
    expect(fn () => app(CompromisedPasswords::class))->toThrow(LogicException::class);
});

it('is not Laravel\'s own uncompromised() rule, because that one fails OPEN', function () {
    // Characterisation of the framework, and the reason this project has its own checker. With the
    // service down the framework's verifier swallows the error, sees an empty answer, and reports
    // the password as safe. If a Laravel upgrade ever changes this, this test says so.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 500)]);

    $validator = Validator::make(['password' => PWNED_PASSWORD], ['password' => Password::min(15)->uncompromised()]);

    expect($validator->passes())->toBeTrue('the framework rule is expected to pass a password it could not check');
});
