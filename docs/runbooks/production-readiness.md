# Production readiness

**Purpose.** What must be true before the Guardian Console serves real people, and who can establish each thing.

**Owner.** Whoever holds the hosting account. **Status:** the application-side checks are automated and green in development; **every hosting item below is unverified** — the cPanel account has not been inspected.

The distinction running through this document is the only thing that makes it useful:

- **Verified here** — proved by a test or a command in this repository, on every run.
- **Owner verification** — can only be established on the real host, by a person.

A green `./flow check` says nothing about the second column. Do not treat it as though it does.

---

## 1. Before anything: what production is

One origin, `https://commons.flowlifeglobal.org`, serving the Guardian Console's static build and the Laravel API from one document root ([deployment topology](../architecture/deployment-topology.md), [ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)). No Docker, no Node, no Redis, no resident daemons — cPanel shared hosting with Apache, PHP 8.3 and MariaDB ([charter](../architecture/charter.md)).

## 2. Application configuration — run the command

```
php artisan security:production-check
```

(`./flow doctor --production` runs the same thing in the development container, against the development environment file, where it is *expected* to fail.)

It reads the configuration this deployment is actually running under and refuses anything dangerous: `APP_ENV`, `APP_DEBUG`, an `http://` application URL, the no-op breached-password checker, a test-speed bcrypt cost, every session-cookie invariant, the session driver and lifetimes, the raised development login limit, the reset-response floor, CORS, PHP version and extensions, writable runtime directories, and `expose_php`.

Two of those deserve naming, because both come from copying `apps/platform/.env.example`:

- **`IDENTITY_COMPROMISED_PASSWORD_CHECK=none`** — development and CI set it so the gate needs no network. In production it means known-breached passwords are accepted. The container refuses `none` outside `local` and `testing`, so this fails loudly rather than silently; the check catches it earlier.
- **`IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP=200`** — the browser suite needs it. Production keeps 30.

The command ends by printing what it cannot see. That list is section 4.

## 3. The scheduler: one cron entry, and everything else in source control

**This is the production maintenance contract**, and it is one line:

```
* * * * *   cd /home/<user>/commons/platform && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Every minute, one command. What it runs is `routes/console.php`, in source control, reviewed like any other code. **Do not add per-task cron entries**: they are invisible to review, drift between environments and are lost on a host migration. The same schedule works on cPanel now and on a VPS later without change.

Today the schedule holds one task, `identity:prune-expired`, hourly — idle database sessions, expired password-reset tokens and unproved authenticator secrets ([session maintenance](../architecture/identity-and-access.md)).

**If the cron entry is missing**, nothing prunes and the `sessions` table grows without limit. That is a capacity problem, not a security one — an idle session is refused on its `last_activity` whether or not its row is still there — but it will eventually fill the account's disk quota.

Verify it is working: `php artisan schedule:list` shows the task and its next run; after an hour, `select count(*) from sessions` should stop climbing.

## 4. Hosting checklist — owner verification

**None of these is verified.** Each needs someone to check it on the real account.

| # | To confirm | Where | If it is not available |
| --- | --- | --- | --- |
| 1 | **HTTPS is active** on the subdomain (AutoSSL or equivalent) | cPanel → SSL/TLS Status | **Hard blocker.** `__Host-` requires `Secure`, so the session cookie cannot be issued at all over plain HTTP. Nobody can sign in. |
| 2 | The subdomain has an **independent document root** (e.g. `/home/<user>/commons/platform/public`), not forced under `public_html` and not shared with WordPress | cPanel → Domains | The Console and API could not be isolated from the WordPress docroot. Revisit before shipping. |
| 3 | **`.htaccess` overrides are honoured** with `mod_rewrite` | Try a rewrite rule and observe | Routing must move into server config. Same arrangement, no longer self-contained. |
| 4 | **`mod_headers` is enabled** | `Header set` in `.htaccess`, then read the response | **The Console's static files would ship with NO security headers** while the API keeps them ([ADR 0026](../adr/0026-production-browser-security-policy.md)). The origin is materially weaker; treat as a blocker. |
| 5 | **PHP 8.3** for the subdomain with `pdo_mysql`, `mbstring`, `openssl`, `intl`, `bcmath`, `zip`, `fileinfo`, `ctype`, `tokenizer` | cPanel → MultiPHP Manager / Select PHP Version | The platform cannot run. |
| 6 | The **web server's** PHP has `expose_php = Off` | Read `X-Powered-By` on a real response | Responses name the PHP patch level. Minor, but free to fix. |
| 7 | **Cron runs every minute** (section 3) | cPanel → Cron Jobs | Nothing is pruned; the session table grows without limit. |
| 8 | **Outbound HTTPS** to `api.pwnedpasswords.com` | `curl` from the account's shell | **No password can be set or changed**: the breach check refuses rather than accepting on failure. |
| 9 | **Outbound mail** works, from the configured sender | Send one invitation | Invitations and password resets cannot be delivered; nobody new can get in. |
| 10 | **MariaDB** reachable with production credentials | `php artisan migrate --pretend` | Nothing works. |
| 11 | **Filesystem permissions**: `storage/` and `bootstrap/cache/` writable by the PHP user | The production check reports this | The framework cannot write sessions, logs or caches. |
| 12 | **No web access to application-private files**: `.env`, `storage/`, `vendor/`, `composer.json`, the repository itself | Request each path over HTTPS and expect 403/404 | **`.env` is the application key and the database password.** Treat as a blocker. |

Items 1, 4 and 12 are load-bearing for security. The rest are correctness or operations.

## 5. Deploying the migration that invalidates sessions

The Phase 9 migration adds `accounts.security_generation` ([ADR 0025](../adr/0025-account-security-generation.md)). Sessions created before it carry no generation, and the check fails safe, so **every signed-in person is signed out once, at deployment**. Expected, one-time, and no data is affected. Nobody needs to do anything but sign in again.

## 6. Related runbooks

- [Rotate the application key](app-key-rotation.md) — and why `APP_KEY` is not a session secret.
- [Backup and restore](backup-and-restore.md) — what must be backed up *together*.
- [Recover a lost second factor](mfa-recovery.md).
- [Create the first (or a recovery) administrator](administrator-bootstrap.md).

## 7. Still not designed

Release and deployment procedure itself — how the build reaches the document root, how migrations are run, how a release is rolled back. The *topology* it must satisfy is recorded; the procedure is not, and `./flow` deliberately has no `release` command rather than an unsafe one.
