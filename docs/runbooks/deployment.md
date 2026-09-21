# Runbook: release and deployment

- **Purpose:** put a release of the Commons platform onto the production host, and get it off again when it is wrong.
- **Owner:** whoever holds the hosting account and server access.
- **Design:** [ADR 0027](../adr/0027-release-and-deployment-model.md). Read it once before the first deployment; this runbook assumes its decisions rather than re-arguing them.
- **Last tested:** the **host capabilities** this procedure depends on were probed directly on 2026-09-21 and are recorded in [production readiness](production-readiness.md), section 4. **The procedure itself has never been executed end to end.**

> **Status: the mechanisms are proven on this host; the procedure is not yet rehearsed.**
>
> The two placeholders this runbook previously carried are gone. The `current` symlink swap **is** observed immediately by both PHP and static requests, and **no opcache reset, restart or wait is required** — measured, not assumed. The maintenance mechanism is verified end to end, including the rewrite condition reading the flag through the storage symlink.
>
> **One host item remains open: outbound mail authentication** ([production readiness](production-readiness.md), section 5), deferred pending an organizational decision. It does not block deployment; it blocks inviting people, which is step 14.
>
> **The composed public surface now exists and is proved locally.** `public/.htaccess` carries the security headers, the private-path denials, the maintenance arm, the API and `/up` carve-out and the SPA fallback, in that order, and `public/maintenance.php` is the responder. The whole contract is driven in a real browser against the production-equivalent origin, and the file's structure and rule order are pinned by a test. **What that does not do is run Apache** — the host's own evidence is still the per-rule probes of 2026-09-21, so the file itself is first exercised by the first real deployment.

**Host environment, measured 2026-09-21:** Apache on CloudLinux, PHP 8.3.33 via LSAPI/`mod_lsapi` (so `PHP_SAPI` reads `litespeed` — this is **not** LiteSpeed Web Server), MariaDB `10.11.18-MariaDB-cll-lve`, direct to origin with no proxy or CDN in front.

---

## 0. The shape of it

```
/home/<user>/commons/
  releases/
    20260921T140311-96c9eb1/      immutable; UTC timestamp + short commit sha
      artisan  app/  bootstrap/  config/  database/  routes/  vendor/
      public/     index.php  .htaccess  maintenance.php
                  index.html  assets/  favicon.ico  robots.txt
      release.json
      storage -> ../../shared/storage
      .env    -> ../../shared/.env
  shared/
    .env                          0600. The only secret on the host.
    storage/                      logs, framework/ (holds the maintenance flag), app/
    deploy.log
  backups/
    20260921T140311Z/             dump.sql.gz + manifest.json
  current -> releases/20260921T140311-96c9eb1
  current.next                    exists only for an instant during a swap
```

Document root for `commons.flowlifeglobal.org` is **`/home/<user>/commons/current/public`** and nothing above it.

Three properties worth holding in mind, because several steps depend on them:

- **`bootstrap/cache/` is per-release.** Cached config belongs to the code it was cached against.
- **`storage/` is shared**, so the maintenance flag survives the release switch: you go down in the old release and come up in the new one.
- **The previous release stays intact on disk.** That is what makes code rollback a symlink operation.

---

## 1. First deployment

Different from every later release, and gated. Steps 1 and 10 are the ones that can stop everything.

### 1. Complete owner/host verification

Work through [production readiness](production-readiness.md) in full. **On this account, section 4a was probed on 2026-09-21 and every blocking check passed**, so this step is a re-confirmation rather than an open question — re-run it if the hosting account, plan or PHP version has changed since. The one open item is outbound mail (section 5), which blocks step 14, not step 2.

### 2. Create the directory structure and point the docroot

```bash
mkdir -p /home/<user>/commons/{releases,shared/storage,backups}
```

Set the subdomain's document root in cPanel → Domains. **Type the home-relative value, not an absolute path:**

```
commons/current/public
```

This cPanel UI prepends the account home directory itself, so an absolute `/home/<user>/…` produces a doubled path that silently fails ([production readiness](production-readiness.md#6-the-cpanel-document-root-quirk)). Confirmed on 2026-09-21.

Confirm HTTPS serves that host before continuing: without it the `__Host-` session cookie cannot be issued at all and nobody can sign in ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)).

### 3. Install the production environment file

Start from **`apps/platform/.env.production.example`** in the repository, at the commit you are releasing. It is documentation, not artifact content: the release validator refuses any `.env*` file in an artifact, so it is never inside the tarball, and the real file never leaves the host. Copy it to the host as `shared/.env` and lock it down:

```bash
chmod 600 /home/<user>/commons/shared/.env
```

**Do not start from `apps/platform/.env.example`**: that is the development file, and its `APP_DEBUG=true` and `IDENTITY_COMPROMISED_PASSWORD_CHECK=none` are exactly the values that must never reach production. The template carries production values throughout and leaves blank only what the operator must supply:

| Setting | Where it comes from |
| --- | --- |
| `APP_KEY` | step 4, below |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | cPanel → MySQL Databases. cPanel prefixes both names with the account name, so copy what it shows rather than what you typed |

Every other value is already correct for this host and should be left alone unless something about the host has changed. Two that look optional are not: `APP_MAINTENANCE_DRIVER=file` is what makes `php artisan down` write the file Apache's maintenance arm reads, and `MAIL_MAILER=log` is the documented deferral of outbound mail (see [production readiness](production-readiness.md#5-outbound-mail--the-one-open-item)) — it sends nothing, deliberately.

Nothing in the template trusts a proxy, and there is no variable that would: Commons is direct to origin, and trusting a proxy is a reviewed code change ([trust boundaries](../architecture/trust-boundaries.md)).

### 4. Generate and record `APP_KEY`

No release is on the host yet (that is step 5), so `artisan` is not available here. Generate the key with the host's PHP directly — this is exactly what `php artisan key:generate --show` does for the application's cipher, 32 random bytes, base64-encoded:

```bash
/usr/local/bin/php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
```

Paste it into `shared/.env` as `APP_KEY`. **Record it in the owner's secure secret store together with today's date**, because every database backup from now on is only restorable alongside it ([backup and restore](backup-and-restore.md)). The key is generated on the host and goes nowhere else except that store. `security:production-check` (step 12) refuses a key of the wrong size, and never prints the key itself.

### 5. Upload, checksum and extract the release

```bash
sha256sum -c commons-<version>.tar.gz.sha256      # must pass before extracting
mkdir -p /home/<user>/commons/releases/<release-id>
tar -xzf commons-<version>.tar.gz -C /home/<user>/commons/releases/<release-id>
```

### 6. Wire the shared links

```bash
cd /home/<user>/commons/releases/<release-id>
cp -an storage/. ../../shared/storage/      # seed shared/storage from the artifact's skeleton; never overwrites
rm -rf storage && ln -s ../../shared/storage storage
ln -s ../../shared/.env .env
```

The **seeding line matters on the first deployment**: `shared/storage` starts empty, and `php artisan down` fails there (`file_put_contents(storage/framework/down): No such file or directory`) because `storage/framework/` does not exist. The artifact ships the directory skeleton (placeholder `.gitignore` files only, never runtime state); `cp -an` copies it into `shared/` once and is a harmless no-op on every later release.

Confirm `storage/` and `bootstrap/cache/` are writable by the PHP user.

### 7. Migrate the empty database

```bash
php artisan migrate --force
```

### 8. Build the caches

```bash
php artisan config:cache
php artisan event:cache
php artisan route:cache
```

**Not `php artisan optimize`.** It includes `view:cache`, which fails on this repository because there is no `resources/views`, and it fails *after* writing the other three caches — so a script that runs it and checks the exit status aborts with the release half-optimized. The reasoning is in [ADR 0027](../adr/0027-release-and-deployment-model.md), *Optimization model*.

### 9. Activate the release

```bash
cd /home/<user>/commons
ln -s releases/<release-id> current.next
php -r 'rename("current.next","current") or exit(1);'
readlink current        # confirm it names the new release
```

`rename(2)` replaces the symlink atomically. **Do not use `ln -sfn`**: it unlinks before re-linking, so `current` is briefly absent, and without `-n` it creates the link inside the target directory.

### 10. Nothing — the swap needs no opcache step

**Verified 2026-09-21: there is no reset, restart or wait to perform.**

Two releases were swapped by atomic `rename(2)` and observed over thirty iterations. The **first** request after the swap already served the new release, for PHP and static content alike, with `__FILE__` resolving under the new release directory. No stale bytecode, no stale realpath, at any point.

The host's settings would have bounded any staleness regardless: `opcache.validate_timestamps=1`, `opcache.revalidate_freq=2`, `realpath_cache_ttl=120`.

**Do not add a reset step "just in case."** It would be unverified cargo — nobody would know whether it worked, and it would mask a regression rather than reveal one. If a future release ever *does* serve stale code, that is a real change in host behaviour: re-run the A/B swap probe before adding anything, and record what changed.

### 11. Install the scheduler cron

See [§5](#6-scheduler). One entry, through `current`.

### 12. CLI verification

See [§6](#7-health-and-release-verification). All of it must pass, `security:production-check` included, with no "clean except".

### 13. HTTP and security verification

See [§6](#7-health-and-release-verification), including every negative check. `.env`, `vendor/` and the storage logs must not be reachable.

### 14. Administrator bootstrap ceremony

Follow [administrator bootstrap](administrator-bootstrap.md). Over SSH, never HTTP; the invitation token is delivered out of band.

### 15. Complete the first administrator's MFA enrolment

The ceremony is **not finished** when the invitation is accepted. It is finished when that administrator has signed in, enrolled an authenticator, and **stored their ten recovery codes**, which are shown once. Confirm this before moving on.

### 16. Create a second administrator

Promptly, and for a different person. The last-administrator invariant ([ADR 0020](../adr/0020-administrator-bootstrap-and-last-administrator-invariant.md)) means a single administrator is a single point of failure whose loss requires server access to repair.

### 17. Record the deployment

Append to `shared/deploy.log`: date, release id, version tag, commit, who deployed it, and the verification result.

---

## 2. Subsequent deployment

Steps 4 to 10 are the maintenance window. Everything before it is reversible by deleting a directory.

### 1. Have a built, gated artifact

Built off-host from an annotated tag, with its gate green and its `schema_rollback` classification set ([§8](#8-flow-release-the-developer-side-tooling)). Know that classification **before** you start; it is what you will act on if step 11 fails.

### 2. Upload, checksum, extract, wire

As first deployment steps 5 and 6. The new release is on disk and serving nothing.

### 3. Warm the caches

```bash
cd /home/<user>/commons/releases/<new-release-id>
php artisan config:cache
php artisan event:cache
php artisan route:cache
```

Safe to do before the window, because this release is not yet serving.

### 4. Enter maintenance

```bash
cd /home/<user>/commons/current
php artisan down
```

**The window starts here.** Confirm: `/api/v1/health` returns 503, and the Console shell returns the maintenance page rather than loading.

### 5. Take the pre-migration backup

See [§4](#5-taking-a-backup). Inside the window, deliberately: a backup taken before maintenance leaves a gap of live writes that a restore would lose.

### 6. Migrate

```bash
php artisan migrate --force
```

### 7. Switch `current`

```bash
cd /home/<user>/commons
ln -s releases/<new-release-id> current.next
php -r 'rename("current.next","current") or exit(1);'
readlink current
```

### 8. No opcache step

Nothing to do — see first-deployment step 10. The swap is observed immediately on this host.

### 9. CLI verification, still in maintenance

The CLI half of [§6](#7-health-and-release-verification): `release:show`, `about`, `migrate:status`, `security:production-check`, `schedule:list`. Do as much as possible here, while nothing is exposed.

### 10. Leave maintenance

```bash
cd /home/<user>/commons/current
php artisan up
```

### 11. HTTP and security verification, immediately

The HTTP half of [§6](#7-health-and-release-verification). Run it now, not after a coffee: this is the only unverified-exposure window in the procedure and the point is to keep it short.

### 12. On any failure

```bash
php artisan down
```

then go to [§3](#3-rollback) and follow the release's `schema_rollback` classification. **Do not improvise this under pressure — the classification was decided when there was time to think.**

### 13. Retain five releases

Delete the oldest beyond five. Never delete the release `current` points at, nor the one before it.

### 14. Record the result

Append to `shared/deploy.log`, including failures and what was done about them.

---

## 3. Rollback

**Which procedure applies is not a judgement call at the time.** It is the `schema_rollback` value in the release's `release.json`, decided by a person when the artifact was built ([ADR 0027](../adr/0027-release-and-deployment-model.md), *Migration rollback classification*).

```bash
php artisan release:show        # among other things, prints schema_rollback
```

### `not-applicable` — no migrations in this release

1. `php artisan down` (if not already).
2. Point `current` back at the previous release, atomically, as in step 7 above.
3. No opcache step is needed (see first-deployment step 10).
4. CLI verification.
5. `php artisan up`, then HTTP verification.

Nothing was done to the database. Nothing is lost.

### `code-only` — the previous release runs against the new schema

Same five steps, with one addition between 3 and 4: **verify the previous release against the already-migrated schema** before coming up. `php artisan about` and `php artisan migrate:status` from the restored release, and exercise one authenticated read if you can. The schema stays migrated; you are relying on the classification that the old code tolerates it.

Nothing is lost.

### `restore-required` — the database must go back too

**Maintenance stays on for the whole of this.**

1. `php artisan down` — and confirm it, because everything below assumes no writes are arriving.
2. Point `current` back at the previous release. No opcache step is needed.
3. **Restore the pre-release backup** — follow [backup and restore](backup-and-restore.md), including the keyring verification, which happens *before* the database is touched.
4. Reconcile invitations (see that runbook): revoke any invitation whose account is already active.
5. CLI verification against the restored release and restored database.
6. `php artisan up`, then HTTP verification.
7. Record in `shared/deploy.log` what was lost (see below).

> **Data loss is explicit here.** Everything written between the pre-migration backup and the moment maintenance was re-entered is gone. Because the backup is taken inside the maintenance window, that is normally only what happened during the failed release itself — but if the release was up and serving for a while before the failure was noticed, it includes every sign-in, every audit event and every administrative act in that period.
>
> **The audit trail loses that period too** ([ADR 0019](../adr/0019-security-event-auditing-seam.md)). Record in the deploy log what the gap was, because `security_events` can no longer tell you.

**`migrate:rollback` is not a recovery mechanism.** Every migration in this application creates or adds; their `down()` methods destroy data. Do not reach for it here.

---

## 4. Maintenance mode

**One authority, two enforcement points.** Verified end to end on this host on 2026-09-21.

The authority is **`shared/storage/framework/down`**, written by `php artisan down` and removed by `php artisan up`. Nothing else creates or deletes it, and there is no second flag. Because `storage/` is shared across releases, the state **survives the `current` swap**: you go down in the old release and come up in the new one, with nothing to carry across.

(Laravel also writes `storage/framework/maintenance.php`, a handler shim the front controller loads, whose first act is to check for `down`. `up` removes both. The flag is `down`; the shim is the mechanism that reads it.)

- **Laravel covers `/api/*` and `/up`.** Its shim negotiates content, so JSON callers get a JSON 503. Apache must not intercept these.
- **Apache covers the static half** — the Console shell, client-side routes and assets, which PHP never sees. It reads the same file.

### The rule

```apache
RewriteCond %{DOCUMENT_ROOT}/../storage/framework/down -f
RewriteCond %{REQUEST_URI} !^/(api($|/)|up$|maintenance\.php$)
RewriteRule ^ /maintenance.php [L]
```

An **internal rewrite** with `[L]`. The status comes from PHP, not from the rewrite engine.

The exclusion reads `api($|/)` rather than the `api/` this runbook carried while the rule was still a sketch. A bare `/api`, with no trailing slash, is an API request: the development gateway routes it to Laravel and the browser suite asserts it answers a JSON 404. Under `api/` it would have been the one API path rewritten to an HTML 503, which is the failure this carve-out exists to prevent.

### The responder

`public/maintenance.php` ships in the artifact as a standalone PHP file. **It must not load Laravel, `vendor/` or `.env`** — the situation it exists for includes a release that is broken or half-installed. It sets:

```
http_response_code(503)
Retry-After: 120
Content-Type: text/html; charset=utf-8
Cache-Control: no-store, no-cache, must-revalidate
```

and emits a **self-contained** page with **no CSS at all**. Not merely no external stylesheet — the rewrite intercepts assets too, so one would be answered with this same page — but no inline `<style>` either: the origin's policy is `style-src 'self'` with no `'unsafe-inline'` ([ADR 0026](../adr/0026-production-browser-security-policy.md)), so a browser would refuse it. The markup is written to read correctly unstyled, which is also how it reads in a text browser, a screen reader and a `curl` transcript.

### Why not `ErrorDocument`

`ErrorDocument 503 /maintenance.html` paired with `RewriteRule ^ - [R=503,L]` is the usual Apache idiom, and **it does not work on this host** — the server returned its own bare `503 Service Unavailable` body instead of the page. The cause was not pursued, because the PHP responder works, is portable to any server, and does something the idiom cannot: set `Retry-After` and `Cache-Control` itself.

**Do not reintroduce the `ErrorDocument` / `R=503` mechanism.**

### What was verified

| Check | Result |
| --- | --- |
| `-f` sees the flag through the storage symlink, outside the document root | yes |
| Flag **absent** | static 200, rewrite 200, `/api/v1/nope` 404 |
| Flag **present** | static 503, rewrite 503, `/api/v1/nope` **404 — the API is never intercepted** |
| Response | 503 with `Retry-After: 120`, `Cache-Control: no-store, no-cache, must-revalidate`, `text/html; charset=utf-8` |
| Body | the maintenance page itself, served |
| Flag **removed** | 200 immediately |

No restart, cache clear or wait at any point, in either direction.

**Composed and proved locally since.** Against the production-equivalent origin, with the flag raised: the Console root, client-side routes and static assets all answer the responder's 503 with `Retry-After: 120` and `no-store`; `/api` and `/api/v1/...` stay JSON and are never rewritten; `/up` is answered by Laravel rather than the responder; `/maintenance.php` serves without looping; private paths stay **403 rather than 503**, because the denials run first; the 503 carries the full browser security policy; and removing the flag restores the whole surface. The flag in those journeys is the same `storage/framework/down` that `php artisan down` writes.

---

## 5. Taking a backup

Taken inside the maintenance window before every release, before any key rotation, and on demand.

### Two-pass dump

Each backup is a **directory** holding the dump and its manifest. The dump is produced in two passes so every table is recreated but only durable tables carry rows — which makes restoring it verbatim the *correct* action, rather than something a runbook has to warn against:

```bash
B=/home/<user>/commons/backups/$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$B"

# Pass 1: structure for every table.
mysqldump --no-data --no-tablespaces <db> > "$B/dump.sql"

# Pass 2: rows for durable tables only.
mysqldump --no-create-info --single-transaction --quick --no-tablespaces <db> \
  --ignore-table=<db>.sessions \
  --ignore-table=<db>.cache \
  --ignore-table=<db>.cache_locks \
  --ignore-table=<db>.jobs \
  --ignore-table=<db>.job_batches \
  --ignore-table=<db>.failed_jobs \
  --ignore-table=<db>.password_reset_tokens \
  >> "$B/dump.sql"

gzip "$B/dump.sql"
sha256sum "$B/dump.sql.gz"
```

| Restored with data | Structure only |
| --- | --- |
| `people`, `accounts`, `role_assignments` | `sessions` |
| `account_totp_factors`, `account_recovery_codes` | `cache`, `cache_locks` |
| `account_invitations` | `jobs`, `job_batches`, `failed_jobs` |
| `security_events`, `migrations` | `password_reset_tokens` |

`migrations` keeps its rows, or the application believes nothing has ever migrated. `password_reset_tokens` is excluded because a restored token is a live credential; the cost is that anyone mid-reset requests a new link.

### The manifest

`manifest.json` beside the dump:

```json
{
  "backup_id": "20260921T140311Z",
  "taken_at": "2026-09-21T14:03:11Z",
  "reason": "pre-release",
  "dump_sha256": "…",
  "dump_bytes": 148213,
  "release": { "version": "v0.1.0", "commit": "96c9eb1" },
  "database": { "connection": "mariadb", "name": "…" },
  "keyring": {
    "current_key_fingerprint": "a1b2c3d4e5f60718",
    "previous_key_fingerprints": ["9f8e7d6c5b4a3210"],
    "keyring_fingerprint": "0011223344556677",
    "previous_keys_configured": true
  },
  "tables_with_data": ["people", "accounts", "…"],
  "tables_schema_only": ["sessions", "cache", "…"]
}
```

**No key material. No database password.** The fingerprints identify *which* keys a restore will need; they do not contain them and cannot recover them. The keys themselves live only in `shared/.env` and the owner's secure secret store — and those two stores must not be the same place as the backup, because a single store holding both the ciphertext and its key protects nothing.

Fingerprints are domain-separated truncated digests, 16 hex characters:

```
key_fingerprint(k)  = sha256("flc-key-v1|"  + k)[0:16]
keyring_fingerprint = sha256("flc-ring-v1|" + join(",", sorted(key_fingerprints)))[0:16]
```

### Verify before you trust it

`sha256sum` the dump and compare it with `dump_sha256`. A backup you have not verified is a hope.

The **restore** contract — including the keyring subset rule that must be checked before the database is touched — is in [backup and restore](backup-and-restore.md).

---

## 6. Scheduler

**Exactly one cron entry**, and everything it runs is in source control:

```
* * * * * cd /home/<user>/commons/current && /usr/local/bin/php artisan schedule:run >> /home/<user>/commons/shared/storage/logs/schedule.log 2>&1
```

Four things about that line are deliberate:

- **`cd .../current`**, never a release directory. A cron entry naming a release silently stops working at the next deployment, and silence is the worst failure mode available.
- **The PHP path must be verified as 8.3.** cPanel's cron frequently gets a different PHP than the web server. Check with `/usr/local/bin/php -v` as the cron user, and correct the path here if it differs.
- **Output goes to a shared log**, not `/dev/null`. At one line an hour this costs nothing, and it is the only evidence that pruning ran at all.
- **Cron does not name individual Laravel commands.** The application schedule (`routes/console.php`) owns the task list, so it is reviewed like code and survives a host migration. Per-task cron entries are how environments drift.

Verify: `php artisan schedule:list` shows `identity:prune-expired` with a sane next run, and after an hour `select count(*) from sessions` stops climbing.

**During a deployment nothing needs doing.** Scheduled tasks skip while the application is in maintenance mode, and no task in this application opts out of that.

---

## 7. Health and release verification

### CLI — run these while maintenance is still active

| Command | Proves |
| --- | --- |
| `php artisan release:show` | The release serving is the one you built. Compare its release id and commit with the artifact (`./flow release inspect` printed both). It also prints the `schema_rollback` classification and the previous release — read them now, while there is time, rather than during a rollback |
| `php artisan about` | The application boots, config resolves, the database connection reports |
| `php artisan migrate:status` | Nothing pending |
| `php artisan security:production-check` | Configuration is production-safe — clean, not "clean except" |
| `php artisan schedule:list` | The prune task is registered with a sane next run |

### HTTP — run these immediately after `php artisan up`

**Positive:**

| Request | Expect |
| --- | --- |
| `GET /up` | `200` `text/plain`, body `up` |
| `GET /api/v1/health` | `200`, `status: ok` |
| `GET /` | `200` `text/html`, the Console shell |
| `GET /people/<anything>` | `200`, the Console shell — SPA fallback, **not** a JSON 404 |
| `GET /assets/<hash>.css` | `200` `text/css`, served statically |
| Response headers on `/` | Full CSP and the rest of the policy — this is what proves `mod_headers` is live |

**Negative — each of these must *not* return 200:**

| Request | Expect |
| --- | --- |
| `GET /api/v1/nope` | `404` `application/json` — Laravel's JSON 404, not the Console shell |
| `GET /.env` | 403 or 404 |
| `GET /vendor/autoload.php` | 403 or 404 |
| `GET /storage/logs/laravel.log` | 403 or 404 |

The negative checks matter more than the positive ones. `.env` holds the application key and the database password.

**Release identity is not on the public surface**, by decision: `/api/v1/health` stays coarse and anonymous, and `release:show` over SSH is how you learn what is deployed ([ADR 0027](../adr/0027-release-and-deployment-model.md)).

`release:show` reads the `release.json` that shipped **inside the release directory it is run from** — Laravel's base path, beside `artisan` — so it always describes the code that is executing, never the newest release on disk or anything in `shared/`. It never consults git (the host has none). It is read-only, and **fail-closed**: no manifest, an unreadable one, malformed JSON, or any missing or invalid identity field exits non-zero and names the field. There is no "unknown" release. If it fails on a deployed release, re-upload the artifact and verify its checksum before doing anything else.

`security:production-check` reports in three parts. **Checks** are configuration this deployment runs under, and any failure means do not serve: environment and debug, `APP_URL`, `APP_KEY` presence and size, the breached-password checker, bcrypt cost, every session-cookie invariant, the rate limits and reset floor, CORS, PHP version and extensions, writable runtime directories, `expose_php`, the **file** maintenance driver, database engine and credentials (and that no development credential is in use), no dependency on a service this host lacks, and that PHP `mail()` is not the transport. **Deliberately open** lists decisions that are deferred on purpose and do not fail the command — today that is outbound mail. **Not checked here** lists the owner verifications no configuration read can establish. No check ever prints a secret; a failing one names the setting and what is wrong with it.

A green "writable runtime directories" at first deployment is the first time that item is observed on the real filesystem ([production readiness](production-readiness.md), item 9). It is implemented and tested locally; it is not verified on the host until that run.

---

## 8. `./flow release`: the developer-side tooling

Implemented, developer-side only: pure functions of a commit, run on a developer machine, holding no production credentials and unable to reach the host ([ADR 0027](../adr/0027-release-and-deployment-model.md), *Production operations boundary*).

| Command | Does |
| --- | --- |
| `./flow release build --ref <tag> --previous <ref\|none> --schema-rollback <value>` | Builds the artifact in an isolated worktree at that exact commit, from the lockfiles, in the project's own PHP and Node images. Writes `release.json`, packages `commons-<version>.tar.gz` and its `.sha256`, then re-extracts and validates what it packaged before publishing anything |
| `./flow release inspect <artifact>` | Verifies the checksum, extracts safely, validates the artifact and prints its manifest, migrations and classification. Refuses anything malformed |
| `./flow release migrations [--ref] [--previous]` | Lists a release's migrations and the scanner's warnings |

Output goes to `dist/releases/` (gitignored) unless `--out` says otherwise. An existing artifact is never overwritten. `./flow release --help` lists every option.

**What a person supplies, and the build refuses to guess:**

- **`--previous <ref>|none`** is the release **currently on the host**, named by you. It is never inferred from tags, because a tag may never have been deployed. `none` means a first release. It is resolved to a commit and recorded.
- **`--schema-rollback`** is always supplied by a person ([classification](#3-rollback)). The build only refuses inconsistent choices: `not-applicable` exactly when no migration is new; on a first release (`--previous none`) always `restore-required`, because there is no earlier code to switch back to and recovery is the pre-release backup.
- **`--acknowledge-scanner-findings`** is required only when the migration scanner reports findings *and* you chose something less conservative than `restore-required`. Choosing `restore-required` never needs it. The findings, your classification, who supplied it (`--classified-by`, default your git identity) and whether acknowledgement was required and given are all recorded in `release.json`. The scanner is a red-flag generator: a clean scan proves nothing.

**Provenance.** The ref must be an annotated tag reachable from `main`. A rehearsal that cannot meet that passes `--allow-untagged`; the artifact is then named by release id rather than version, stamped `provenance_override: true`, and shown as such by `inspect`.

**What the artifact holds.** Exactly the layout in [§0](#0-the-shape-of-it): platform code, `vendor/` (production dependencies only), the Console build merged into `public/`, the empty `storage/` and `bootstrap/cache/` skeleton, and `release.json`. It is assembled from an allowlist and then judged independently: no env files, logs, dumps, keys, tests, `.git`, development packages, cached configuration or shipped symlinks.

**The production public surface is required, not optional.** The document root must hold the composed `.htaccess` and `public/maintenance.php`, and the validator checks the rule classes and the rule ORDER — private denials before the maintenance arm, the API carve-out before the SPA fallback — refuses the `ErrorDocument`/`R=503` mechanism that failed on this host, refuses a responder that loads Laravel, `vendor/` or the environment, and refuses an unexpected file in the document root. An artifact that fails any of that is invalid and is never written out. The three approved cache commands are run against a throwaway copy to prove they still succeed (`optimize` is never run), and the build refuses if `resources/views` has appeared.

**`./flow release deploy` does not exist, and its absence is deliberate.** Nor will `backup`, `restore` or `rollback`. Everything touching production stays an explicit operator action run from this runbook, holding no credentials in the repository, until the manual procedure has been performed on the real host enough times to be worth encoding ([ADR 0027](../adr/0027-release-and-deployment-model.md), *Production operations boundary*).

## 9. Not built yet

This runbook describes the approved design. These parts of it do not exist in the repository, and the implementation phase adds them:

- **The cache-command check on every commit.** `./flow release build` runs it (and refuses if `resources/views` has appeared), so it gates a release; the ordinary `./flow check` does not yet, so a Blade view would only be caught when a release is built.
- **An Apache-served rehearsal.** Everything about the composed `.htaccess` that can be proved without Apache now is; the file itself is first executed by a real deployment.

---

## Related

- [ADR 0027](../adr/0027-release-and-deployment-model.md) — the decisions behind all of the above.
- [Production readiness](production-readiness.md) — the host checklist that gates first deployment.
- [Backup and restore](backup-and-restore.md) — the restore contract and the keyring rule.
- [Administrator bootstrap](administrator-bootstrap.md) — the ceremony in step 14.
- [Rotate the application key](app-key-rotation.md) — and why a backup without its keyring is not a backup.
- [Deployment topology](../architecture/deployment-topology.md) — the serving arrangement this procedure produces.
