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

    /**
     * Production mail transports this deployment currently approves (ADR 0031): `log`, the documented
     * deliberate deferral, and `smtp`, the one real delivery transport this package prepares. Adopting
     * another transport — an HTTP-API provider, `failover`, `roundrobin` — is meant to be an
     * intentional, reviewed change to this allowlist, not a value someone can set ahead of it.
     */
    private const array APPROVED_PRODUCTION_MAILERS = ['log', 'smtp'];

    /**
     * The development mail catcher's own identity (docker-compose's `mailpit` service, `.env.example`).
     * None of these can validly appear in a production SMTP configuration.
     */
    private const array DEVELOPMENT_SMTP_HOSTS = ['mailpit', '127.0.0.1', 'localhost', '::1'];

    /** Mailpit's fixed SMTP port (`.env.example`). A real provider does not use it. */
    private const int DEVELOPMENT_SMTP_PORT = 1025;

    /**
     * The ceiling for MAIL_TIMEOUT. Invitation and password-reset delivery are synchronous and
     * unqueued (Identity's mail adapters, ADR 0024), so this bounds how long an operator's or a
     * visitor's HTTP request can block on a stalled SMTP conversation — not a performance tuning
     * knob. Kept small and simple rather than derived from any host's specific request-timeout
     * ceiling, which this command has no way to read.
     */
    private const float MAX_SMTP_TIMEOUT_SECONDS = 30.0;

    /**
     * The bounds Resources applies to `resources.assets.max_bytes` (Resources\Application\AssetLimits), restated because this module
     * depends on none: the check must judge the limit the application will actually enforce. A test pins that the two agree.
     */
    public const int RESOURCES_ASSET_FLOOR = 1024 * 1024;

    public const int RESOURCES_ASSET_CEILING = 100 * 1024 * 1024;

    /** Room a multipart request needs beyond the file itself: the other fields (a File Card's document may be 256 KiB) and framing. */
    public const int RESOURCES_FORM_ALLOWANCE = 1024 * 1024;

    public function __construct(private Config $config, private Application $app, private PhpIni $ini = new PhpIni) {}

    /** @return list<ReadinessCheck> */
    public function checks(): array
    {
        return [
            ...$this->environment(),
            ...$this->credentials(),
            ...$this->session(),
            ...$this->limits(),
            ...$this->runtime(),
            ...$this->uploads(),
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
        $mfaPerIp = $this->config->integer('identity.credential_throttle.mfa_challenge.per_ip');

        return [
            ReadinessCheck::assert(
                'the login rate limit is not the raised development one',
                $perIp <= 30,
                'IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP is '.$perIp.'. The browser suite raises it to 200 and the development example file sets it; production must keep the default of 30.',
            ),
            ReadinessCheck::assert(
                'the MFA challenge rate limit is not the raised development one',
                $mfaPerIp <= 30,
                'IDENTITY_MFA_MAX_PER_IP is '.$mfaPerIp.'. The browser suite raises it to 200 and the development example file sets it; production must keep the default of 30, or a stolen password could be paired with unlimited guesses at the second factor.',
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
     * What configuration ALONE can honestly settle about mail (ADR 0031): which transport is in use,
     * and — only when it is `smtp` — whether its shape is one a real provider could actually own. It
     * cannot settle SPF alignment, DKIM signing, a DMARC pass or inbox placement; those can only be
     * learned by sending real mail and looking at where it landed, and stay `deferred()`'s job.
     *
     * **Fail-closed, not a blocklist.** Earlier this only refused `sendmail` by name, so an unreviewed
     * future transport (or a typo) would silently pass. Now only `log` and `smtp`
     * (`APPROVED_PRODUCTION_MAILERS`) are accepted at all; `sendmail` (tested on this host on
     * 2026-09-21: delivered, no DKIM signature, a Return-Path not aligned with the visible From, so it
     * fails DMARC), `array` (a test-only transport that silently discards mail) and anything else —
     * `ses`, `postmark`, `mailgun`, a typo — each fail the same way: by not being on the allowlist.
     *
     * **The `smtp` structural checks are each written `! $isSmtp || …`**, so they read as trivially
     * satisfied — and never separately fail a `log`-configured deployment — when `smtp` is not the
     * configured transport, matching how every other check in this class reports "not applicable" as
     * passed rather than adding a second axis of failure.
     *
     * @return list<ReadinessCheck>
     */
    private function mail(): array
    {
        $mailer = $this->config->string('mail.default');
        $isSmtp = $mailer === 'smtp';

        $smtp = $this->config->array('mail.mailers.smtp');
        $host = is_string($smtp['host'] ?? null) ? $smtp['host'] : '';
        $port = $smtp['port'] ?? null;
        $username = is_string($smtp['username'] ?? null) ? $smtp['username'] : '';
        $password = is_string($smtp['password'] ?? null) ? $smtp['password'] : '';
        $timeout = $smtp['timeout'] ?? null;
        $configuredScheme = is_string($smtp['scheme'] ?? null) ? $smtp['scheme'] : null;
        // Mirrors Illuminate\Mail\MailManager::createSmtpTransport(): an explicit scheme wins; failing
        // that, port 465 means implicit TLS ("smtps"), and everything else is the "smtp" scheme with
        // opportunistic STARTTLS.
        $scheme = ($configuredScheme !== null && $configuredScheme !== '')
            ? $configuredScheme
            : ((is_numeric($port) && (int) $port === 465) ? 'smtps' : 'smtp');
        $requireTls = filter_var($smtp['require_tls'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $verifyPeerDisabled = array_key_exists('verify_peer', $smtp)
            && $smtp['verify_peer'] !== ''
            && ! filter_var($smtp['verify_peer'], FILTER_VALIDATE_BOOLEAN);
        $fromAddress = $this->config->string('mail.from.address');

        return [
            ReadinessCheck::assert(
                'the configured mail transport is one production currently approves (log or smtp)',
                in_array($mailer, self::APPROVED_PRODUCTION_MAILERS, true),
                match (true) {
                    $mailer === 'sendmail' => 'MAIL_MAILER is "sendmail", which hands mail to the host\'s own binary. '
                        .'Tested on this host on 2026-09-21: it delivers, and it delivers with no DKIM signature and a '
                        .'Return-Path that is not aligned with the visible From, so it fails DMARC. An invitation that '
                        .'silently lands in spam is an outage that looks like nothing at all, and this is an '
                        .'invite-only directory where that email is the way in.',
                    $mailer === 'array' => 'MAIL_MAILER is "array", a test-only transport that accepts every message '
                        .'and delivers none. It belongs in phpunit.xml, never in a deployment.',
                    default => 'MAIL_MAILER is "'.$mailer.'". Only "log" (deliberately deferred: nothing is sent) and '
                        .'"smtp" (the transport this package prepares) are approved here. Adopting another transport '
                        .'is meant to be an intentional, reviewed change to this allowlist (ADR 0031), not a value '
                        .'set ahead of it.',
                },
            ),
            ReadinessCheck::assert(
                'an smtp mailer names a real host, not the development Mailpit one',
                ! $isSmtp || ($host !== '' && ! in_array(strtolower($host), self::DEVELOPMENT_SMTP_HOSTS, true)),
                'MAIL_HOST is "'.$host.'". That is empty, or it is the development Mailpit host — the same catcher '
                .'docker-compose runs and .env.example points at. Production needs the transactional provider\'s own '
                .'SMTP host.',
            ),
            ReadinessCheck::assert(
                'an smtp mailer uses a real submission port, not the development Mailpit one',
                ! $isSmtp || (is_numeric($port) && (int) $port > 0 && (int) $port !== self::DEVELOPMENT_SMTP_PORT),
                'MAIL_PORT is "'.(is_scalar($port) ? (string) $port : 'unset').'". '.self::DEVELOPMENT_SMTP_PORT
                .' is Mailpit\'s fixed development port. A provider\'s real submission port is ordinarily 587 '
                .'(STARTTLS) or 465 (implicit TLS).',
            ),
            ReadinessCheck::assert(
                'an smtp mailer supplies authentication credentials',
                ! $isSmtp || ($username !== '' && $password !== ''),
                'MAIL_USERNAME or MAIL_PASSWORD is empty. Mailpit accepts unauthenticated mail; a real transactional '
                .'provider does not, and an unauthenticated relay is exactly the kind of unsigned, DMARC-misaligned '
                .'delivery already measured and rejected with PHP mail().',
            ),
            ReadinessCheck::assert(
                'an smtp mailer has a real sender identity configured',
                ! $isSmtp || ($fromAddress !== '' && $fromAddress !== 'hello@example.com'
                    && str_contains($fromAddress, '@') && ! str_ends_with($fromAddress, '@')),
                'MAIL_FROM_ADDRESS is "'.$fromAddress.'". That is empty or the framework\'s own placeholder. The '
                .'visible From is what SPF, DKIM and DMARC alignment are checked against once real delivery is '
                .'verified (production-readiness.md, section 5).',
            ),
            ReadinessCheck::assert(
                'an smtp mailer requires TLS rather than merely allowing it',
                ! $isSmtp || $scheme === 'smtps' || $requireTls,
                'Neither the "smtps" scheme (implicit TLS, ordinarily port 465) nor MAIL_REQUIRE_TLS is set. Symfony '
                .'Mailer\'s default on the "smtp" scheme is OPPORTUNISTIC STARTTLS: if the server does not advertise '
                .'it, the message is sent in plaintext over the open Internet instead of the connection failing '
                .'(symfony/mailer\'s EsmtpTransportFactory). Set MAIL_REQUIRE_TLS=true on port 587, or use port 465, '
                .'so a downgrade fails the send instead of sending it unencrypted.',
            ),
            ReadinessCheck::assert(
                'an smtp mailer does not disable certificate verification',
                ! $isSmtp || ! $verifyPeerDisabled,
                'verify_peer is disabled in the smtp mailer configuration, which accepts a TLS certificate from '
                .'anyone claiming to be the provider. This is deliberately not read from the environment, so fixing '
                .'it means editing config/mail.php, not flipping an env value back.',
            ),
            ReadinessCheck::assert(
                'an smtp mailer\'s timeout is set and bounded',
                ! $isSmtp || (is_numeric($timeout) && (float) $timeout > 0.0 && (float) $timeout <= self::MAX_SMTP_TIMEOUT_SECONDS),
                'MAIL_TIMEOUT resolves to '.(is_scalar($timeout) ? (string) $timeout : 'unset').'. Invitation and '
                .'password-reset delivery are synchronous and unqueued, so this is how long a real person\'s request '
                .'can block on a stalled connection: it must be a positive number of seconds, no more than '
                .((int) self::MAX_SMTP_TIMEOUT_SECONDS).'.',
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
     * Resources' managed files (ADR 0037, decisions 63-64). The application refuses a file over `resources.assets.max_bytes` itself;
     * these make sure PHP lets such a file reach it, so that "too large" is the application's coded answer rather than PHP silently
     * discarding the body, and that the store the files go to is private.
     *
     * The PHP limits read here are THIS process's (the CLI's on the production host). The web server's PHP can differ, which is why
     * the command also lists them as an owner verification.
     *
     * @return list<ReadinessCheck>
     */
    private function uploads(): array
    {
        $max = max(self::RESOURCES_ASSET_FLOOR, min(self::RESOURCES_ASSET_CEILING, $this->config->integer('resources.assets.max_bytes', 20 * 1024 * 1024)));
        $upload = $this->ini->bytes('upload_max_filesize');
        $post = $this->ini->bytes('post_max_size');
        $fileUploads = $this->ini->get('file_uploads');
        $maxFiles = $this->ini->get('max_file_uploads');

        $disk = $this->config->array('filesystems.disks.resources', []);
        $root = is_string($disk['root'] ?? null) ? rtrim(str_replace('\\', '/', $disk['root']), '/') : '';
        $public = rtrim(str_replace('\\', '/', $this->app->publicPath()), '/');
        $private = $root !== '' && $root !== $public && ! str_starts_with($root.'/', $public.'/')
            && ! array_key_exists('url', $disk) && ! (bool) ($disk['serve'] ?? false) && ($disk['visibility'] ?? null) === 'private';

        return [
            ReadinessCheck::assert(
                'PHP accepts file uploads',
                in_array(strtolower(is_string($fileUploads) ? $fileUploads : ''), ['1', 'on', 'true', 'yes'], true) && is_numeric($maxFiles) && (int) $maxFiles >= 1,
                'file_uploads is off or max_file_uploads is 0, so no File Card can be created and no file replaced (ADR 0037).',
            ),
            ReadinessCheck::assert(
                'PHP\'s upload_max_filesize admits the largest Resources file',
                $upload !== null && ($upload === 0 || $upload >= $max),
                'upload_max_filesize is '.self::size($upload).' and RESOURCES_ASSET_MAX_BYTES is '.self::size($max).'. PHP would discard a '
                .'file between the two before the application could refuse it properly. Raise upload_max_filesize, or lower '
                .'RESOURCES_ASSET_MAX_BYTES to what the host allows.',
            ),
            ReadinessCheck::assert(
                'PHP\'s post_max_size admits the largest Resources upload, with room for the rest of the form',
                $post !== null && ($post === 0 || $post >= $max + self::RESOURCES_FORM_ALLOWANCE),
                'post_max_size is '.self::size($post).'; it must be at least RESOURCES_ASSET_MAX_BYTES ('.self::size($max).') plus '
                .self::size(self::RESOURCES_FORM_ALLOWANCE).' for the other fields of the form. Over it, PHP drops the whole request body.',
            ),
            ReadinessCheck::assert(
                'the Resources file store is private and outside the public directory',
                $private,
                'The `resources` disk in config/filesystems.php must be a private disk with no url, not served, rooted outside public/. '
                .'Resource files are authorization-controlled: they are served only by Resources\' authorized routes (ADR 0037, decision 66).',
            ),
        ];
    }

    private static function size(?int $bytes): string
    {
        return match (true) {
            $bytes === null => 'unreadable',
            $bytes === 0 => 'unlimited',
            default => number_format($bytes / 1048576, 1).' MiB ('.$bytes.' bytes)',
        };
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
