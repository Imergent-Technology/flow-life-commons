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
* * * * *   cd /home/<user>/commons/current && /usr/local/bin/php artisan schedule:run >> /home/<user>/commons/shared/storage/logs/schedule.log 2>&1
```

Every minute, one command. What it runs is `routes/console.php`, in source control, reviewed like any other code. **Do not add per-task cron entries**: they are invisible to review, drift between environments and are lost on a host migration. The same schedule works on cPanel now and on a VPS later without change.

Two details of that line are load-bearing and are explained in the [deployment runbook](deployment.md#5-scheduler): it goes through **`current`**, never a release directory, so it survives every deployment; and it logs rather than discarding output, so a silent failure is discoverable. The PHP path must be confirmed to be 8.3 for the cron user, which is not always the same PHP the web server runs.

Today the schedule holds one task, `identity:prune-expired`, hourly — idle database sessions, expired password-reset tokens and unproved authenticator secrets ([session maintenance](../architecture/identity-and-access.md)).

**If the cron entry is missing**, nothing prunes and the `sessions` table grows without limit. That is a capacity problem, not a security one — an idle session is refused on its `last_activity` whether or not its row is still there — but it will eventually fill the account's disk quota.

Verify it is working: `php artisan schedule:list` shows the task and its next run; after an hour, `select count(*) from sessions` should stop climbing.

## 4. Hosting checklist — owner verification

**Every item below is UNVERIFIED.** Each needs someone to check it on the real account. Nothing may be marked verified on the strength of a document, a default, or an expectation about how cPanel usually behaves.

The list is in two parts, and the split matters: the first part can **change the release design**, the second can only stop a deployment.

### 4a. Blocking release-model checks

**These decide whether the approved release model is possible at all** ([ADR 0027](../adr/0027-release-and-deployment-model.md)). Run them before any release tooling is implemented, because a failure here means redesigning rather than fixing.

| # | To confirm | How | If it is not available |
| --- | --- | --- | --- |
| R1 | **Apache/PHP-FPM correctly follow `current/public` after the symlink's target is atomically replaced** — a request after the swap serves the *new* release, not the old one | Deploy two trivial releases and swap between them; watch what is served | **The whole release model changes.** Falls back to rsync-in-place, which loses instant rollback entirely and leaves a window where the tree is a mixture of two releases. Grounds to evaluate the host. |
| R2 | **The exact operation that clears or reloads PHP-FPM/opcache** for this account | Try: restarting the PHP-FPM pool from cPanel's PHP selector; `cloudlinux-selector restart --interpreter php`; touching the docroot | Every release risks serving stale code for an unknown interval. The CLI's opcache is separate, so `php artisan` cannot do it. |
| R3 | **Atomic symlink rename works** | `cd /tmp && mkdir -p sa sb && ln -s sa L && ln -s sb L.next && php -r 'rename("L.next","L") or exit(1);' && readlink L` → expect `sb` | The swap needs another mechanism; `ln -sfn` is not atomic and is not an acceptable substitute. |
| R4 | The subdomain has an **independent document root** (`/home/<user>/commons/current/public`), not forced under `public_html`, not shared with WordPress — **and a docroot containing a symlink is accepted** | cPanel → Domains | The Console and API could not be isolated from the WordPress docroot, and the `current` indirection is impossible. Revisit before shipping. |
| R5 | **HTTPS is active** on the subdomain (AutoSSL or equivalent) | cPanel → SSL/TLS Status | **Hard blocker.** `__Host-` requires `Secure`, so the session cookie cannot be issued at all over plain HTTP. Nobody can sign in. |
| R6 | **`.htaccess` overrides are honoured** with `mod_rewrite` | Try a rewrite rule and observe | Routing, the SPA fallback and the maintenance arm must all move into server config. Same arrangement, no longer self-contained. |
| R7 | **`mod_headers` is enabled** | `Header set` in `.htaccess`, then read the response | **The Console's static files would ship with NO security headers** while the API keeps them ([ADR 0026](../adr/0026-production-browser-security-policy.md)). The origin is materially weaker; treat as a blocker. |
| R8 | **No web access to application-private files**: `.env`, `storage/`, `vendor/`, `composer.json`, the repository itself | Request each path over HTTPS and expect 403/404 | **`.env` is the application key and the database password.** Treat as a blocker. |

R1 and R2 are the highest-value checks in this document. They are the two that can invalidate a design decision rather than merely delay a deployment.

### 4b. Required first-deployment checks

These do not change the design. Each one stops the first deployment until it is satisfied.

| # | To confirm | Where | If it is not available |
| --- | --- | --- | --- |
| 1 | **PHP 8.3** for the subdomain with `pdo_mysql`, `mbstring`, `openssl`, `intl`, `bcmath`, `zip`, `fileinfo`, `ctype`, `tokenizer` | cPanel → MultiPHP Manager / Select PHP Version | The platform cannot run. |
| 2 | The **web server's** PHP has `expose_php = Off` | Read `X-Powered-By` on a real response | Responses name the PHP patch level. Minor, but free to fix. |
| 3 | **The cron user's PHP path, confirmed to be 8.3** | `/usr/local/bin/php -v` as the cron user | The scheduler runs under the wrong PHP, or not at all. cPanel's cron PHP is frequently not the web server's. |
| 4 | **Cron runs every minute** (section 3) | cPanel → Cron Jobs | Nothing is pruned; the session table grows without limit. |
| 5 | **`mysqldump` is available** over SSH | `mysqldump --version` | No pre-release backup, so no recovery point. The fallback is a manual phpMyAdmin export, which is materially worse and cannot be scripted. |
| 6 | **MariaDB** reachable with production credentials | `php artisan migrate --pretend` | Nothing works. |
| 7 | **Outbound HTTPS** to `api.pwnedpasswords.com` | `curl` from the account's shell | **No password can be set or changed**: the breach check refuses rather than accepting on failure. |
| 8 | **Outbound mail** works, from the configured sender | Send one invitation | Invitations and password resets cannot be delivered; nobody new can get in. |
| 9 | **Filesystem permissions**: `storage/` and `bootstrap/cache/` writable by the PHP user | The production check reports this | The framework cannot write sessions, logs or caches. |
| 10 | **Disk quota** sufficient for five retained releases (each carrying `vendor/`) plus backups | cPanel → disk usage; measure one artifact | Releases cannot be retained, so rollback has nothing to roll back to. Needs a real number, not an assumption. |

R5, R7, R8 and 4b/9 are load-bearing for security. The rest are correctness or operations.

## 5. Deploying the migration that invalidates sessions

The Phase 9 migration adds `accounts.security_generation` ([ADR 0025](../adr/0025-account-security-generation.md)). Sessions created before it carry no generation, and the check fails safe, so **every signed-in person is signed out once, at deployment**. Expected, one-time, and no data is affected. Nobody needs to do anything but sign in again.

## 6. Related runbooks

- [Release and deployment](deployment.md) — the procedure this checklist gates.
- [Rotate the application key](app-key-rotation.md) — and why `APP_KEY` is not a session secret.
- [Backup and restore](backup-and-restore.md) — what must be backed up *together*, and the restore contract.
- [Recover a lost second factor](mfa-recovery.md).
- [Create the first (or a recovery) administrator](administrator-bootstrap.md).

## 7. Designed, not built

The release and deployment procedure is now **decided** ([ADR 0027](../adr/0027-release-and-deployment-model.md)) and written up in the [deployment runbook](deployment.md): immutable release directories behind a `current` symlink, artifacts built off-host from an exact tag, migrations inside a short maintenance window, and a rollback path chosen by a human-supplied classification rather than by a tool.

**The tooling does not exist.** `./flow` still has no `release`, `backup` or `restore` command, and the Apache rules the design requires — the `/api` carve-out, the maintenance arm, the SPA fallback — are not yet in `public/.htaccess`. Those arrive in the implementation phase, after the section 4a checks are answered, because two of them can still change the design.
