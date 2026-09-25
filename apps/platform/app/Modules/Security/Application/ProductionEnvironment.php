<?php

declare(strict_types=1);

namespace App\Modules\Security\Application;

/**
 * The production environment contract: which settings a production `.env` must carry, which the
 * operator supplies per host, and which must never appear in one at all.
 *
 * WHY THIS EXISTS AS A LIST RATHER THAN PROSE. There are two artefacts describing the same thing —
 * `apps/platform/.env.production.example`, which an operator copies, and `ProductionReadiness`, which
 * refuses a bad deployment afterwards — and the failure mode is that they drift: the template gains a
 * setting the check never looks at, or the check starts requiring something the template never
 * mentions, and the first person to notice is the one deploying. So the names live here once, and a
 * test holds the template to them.
 *
 * It is deliberately a list of KEYS, not a schema. Parsing dotenv properly, with its quoting and
 * interpolation rules, to validate values that Laravel is about to parse anyway would be a second
 * implementation of someone else's format. Values are judged where they are already resolved: by
 * `ProductionReadiness`, reading configuration.
 */
final readonly class ProductionEnvironment
{
    /**
     * Settings a production environment file must state explicitly.
     *
     * "Explicitly" is the point. Most of these have a framework default that is correct, and relying
     * on a default means the file gives no sign that the setting matters — so the next person to edit
     * it cannot tell the difference between a value that was chosen and one that was never considered.
     */
    public const array REQUIRED_KEYS = [
        'APP_NAME',
        'APP_ENV',
        'APP_KEY',
        'APP_DEBUG',
        'APP_URL',
        'APP_MAINTENANCE_DRIVER',
        'BCRYPT_ROUNDS',
        'LOG_CHANNEL',
        'LOG_LEVEL',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'SESSION_DRIVER',
        'SESSION_LIFETIME',
        'CACHE_STORE',
        'QUEUE_CONNECTION',
        'FILESYSTEM_DISK',
        'MAIL_MAILER',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'CORS_ALLOWED_ORIGINS',
    ];

    /**
     * Keys the template must leave EMPTY, because their value is a secret or a per-host fact.
     *
     * A template that ships a plausible-looking value for one of these is worse than one that ships
     * none: it invites being copied unchanged, and a placeholder password that reaches production is
     * indistinguishable from a real one until someone tries it.
     */
    public const array OPERATOR_SUPPLIED = [
        'APP_KEY',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    /**
     * Keys that must NOT appear in a production environment file.
     *
     * The first three are the development example's dangerous values, and copying that file is exactly
     * how they reach production (docs/security/secrets.md). Each has a safe default in config, so the
     * correct production file is the one that stays silent about them: `pwned_passwords` and 30
     * attempts (of a password, and of a second-factor code) are what the application chooses when
     * nothing overrides it.
     *
     * The last is different, and is listed to prevent a misunderstanding rather than a mistake. This
     * application trusts no reverse proxy, and that is a decision expressed in `bootstrap/app.php`,
     * not a setting: Commons is direct to origin (trust boundaries), and every per-address rate limit
     * and audit row depends on the client address being the real one. Setting `TRUSTED_PROXIES` in an
     * environment file does nothing at all — which is the trap. If a proxy is ever put in front of
     * production, its addresses are listed in code, never a wildcard, and reviewed on their own.
     */
    public const array FORBIDDEN_KEYS = [
        'IDENTITY_COMPROMISED_PASSWORD_CHECK',
        'IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP',
        'IDENTITY_MFA_MAX_PER_IP',
        'TRUSTED_PROXIES',
    ];

    /** Development credentials from compose.yaml. If one of these reaches production, say so loudly. */
    public const array DEVELOPMENT_SECRETS = [
        'flowlife_dev_only',
        'flowlife_root_dev_only',
    ];

    public static function templatePath(): string
    {
        return base_path('.env.production.example');
    }
}
