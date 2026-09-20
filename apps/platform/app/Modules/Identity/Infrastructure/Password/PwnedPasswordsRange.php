<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Password;

use App\Modules\Identity\Application\CompromisedPasswordCheckUnavailable;
use App\Modules\Identity\Application\CompromisedPasswords;
use App\Modules\Identity\Domain\PlainPassword;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Asks the Pwned Passwords range API whether a password appears in public breaches, using
 * k-anonymity: the password is SHA-1 hashed locally and ONLY THE FIRST FIVE HEX CHARACTERS of that
 * hash leave this machine. The service answers with every suffix that shares the prefix, and the
 * comparison is done here. Neither the password nor its full hash is ever sent, and the request is
 * padded (`Add-Padding`) so the size of the answer does not hint at the prefix's popularity.
 *
 * **This deliberately does not use Laravel's `Password::uncompromised()`.** That facility fails OPEN:
 * on a connection error or any non-2xx answer its verifier reports the exception, treats the empty
 * body as "nothing found", and the password passes. An outage would silently switch the policy off.
 * Here every way of not getting a well-formed answer throws CompromisedPasswordCheckUnavailable, so the
 * caller asks the user to retry instead. (Nothing else is borrowed from the framework's verifier
 * either, since its hashing and lookup are the only useful parts and are a few lines.)
 *
 * A response is accepted only if every non-blank line is a `SUFFIX:COUNT` pair (35 upper-case hex
 * characters, a count) and there is at least one. A real answer always has many entries, because of
 * the padding. Padding rows have a count of zero and are ignored: only a positive count is a hit.
 *
 * Infrastructure only: Domain and Application never make a network call. It is replaceable behind
 * CompromisedPasswords, for example by a local blocklist, without touching a use case.
 */
final readonly class PwnedPasswordsRange implements CompromisedPasswords
{
    public function __construct(
        private Http $http,
        private Config $config,
    ) {}

    public function contains(PlainPassword $password): bool
    {
        $hash = strtoupper(sha1($password->reveal()));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $seconds = max(1, $this->config->integer('identity.password.compromised_check.timeout_seconds'));
        $base = rtrim($this->config->string('identity.password.compromised_check.url'), '/');

        try {
            $response = $this->http
                ->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'flowlife-platform-password-policy'])
                ->connectTimeout($seconds)
                ->timeout($seconds)
                ->get("{$base}/range/{$prefix}");
        } catch (ConnectionException $e) {
            throw new CompromisedPasswordCheckUnavailable('The breached-password service could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            throw new CompromisedPasswordCheckUnavailable('The breached-password service answered with status '.$response->status().'.');
        }

        $entries = 0;
        $found = false;
        foreach (preg_split('/\r\n|\r|\n/', trim($response->body())) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([0-9A-F]{35}):(\d+)$/D', $line, $entry) !== 1) {
                throw new CompromisedPasswordCheckUnavailable('The breached-password service sent an answer that could not be understood.');
            }
            $entries++;
            if ((int) $entry[2] > 0 && hash_equals($suffix, $entry[1])) {
                $found = true;
            }
        }

        if ($entries === 0) {
            throw new CompromisedPasswordCheckUnavailable('The breached-password service sent an empty answer.');
        }

        return $found;
    }
}
