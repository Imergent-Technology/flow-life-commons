<?php

declare(strict_types=1);

namespace App\Modules\Security\Application;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Checks the configuration a production deployment would run under, from INSIDE that deployment.
 *
 * What it is for: a production host inherits its environment from a file somebody edits by hand. The
 * dangerous failures are not exotic — they are `APP_DEBUG=true` left on, the breached-password check
 * still set to `none` because that is what the development example says, the raised login limit the
 * browser suite needs, a session driver that is not the database. Each of those is invisible until
 * something goes wrong, and each is a single line to check.
 *
 * What it deliberately is NOT: a policy engine. Every check is a static read of configuration or of
 * the filesystem, it changes nothing, and it asks the operating system nothing it cannot answer. It
 * makes no claim about the hosting account — whether `mod_headers` is enabled, whether cron runs, what
 * PHP the web server uses rather than the CLI. Those are owner verifications, listed separately by the
 * command that renders this, so a green result is never mistaken for a verified host.
 */
final readonly class ProductionReadiness
{
    public function __construct(private Config $config, private Application $app) {}

    /** @return list<ReadinessCheck> */
    public function checks(): array
    {
        return [
            ...$this->environment(),
            ...$this->credentials(),
            ...$this->session(),
            ...$this->limits(),
            ...$this->runtime(),
        ];
    }

    /** @return list<ReadinessCheck> */
    private function environment(): array
    {
        $url = $this->config->string('app.url');

        return [
            ReadinessCheck::assert(
                'APP_ENV is production',
                $this->config->string('app.env') === 'production',
                'APP_ENV is "'.$this->config->string('app.env').'". Development-only seams (the e2e fixtures, the no-op breach checker, the Console user fixture) refuse to run only OUTSIDE local and testing.',
            ),
            ReadinessCheck::assert(
                'APP_DEBUG is off',
                $this->config->boolean('app.debug') === false,
                'APP_DEBUG is on. Every error would return a stack trace, the SQL that failed, and the configuration around it, to whoever triggered it.',
            ),
            ReadinessCheck::assert(
                'APP_URL is an https:// address',
                str_starts_with($url, 'https://'),
                'APP_URL is "'.$url.'". It is the origin of every invitation and password-reset link, and the host the trusted-host check allows; over http:// the __Host- session cookie cannot be issued at all.',
            ),
            ReadinessCheck::assert(
                'APP_URL names one host, with no path',
                in_array(rtrim($url, '/'), [$url, $url.'/'], true) && (parse_url($url, PHP_URL_PATH) === null || parse_url($url, PHP_URL_PATH) === '/'),
                'APP_URL has a path. The Console and the API share the ROOT of one origin (ADR 0016).',
            ),
        ];
    }

    /** @return list<ReadinessCheck> */
    private function credentials(): array
    {
        $key = $this->config->string('app.key');

        return [
            ReadinessCheck::assert(
                'APP_KEY is set',
                $key !== '',
                'Without it nothing can be encrypted or decrypted: no session, no cookie, no stored authenticator secret.',
            ),
            ReadinessCheck::assert(
                'the breached-password check is the real one',
                $this->config->string('identity.password.compromised_check.driver') === 'pwned_passwords',
                'IDENTITY_COMPROMISED_PASSWORD_CHECK is "'.$this->config->string('identity.password.compromised_check.driver').'". The development example file sets it to "none"; copying that file to production is the way this happens. It needs outbound HTTPS to api.pwnedpasswords.com.',
            ),
            ReadinessCheck::assert(
                'password hashing is not at a development cost',
                $this->config->integer('hashing.bcrypt.rounds') >= 10,
                'bcrypt rounds are '.$this->config->integer('hashing.bcrypt.rounds').'. The test suite runs at 4 for speed; a production value that low makes stolen hashes cheap to crack.',
            ),
        ];
    }

    /** @return list<ReadinessCheck> */
    private function session(): array
    {
        // The cookie attributes are fixed in config and not read from the environment, so these cannot
        // fail on a host that was configured badly — only if someone edits the file. That is exactly
        // why they are checked: the architecture calls them invariants, so something must say so.
        return [
            ReadinessCheck::assert('sessions are stored in the database', $this->config->string('session.driver') === 'database',
                'SESSION_DRIVER is "'.$this->config->string('session.driver').'". A session must be revocable, which is why it is a row (ADR 0016); a cookie or file session cannot be ended by an administrator.'),
            ReadinessCheck::assert('the session cookie is __Host- prefixed', $this->config->string('session.cookie') === '__Host-flowlife-session',
                'The prefix is what makes the browser itself refuse the cookie if it ever gains a Domain or loses Secure.'),
            ReadinessCheck::assert('the session cookie is Secure', $this->config->boolean('session.secure'), ''),
            ReadinessCheck::assert('the session cookie is HttpOnly', $this->config->boolean('session.http_only'), ''),
            ReadinessCheck::assert('the session cookie is host-only (no Domain)', $this->config->get('session.domain') === null,
                'A Domain would send this privileged cookie to every sibling host, WordPress included (ADR 0004).'),
            ReadinessCheck::assert('the session cookie path is /', $this->config->string('session.path') === '/', ''),
            ReadinessCheck::assert('the session cookie is SameSite=Lax', $this->config->string('session.same_site') === 'lax', ''),
            ReadinessCheck::assert('idle sessions expire after 30 minutes', $this->config->integer('session.lifetime') === 30,
                'SESSION_LIFETIME is '.$this->config->integer('session.lifetime').'; the frozen policy is 30 minutes of request inactivity.'),
            ReadinessCheck::assert('the absolute session lifetime is 12 hours', $this->config->integer('identity.session.absolute_lifetime_minutes') === 720, ''),
            ReadinessCheck::assert('session sweeping is scheduled, not left to the request lottery', $this->config->array('session.lottery') === [0, 100],
                'A non-zero lottery makes an ordinary request pay for a sweep. Deterministic maintenance replaces it (identity:prune-expired), which needs the cron entry below.'),
            ReadinessCheck::assert('no cross-origin browser client is allowed', $this->config->array('cors.allowed_origins') === [],
                'CORS_ALLOWED_ORIGINS is set. The Console is same-origin and needs none (ADR 0016).'),
            ReadinessCheck::assert('cross-origin requests can never carry the session cookie', $this->config->boolean('cors.supports_credentials') === false, ''),
        ];
    }

    /** @return list<ReadinessCheck> */
    private function limits(): array
    {
        $perIp = $this->config->integer('identity.login_throttle.max_attempts_per_ip');

        return [
            ReadinessCheck::assert(
                'the login rate limit is not the raised development one',
                $perIp <= 30,
                'IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP is '.$perIp.'. The browser suite raises it to 200 and the development example file sets it; production must keep the default of 30.',
            ),
            ReadinessCheck::assert(
                'failed sign-ins are limited per identifier',
                $this->config->integer('identity.login_throttle.max_failures_per_identifier') <= 5,
                'A raised per-identifier limit turns a slow password guess into a feasible one.',
            ),
            ReadinessCheck::assert(
                '"I forgot my password" pads its response',
                $this->config->integer('identity.password_reset.response_floor_ms') >= 1000,
                'IDENTITY_PASSWORD_RESET_RESPONSE_FLOOR_MS is '.$this->config->integer('identity.password_reset.response_floor_ms').'. Tests set it to 0; without the floor, response TIME says which addresses have accounts.',
            ),
        ];
    }

    /** @return list<ReadinessCheck> */
    private function runtime(): array
    {
        $extensions = ['pdo_mysql', 'mbstring', 'openssl', 'intl', 'bcmath', 'zip', 'fileinfo', 'ctype', 'tokenizer'];
        $missing = array_values(array_filter($extensions, static fn (string $name): bool => ! extension_loaded($name)));

        $writable = array_values(array_filter(
            [storage_path('framework'), storage_path('logs'), storage_path('app'), $this->app->bootstrapPath('cache')],
            static fn (string $path): bool => ! is_writable($path),
        ));

        return [
            ReadinessCheck::assert('PHP is 8.3 or newer', version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP is '.PHP_VERSION.'.'),
            ReadinessCheck::assert('every required PHP extension is loaded', $missing === [], 'Missing: '.implode(', ', $missing).'.'),
            ReadinessCheck::assert('the runtime directories are writable', $writable === [], 'Not writable: '.implode(', ', $writable).'.'),
            ReadinessCheck::assert(
                'the version of PHP is not announced',
                ini_get('expose_php') === '' || ini_get('expose_php') === '0' || ini_get('expose_php') === 'Off',
                'expose_php is on, so responses carry X-Powered-By with the PHP patch level. NOTE: this reads the CLI configuration; the web server may differ, which is an owner verification.',
            ),
        ];
    }
}
