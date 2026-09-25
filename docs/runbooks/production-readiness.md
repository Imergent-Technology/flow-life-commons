# Production readiness

**Purpose.** What must be true before the Guardian Console serves real people, and who can establish each thing.

**Owner.** Whoever holds the hosting account.

**Status (2026-09-22):** the application-side checks are automated and green in development. The hosting account **has been inspected and the platform has been deployed to it**: every item in section 4 was probed on the real host, all but one passed, and the first production deployment (v0.1.1, live) exercised the rest end to end, including the one item this checklist could not probe ahead of a real release — writable runtime directories (item 9). **The open item is outbound mail authentication**, deliberately deferred pending an organizational decision (section 5). Production currently has one administrator, by deliberate operational choice, not because bootstrap is incomplete.

The distinction running through this document is the only thing that makes it useful:

- **Verified here** — proved by a test or a command in this repository, on every run.
- **Owner verification** — can only be established on the real host, by a person. Section 4 records what was established, when, and what was measured.

A green `./flow check` says nothing about the second column. Do not treat it as though it does.

Nothing in section 4 is marked verified by inference. Each row was observed directly; where a probe proved something narrower than the row's claim, the row says so.

---

## 1. Before anything: what production is

One origin, `https://commons.flowlifeglobal.org`, serving the Guardian Console's static build and the Laravel API from one document root ([deployment topology](../architecture/deployment-topology.md), [ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)). No Docker, no Node, no Redis, no resident daemons — cPanel shared hosting with Apache, PHP 8.3 and MariaDB ([charter](../architecture/charter.md)).

### The measured environment (2026-09-21)

| | Measured |
| --- | --- |
| Web server | **Apache** (`Server: Apache`), on **CloudLinux** |
| PHP handler | **LSAPI / `mod_lsapi`** — so `PHP_SAPI` reports `litespeed`. This is **not** LiteSpeed Web Server: `/usr/local/apache` and `/opt/alt` are present, `/usr/local/lsws` is not, and MariaDB reports `cll-lve` |
| Web PHP | 8.3.33, all required extensions present |
| Interactive CLI PHP | `/usr/local/bin/php` — 8.3.33, `cli` |
| **Cron PHP** | bare `php` resolves to `/usr/bin/php` — 8.3.33, **`cgi-fcgi`**. See section 3 |
| Database | `10.11.18-MariaDB-cll-lve` — matches the development engine |
| Opcache | enabled; `validate_timestamps=1`, `revalidate_freq=2`, `realpath_cache_ttl=120` |
| Edge | **Direct to origin.** No proxy, CDN or intermediary page cache. `commons` resolves to `107.180.115.24`; the WordPress apex resolves elsewhere and sits behind Sucuri/Cloudproxy ([trust boundaries](../architecture/trust-boundaries.md)) |
| Account quota | 4.76 GB used of 75 GB |

**Do not read `PHP_SAPI = litespeed` as evidence of LiteSpeed Web Server**, and do not add an LSCache purge step on the strength of it. That string comes from the PHP handler, not the web server, and no LiteSpeed cache is present.

## 2. Application configuration — run the command

```
php artisan security:production-check
```

(`./flow doctor --production` runs the same thing in the development container, against the development environment file, where it is *expected* to fail.)

It reads the configuration this deployment is actually running under and refuses anything dangerous: `APP_ENV`, `APP_DEBUG`, an `http://` application URL, `APP_KEY` presence and size, the no-op breached-password checker, a test-speed bcrypt cost, every session-cookie invariant, the session driver and lifetimes, the raised development login limit, the reset-response floor, CORS, PHP version and extensions, writable runtime directories, a maintenance driver other than `file` (under which `php artisan down` never writes the file Apache's maintenance arm reads), missing database credentials or the development ones, any cache or queue store needing a service this host does not run, and PHP `mail()` as the transport. No check prints a secret: a failing `APP_KEY` or database password is named, never shown.

Start the production file from **`apps/platform/.env.production.example`**, not the development `.env.example` ([deployment runbook](deployment.md), first deployment step 3). A test loads that template as the environment, fills in only the four operator-supplied values, and runs this command against it — so the template and the check cannot drift apart without the build failing.

Deferred decisions are reported in their own section, **Deliberately open**, and never fail the command: a check that always fails on a correct deployment is one people stop reading. Today the only item there is outbound mail (section 5).

Three of those deserve naming, because all come from copying `apps/platform/.env.example`:

- **`IDENTITY_COMPROMISED_PASSWORD_CHECK=none`** — development and CI set it so the gate needs no network. In production it means known-breached passwords are accepted. The container refuses `none` outside `local` and `testing`, so this fails loudly rather than silently; the check catches it earlier.
- **`IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP=200`** — the browser suite needs it. Production keeps 30.
- **`IDENTITY_MFA_MAX_PER_IP=200`** — the same, for second-factor code attempts per address. Production keeps 30, and the check refuses anything higher.

The command ends by printing what it cannot see. That list is section 4.

## 3. The scheduler: one cron entry, and everything else in source control

**This is the production maintenance contract**, and it is one line:

```
* * * * *   cd /home/<user>/commons/current && /usr/local/bin/php artisan schedule:run >> /home/<user>/commons/shared/storage/logs/schedule.log 2>&1
```

Every minute, one command. What it runs is `routes/console.php`, in source control, reviewed like any other code. **Do not add per-task cron entries**: they are invisible to review, drift between environments and are lost on a host migration. The same schedule works on cPanel now and on a VPS later without change.

Three details of that line are load-bearing and are explained in the [deployment runbook](deployment.md#6-scheduler). It goes through **`current`**, never a release directory, so it survives every deployment. It logs rather than discarding output, so a silent failure is discoverable. And the PHP binary is **absolute**, which is not stylistic:

> **Verified 2026-09-21.** Bare `php` under cron on this host resolves to `/usr/bin/php`, which is **`cgi-fcgi`**, not `cli`. That SAPI parses arguments differently and writes HTTP headers to stdout, so `schedule:run` under it is unreliable and its log fills with `Content-type` lines. Running `/usr/local/bin/php` *from cron* was tested directly and reports `cli 8.3.33`, which is what the line above uses.
>
> **Never write bare `php` in a cron entry on this host.**

Cron firing once per minute is verified.

Today the schedule holds one task, `identity:prune-expired`, hourly — idle database sessions, expired password-reset tokens and unproved authenticator secrets ([session maintenance](../architecture/identity-and-access.md)).

**If the cron entry is missing**, nothing prunes and the `sessions` table grows without limit. That is a capacity problem, not a security one — an idle session is refused on its `last_activity` whether or not its row is still there — but it will eventually fill the account's disk quota.

Verify it is working: `php artisan schedule:list` shows the task and its next run; after an hour, `select count(*) from sessions` should stop climbing.

## 4. Hosting checklist — owner verification

**Probed on the real account on 2026-09-21, then exercised for real by the first production deployment on 2026-09-22.** The list is in two parts: the first could have **changed the release design**, the second could only stop a deployment.

### 4a. Blocking release-model checks — all verified

These decided whether the approved release model was possible at all ([ADR 0027](../adr/0027-release-and-deployment-model.md)). **All eight passed**, so the release model freezes as designed.

| # | Claim | Status | What was measured |
| --- | --- | --- | --- |
| R1 | A request after the release symlink's target is atomically replaced serves the **new** release, for both PHP and static content | **Verified 2026-09-21** | Two trivial releases, A and B, swapped by `rename(2)`. The **first** observation after the swap already showed `php=B`, `static=B`, and `__FILE__` resolved under `releases/b`. Thirty iterations, no staleness at any point. |
| R2 | An opcache clear or PHP reload is available after a swap | **Verified unnecessary 2026-09-21** | No stale bytecode or realpath was ever observed, so no reset, restart or wait is required. `validate_timestamps=1`, `revalidate_freq=2`, `realpath_cache_ttl=120` would have bounded any staleness in any case. **No reset step is in the procedure**, deliberately. |
| R3 | Atomic symlink replacement works | **Verified 2026-09-21** | `ln -s` to a temporary name, then PHP `rename()`; `readlink` reported the new target. `ln -sfn` remains rejected — it is not atomic. |
| R4 | The subdomain takes an **independent document root**, not under `public_html`, not shared with WordPress, **and accepts a path through a symlink** | **Verified 2026-09-21** | Docroot pointed at `current/public` and served correctly through the symlink. See the cPanel path quirk in section 6. |
| R5 | **HTTPS is active** on the subdomain | **Verified 2026-09-21** | `HTTP/2 200` over TLS on `commons.flowlifeglobal.org`. |
| R6 | **`.htaccess` overrides honoured**, with `mod_rewrite` | **Verified 2026-09-21** | A rewrite rule in `.htaccess` resolved as written. The rewrite condition also tested a file **outside** the document root, through the storage symlink (`%{DOCUMENT_ROOT}/../storage/framework/down -f`), which the maintenance model depends on. |
| R7 | **`mod_headers` is enabled** | **Verified 2026-09-21** | A `Header always set` inside `<IfModule mod_headers.c>` reached the client, so the generated security-header block from [ADR 0026](../adr/0026-production-browser-security-policy.md) will be effective. |
| R8 | **No web access to application-private files** | **Verified 2026-09-21** | `/.env` → 403, `/../shared/.env` → 403, `/%2e%2e/shared/.env` → 403, a path into a release directory → 404. Nothing private returned 200. |

**Proven locally at two levels; the real file has still never served a release.** The composed contract — security headers, private-path denials, the maintenance arm, the API and `/up` carve-out and the SPA fallback, in that order — is exercised end to end in a real browser against Caddy, the production-equivalent development gateway (`apps/guardian-console/e2e/production-surface.spec.ts`), and the committed `.htaccess` is pinned structurally, order included, by `ProductionSurfaceTest`. That proves the **contract**, not Apache: there is no Apache in that origin.

Separately, `scripts/tests/apache-surface.sh` runs the real `.htaccess` and `maintenance.php` under a disposable Apache container and asserts the routing and header behaviour directly. This is the level that found an Apache-specific defect the Caddy-driven suite could not see: the API/`/up` rewrite's `[L]` let Apache's own internal redirect re-run the ruleset and the maintenance rule catch the rewritten request on a second pass, sending `/api` and `/up` to the HTML responder instead of Laravel while the flag was raised — fixed with `[END]` (ADR 0027, *Verified on the production host*). A paired header-duplication defect was found and fixed the same way.

Neither level is the production host: LSAPI on CloudLinux is not a container's mod_php. **The first real deployment (2026-09-22) has since exercised the file on the actual host** — maintenance routing, the `/api`/`/up` carve-out, private-path denials, and the security-header block all matched what the per-rule host probes (above), the Caddy contract test and the Apache-container test predicted.

### 4b. First-deployment checks

| # | Claim | Status | What was measured |
| --- | --- | --- | --- |
| 1 | **PHP 8.3** for the subdomain with the required extensions | **Verified 2026-09-21** | Web SAPI reports 8.3.33; `pdo_mysql`, `mbstring`, `openssl`, `intl`, `bcmath`, `zip`, `fileinfo`, `ctype`, `tokenizer` all present. |
| 2 | The PHP version is not advertised to clients | **VERIFIED on production 2026-09-22, after a fix and a redeploy** | The 2026-09-21 CLI-only probe read `expose_php` as **On** and (wrongly) concluded no `X-Powered-By` header reached clients. The first deployment rehearsal (2026-09-22, real Apache + CloudLinux LSAPI, release v0.1.0) showed otherwise: **every PHP response carried `X-Powered-By: PHP/8.3.33`** — the maintenance responder and Laravel's own JSON/HTML responses alike. The hosting account's "Select PHP Version" UI has no `expose_php` toggle and there is no MultiPHP INI Editor for this account, so the ini setting cannot be changed from the host. `public/.htaccess` now strips the header at the origin instead (`Header onsuccess unset X-Powered-By` and `Header always unset X-Powered-By` — both, because LSAPI was observed populating either of Apache's two response-header tables); a real-Apache container test (`scripts/tests/apache-surface.sh`) proves this against `expose_php=On`. **Re-verified on the production host the same day**, after deploying the fix as v0.1.1: `X-Powered-By` was absent from `/`, `/api/v1/health` and `/up`, under maintenance and after `artisan up`. `expose_php` reading `On` in the CLI or web ini is not itself a finding, and may remain `On` indefinitely; the client-visible header is what is enforced, and it stays absent. |
| 3 | The cron PHP binary is 8.3 **and the `cli` SAPI** | **Verified 2026-09-21** | `/usr/local/bin/php` run *from cron* reports `cli 8.3.33`. Bare `php` resolves to `/usr/bin/php`, `cgi-fcgi` — see section 3. |
| 4 | **Cron runs every minute** | **Verified 2026-09-21** | A one-minute probe entry fired on schedule. |
| 5 | **`mysqldump` is available**, with shared-hosting-safe flags | **Verified 2026-09-21** | `/usr/bin/mysqldump`, Ver 10.19 Distrib 10.11.18-MariaDB. A real dump with `--no-tablespaces --single-transaction --quick` succeeded (1,332 bytes on an empty database). `--no-tablespaces` is required: shared-hosting users lack the `PROCESS` privilege. |
| 6 | **MariaDB reachable** with production credentials | **Verified 2026-09-21** | `10.11.18-MariaDB-cll-lve`, matching the development engine. |
| 7 | **Outbound HTTPS** to `api.pwnedpasswords.com` | **Verified 2026-09-21** | HTTP 200 from the account's shell. |
| 8 | **Outbound mail works from the configured sender** | **OPEN — deferred.** See section 5 | SMTP ports 25/465/587 are open locally, and a PHP `mail()` test was delivered — but **not DMARC-aligned and unsigned**. Not production-approved. |
| 9 | **Filesystem permissions**: `storage/` and `bootstrap/cache/` writable by the PHP user | **VERIFIED on production 2026-09-22** | Not separately probed ahead of a release, because the release tree did not exist yet. `security:production-check` reported this green at first deployment and again on the v0.1.1 redeployment. |
| 10 | **Disk quota** sufficient for five retained releases plus backups | **Verified 2026-09-21** | 4.76 GB used of **75 GB** (6.34%), ~70 GB free. At ~65 MB per release, five retained releases are ~325 MB — comfortably supported. The earlier `df` figure measured the shared filesystem, not the account, and did not establish this. |

R5, R7 and R8 are load-bearing for security. The rest are correctness or operations.

**A refinement to R8, found during the 2026-09-22 deployment: not every 403 on a private path carries the ADR 0026 security-header block, and one of them should not.** `GET /.env` is denied by the **host** — a generic, cPanel-style 403 page returned before Apache ever reaches the application's own `.htaccess` response-header rules — so that particular 403 has no application header block on it. This is not evidence `.env` is reachable (it still never returns anything but 403) and not a weakening of the invariant: the application's own private-path denials (`/release.json`, `/vendor/autoload.php`, `/storage/logs/laravel.log`) run independently, return 403, and **do** carry the full seven-header policy, because those are answered by the application's own rewrite rules rather than intercepted upstream of them. Do not read the `/.env` case as license to assume any 403 elsewhere lacks headers — it is specific to this one host-level interception.

**CLOSED — the storage seeding step on later deployments** (it was open before the second deployment, not the first).

*Historical finding.* The deployment runbook seeds `shared/storage` with `cp -an` ([first deployment step 6](deployment.md#6-wire-the-shared-links)). That is safe on the first deployment, when `shared/storage` is empty. It is **not** a harmless no-op afterwards: it re-applies mode and mtime to persistent directories that already exist (measured in the 2026-09-21 re-audit). Before any later deployment, use `cp -rn` instead of `cp -an` — **confirmed directly on the production host's own `cp`**: an existing directory kept its mode (`2775` stayed `2775`), existing file content was untouched, and a missing skeleton path was added. This is not the same claim as "mtime never changes": a directory's own mtime still moves when a new entry is added to it, which is ordinary filesystem behaviour and not something `cp -rn` (or any copy that adds a path) avoids. The invariant `cp -rn` gives is that it does not overwrite existing content and does not reapply the skeleton's metadata onto what already exists — nothing stronger.

*Resolution.* `cp -an` was the problem: safe against an empty `shared/storage`, but on a later release it re-applies mode and mtime to directories that already exist. Subsequent deployments use `cp -rn` instead ([subsequent deployment step 2](deployment.md#2-upload-checksum-extract-wire)), which was verified on the real production host, as above, and the procedure has since been exercised successfully in subsequent deployments ([later deployments](deployment.md#11-later-deployments)). First deployment step 6 is unchanged: it is still `cp -an`, correct only for the first deployment. Nothing remains open here.

## 5. Outbound mail — the one open item

**Status: OPEN, deliberately deferred** pending an organizational decision about Flow Life's mail arrangement. This does **not** block release tooling, and it is not a defect in the platform. It blocks *inviting people*, which is the last thing first deployment does.

It matters more here than on most platforms: this is an invite-only directory, so **the invitation email is the onboarding path**. A message that silently lands in spam is an outage that looks like nothing at all.

### What was measured on 2026-09-21

| | Finding |
| --- | --- |
| SMTP ports | 25, 465, 587 open locally — a relay exists |
| SPF | Present: `v=spf1 include:dc-db9e4b7a04._spfm.flowlifeglobal.org ~all` |
| DMARC | Present: `v=DMARC1; p=none; rua=...` |
| MX | `mx1-us1.ppe-hosted.com`, `mx2-us1.ppe-hosted.com` |
| DKIM | Nothing at the `default._domainkey` selector. **This does not prove DKIM is absent** — the selector may differ |
| cPanel Email Deliverability tool | Not exposed on this GoDaddy account |

A test through local PHP `mail()` **was delivered to Gmail**, and that is the misleading part. Gmail's authentication results showed:

- **SPF pass** — but only for the host-generated envelope sender, `mk237kzpoprj@…prod.phx3.secureserver.net`
- **No `DKIM-Signature`**
- **`dmarc=fail`**, because the visible `From:` was `commons@flowlifeglobal.org` and the Return-Path was not aligned with it

Forcing the envelope sender with `mail()`'s fifth argument (`-f`) was **rejected** by the local transport with `bad addresses found in headers`.

> **Local PHP `mail()` is not approved as the production invitation transport** in its current configuration. It delivers, it is unsigned, and it fails DMARC alignment.

### The decision to be made

The founder needs to settle Flow Life's mail topology before anything is configured. `info@flowlifeglobal.org` is currently on GoDaddy's paid email service, and the options include retaining it, adding a paid mailbox for Commons, migrating `info@` to cPanel-hosted mail, using cPanel mail for Commons, or using an authenticated transactional SMTP provider.

A DKIM-related control is visible in the GoDaddy hosting settings. **Mail routing and DKIM configuration are deliberately unchanged** until the intended topology is chosen — changing them piecemeal risks breaking delivery for the existing mailbox.

### The follow-up verification, once the strategy is chosen

1. Configure the intended authenticated SMTP path.
2. Verify **SPF alignment for the actual envelope sender**, not just an SPF pass.
3. Verify **DKIM signing** is present.
4. Verify **DMARC passes**.
5. Send a **real Commons invitation** through the application.
6. Verify **inbox rather than spam** placement.
7. Record the production sender configuration here.

## 6. The cPanel document-root quirk

Worth knowing before first deployment, because it wastes time and looks like a permissions fault.

**This cPanel Domains UI prepends the account home directory to whatever you type.** Supplying an absolute path produces a doubled path:

```
typed:     /home/<user>/commons/current/public
resolves:  /home/<user>/home/<user>/commons/current/public     ✗
```

Supply the **home-relative** value instead:

```
typed:     commons/current/public
resolves:  /home/<user>/commons/current/public                 ✓
```

Confirmed on 2026-09-21 with the probe tree (`commons-probe/current/public`). Assuming the UI behaves the same at first deployment, the real value is **`commons/current/public`**.

Elsewhere in these runbooks, paths are written absolute because shell commands need them that way. **Only the cPanel Domains field takes the relative form.**

## 7. Deploying the migration that invalidates sessions

The Phase 9 migration adds `accounts.security_generation` ([ADR 0025](../adr/0025-account-security-generation.md)). Sessions created before it carry no generation, and the check fails safe, so **every signed-in person is signed out once, at deployment**. Expected, one-time, and no data is affected. Nobody needs to do anything but sign in again.

## 8. Related runbooks

- [Release and deployment](deployment.md) — the procedure this checklist gates.
- [Rotate the application key](app-key-rotation.md) — and why `APP_KEY` is not a session secret.
- [Backup and restore](backup-and-restore.md) — what must be backed up *together*, and the restore contract.
- [Recover a lost second factor](mfa-recovery.md).
- [Create the first (or a recovery) administrator](administrator-bootstrap.md).

## 9. Designed and validated, not built

The release and deployment procedure is **decided** ([ADR 0027](../adr/0027-release-and-deployment-model.md)) and written up in the [deployment runbook](deployment.md): immutable release directories behind a `current` symlink, artifacts built off-host from an exact tag, migrations inside a short maintenance window, and a rollback path chosen by a human-supplied classification rather than by a tool.

**The host probes validated that design rather than reopening it.** Every check that could have forced a redesign passed, including the two highest-risk ones — the symlink swap being observed immediately, and the maintenance flag being visible to `.htaccess` through the storage symlink.

**The host-side tooling does not exist, by design.** `./flow release` builds and inspects artifacts on a developer machine ([deployment runbook §8](deployment.md#8-flow-release-the-developer-side-tooling)); `./flow` has no `backup`, `restore` or `deploy` command and will not. The **public surface is built**: `public/.htaccess` carries the composed rules and `public/maintenance.php` is the responder, both proved as a whole in a browser against the production-equivalent origin and required by the artifact validator. **The design was first carried out manually, in full, on 2026-09-22, and has been repeated since ([later deployments](deployment.md#11-later-deployments))** — the first production deployment closed item 9 above (writable production paths confirmed) and brought v0.1.1 live ([deployment runbook §10](deployment.md#10-first-production-deployment-closure-2026-09-22)). Outbound mail remains the one open item, and host-side automation remains deliberately unbuilt.
