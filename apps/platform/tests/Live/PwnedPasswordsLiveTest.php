<?php

declare(strict_types=1);

use App\Modules\Identity\Application\CompromisedPasswordCheckUnavailable;
use App\Modules\Identity\Domain\PlainPassword;
use App\Modules\Identity\Infrastructure\Password\PwnedPasswordsRange;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\SkippedWithMessageException;

/*
 * MANUAL, NETWORK-DEPENDENT SMOKE TEST of the real Pwned Passwords range service.
 *
 * It is deliberately NOT part of any test suite (see phpunit.xml): ./flow test, ./flow check, CI and
 * the browser e2e never run it, and never need the public network. Everything the platform does with
 * the service, including every way it can fail, is proved deterministically against a fake in
 * tests/Feature/Modules/Identity/Credentials (PwnedPasswordsRangeTest, BreachCheckEndpointsTest).
 * What only the real service can tell us is whether the contract those fakes encode still holds: that
 * it answers, in the format the adapter parses, and that the adapter and service agree on a known
 * breached password. Run it by hand, for example after changing the adapter or when the service is
 * suspected to have changed:
 *
 *     ./flow test backend -- tests/Live
 *
 * With no network it SKIPS, saying so, rather than failing: an offline machine is not a regression.
 * It fails only when the service answered and the answer was wrong.
 */

beforeEach(function () {
    // The one place a test may reach out: TestCase forbids stray requests everywhere else.
    Http::allowStrayRequests();
});

/**
 * Asks the live service about a password. Null means it could not be reached or did not answer usefully,
 * with the reason in $why, so the caller can SKIP (distinctly from failing).
 */
function probeLiveService(string $password, ?string &$why): ?bool
{
    try {
        return app(PwnedPasswordsRange::class)->contains(PlainPassword::fromInput($password));
    } catch (CompromisedPasswordCheckUnavailable $e) {
        $why = '[live network] SKIPPED, not failed: the public Pwned Passwords service could not be reached or did not answer usefully ('.$e->getMessage().'). This manual smoke test needs outbound HTTPS.';

        return null;
    }
}

it('[live network] finds a well-known breached password through the real service', function () {
    // Present in the public corpus tens of thousands of times; 16 characters, so it passes the offline rules.
    $found = probeLiveService('password12345678', $why);
    if ($found === null) {
        throw new SkippedWithMessageException((string) $why);
    }

    expect($found)->toBeTrue();
});

it('[live network] does not flag a passphrase nobody has ever used', function () {
    $found = probeLiveService('live smoke '.bin2hex(random_bytes(16)).' passphrase', $why);
    if ($found === null) {
        throw new SkippedWithMessageException((string) $why);
    }

    expect($found)->toBeFalse();
});
