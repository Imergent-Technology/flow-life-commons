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
    /** Backing services the hosting account does not have and will not grow (charter, ADR 0010). */
    private const array SERVICES_THE_HOST_LACKS = ['redis', 'memcached', 'dynamodb', 'sqs', 'beanstalkd'];

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
            ...$this->maintenance(),
            ...$this->database(),
            ...$this->mail(),
        ];
    }

    /**
     * Open items this deployment has NOT closed, reported without failing it.
     *
     * Distinct from a check, and the distinction is the whole point: a failed check means the
     * deployment is unsafe and must not serve anyone, while these are decisions that have been
     * deliberately deferred and are documented as deferred. Folding them into the pass/fail list
     * would make a correct deployment look broken, and the usual response to a check that always
     * fails is to stop reading it.
     *
     * `passed` here means "closed", not "safe".
     *
     * @return list<ReadinessCheck>
     */
    public function deferred(): array
    {
        $mailer = $this->config->string('mail.default');
        $configured = ! in_array($mailer, ['log', 'array'], true);

        return [
            ReadinessCheck::assert(
                'outbound mail is authenticated and its deliverability is verified',
                // ALWAYS reported open, regardless of what MAIL_MAILER is set to. SPF alignment for the
                // actual envelope sender, DKIM signing, a DMARC pass and inbox-rather-than-spam
                // placement can only be established by sending real mail and looking at where it
                // landed (production-readiness.md, section 5) — a fact no configuration read can
                // produce. Deriving "closed" from the mailer name alone was the earlier version of
                // this check, and it was wrong in the dangerous direction: an authenticated-LOOKING
                // transport (a real SMTP host, real credentials) reports exactly the same "configured"
                // shape whether or not it has ever actually been verified, so a person could read a
                // green result here as "mail works" when nothing has confirmed that at all.
                false,
                $configured
                    ? 'MAIL_MAILER is "'.$mailer.'": a transport is configured, which is necessary but not '
                        .'sufficient. Configuration shape cannot establish SPF alignment for the real envelope '
                        .'sender, DKIM signing or a DMARC pass — those are only established by sending a real '
                        .'invitation and checking where it lands (docs/runbooks/production-readiness.md, section 5). '
                        .'Record the result there once done; this command has no way to read it back.'
                    : 'MAIL_MAILER is "'.$mailer.'", so nothing is sent: an invitation is written to the log instead. '
                        .'This is the documented deferred state, not a fault — Flow Life\'s mail topology is an '
                        .'organizational decision that has not been made, and PHP mail() is not approved (it '
                        .'delivered unsigned and not DMARC-aligned when tested on 2026-09-21). Deployment is not '
                        .'blocked: the first administrator\'s invitation token is delivered out of band over SSH. '
                        .'Inviting anyone else is blocked until a transport is chosen and SPF alignment, DKIM, DMARC '
                        .'and inbox placement are each verified with a real invitation '
                        .'(docs/runbooks/production-readiness.md, section 5).',
            ),
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
                'APP_KEY is a key of the right size for the cipher',
                $key === '' || self::keyBytes($key) === self::cipherBytes($this->config->string('app.cipher')),
                // The VALUE is never named here, in success or failure: this runs on a live host and its
                // output gets pasted into tickets. The length is enough to act on.
                'It decodes to '.self::keyBytes($key).' bytes and '.$this->config->string('app.cipher').' needs '
                .self::cipherBytes($this->config->string('app.cipher')).'. Generate one with `php artisan key:generate '
                .'--show` and record it with the date in the secure store: every backup taken under it is only '
                .'restorable alongside it (ADR 0023).',
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

    /**
     * The maintenance flag is a FILE, and both halves of the origin read that one file.
     *
     * @return list<ReadinessCheck>
     */
    private function maintenance(): array
    {
        $driver = $this->config->string('app.maintenance.driver');

        return [
            ReadinessCheck::assert(
                'maintenance mode is driven by a file, which the web server can see',
                $driver === 'file',
                'APP_MAINTENANCE_DRIVER is "'.$driver.'". `php artisan down` would then record the state somewhere '
                .'Apache cannot read, so `storage/framework/down` is never written — and the Console, its assets and '
                .'every client-side route would keep serving normally while the API is down. One authority, two '
                .'enforcement points, and both of them test for that file (ADR 0027).',
            ),
        ];
    }

    /**
     * Database credentials, checked for presence and for being nobody\'s development leftovers.
     *
     * No value is ever included in a detail message. The name of the setting is what the operator
     * needs; the value is a credential, and this command is run on a live host where its output may
     * be pasted into a ticket.
     *
     * @return list<ReadinessCheck>
     */
    private function database(): array
    {
        $connection = $this->config->string('database.default');
        $settings = $this->config->array("database.connections.$connection");

        $missing = array_values(array_filter(
            ['database', 'username', 'password'],
            static fn (string $key): bool => ! is_string($settings[$key] ?? null) || ($settings[$key] === ''),
        ));
        $password = is_string($settings['password'] ?? null) ? $settings['password'] : '';

        return [
            ReadinessCheck::assert(
                'the database connection is the engine production runs',
                in_array($connection, ['mariadb', 'mysql'], true),
                'DB_CONNECTION is "'.$connection.'". Production is MariaDB 10.11 on the hosting account (ADR 0005 keeps '
                .'PostgreSQL portability, which is a property of the schema, not a production topology).',
            ),
            ReadinessCheck::assert(
                'the database credentials are all supplied',
                $missing === [],
                'Not set for the "'.$connection.'" connection: '.implode(', ', $missing).'. cPanel prefixes both the '
                .'database and the user with the account name, so these are not the names you typed when creating them.',
            ),
            ReadinessCheck::assert(
                'no development credential reached production',
                ! in_array($password, ProductionEnvironment::DEVELOPMENT_SECRETS, true),
                'The database password is one of the throwaway values from compose.yaml. It is in the repository, in '
                .'every clone, and in the git history: treat it as public.',
            ),
            ReadinessCheck::assert(
                'nothing depends on a service this host does not run',
                ! in_array($this->config->string('cache.default'), self::SERVICES_THE_HOST_LACKS, true)
                    && ! in_array($this->config->string('queue.default'), self::SERVICES_THE_HOST_LACKS, true),
                'CACHE_STORE is "'.$this->config->string('cache.default').'" and QUEUE_CONNECTION is "'
                .$this->config->string('queue.default').'". The hosting account runs no Redis, memcached or resident '
                .'daemon of any kind (charter); both must be database-backed.',
            ),
        ];
    }

    /**
     * The one mail fact that is settled: PHP\'s own transport is not approved.
     *
     * Everything else about mail is deliberately open and is reported by `deferred()` instead.
     *
     * @return list<ReadinessCheck>
     */
    private function mail(): array
    {
        $mailer = $this->config->string('mail.default');

        return [
            ReadinessCheck::assert(
                'the unapproved PHP mail() transport is not in use',
                $mailer !== 'sendmail',
                'MAIL_MAILER is "sendmail", which hands mail to the host\'s own binary. Tested on this host on '
                .'2026-09-21: it delivers, and it delivers with no DKIM signature and a Return-Path that is not aligned '
                .'with the visible From, so it fails DMARC. An invitation that silently lands in spam is an outage that '
                .'looks like nothing at all, and this is an invite-only directory where that email is the way in.',
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
            // `expose_php` is deliberately NOT a check here: see exposePhp() below for why.
        ];
    }

    /**
     * The CLI process's own `expose_php` reading, informational only — NOT a check, and deliberately
     * so.
     *
     * `ini_get('expose_php')` here reads the ini of the process running this command, which on a
     * shared host is very often a DIFFERENT PHP build from the one answering HTTP requests: cPanel
     * commonly ships separate `ea-php83` (web, through LSAPI) and `ea-php83-cli` (interactive CLI)
     * packages with independent `php.ini` files, and this command always runs under the CLI one. A
     * hard failure here would therefore fail or pass on a fact about the wrong process.
     *
     * This reading is not merely unreliable in theory: it was actively MISLEADING once. A CLI-only
     * probe on 2026-09-21 read `On` here and (wrongly) concluded no `X-Powered-By` header reached a
     * client; the first supervised deployment rehearsal (2026-09-22, real Apache + CloudLinux LSAPI)
     * found every PHP response carrying `X-Powered-By: PHP/8.3.33` (production-readiness.md, section
     * 4b, item 2). That account's control panel exposes no way to change `expose_php` at all — no
     * `expose_php` toggle in "Select PHP Version", no MultiPHP INI Editor — so this setting, read from
     * anywhere, is not something an operator can act on regardless. The header is now removed at the
     * one place that CAN enforce it: `public/.htaccess` (`Header onsuccess unset X-Powered-By` and
     * `Header always unset X-Powered-By`, ADR 0026), which makes the client-visible fact independent
     * of whatever `expose_php` reads, on either SAPI, from here on.
     *
     * The fact that actually matters — whether `X-Powered-By` reaches a real client — cannot be
     * established by reading local configuration at all (this command connects to nothing); it is an
     * owner verification, confirmed on the host and re-confirmed after any change to `public/.htaccess`
     * or the account's PHP.
     */
    public function exposePhp(): string
    {
        $value = ini_get('expose_php');
        if ($value === false) {
            return 'unknown (the ini setting could not be read)';
        }

        return $value === '' || $value === '0' ? 'Off' : $value;
    }

    /** How many bytes of key material APP_KEY actually carries. Never returns or logs the key itself. */
    private static function keyBytes(string $key): int
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? 0 : strlen($decoded);
        }

        return strlen($key);
    }

    private static function cipherBytes(string $cipher): int
    {
        return str_contains($cipher, '128') ? 16 : 32;
    }
}
