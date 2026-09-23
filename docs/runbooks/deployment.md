# Runbook: release and deployment

- **Purpose:** put a release of the Commons platform onto the production host, and get it off again when it is wrong.
- **Owner:** whoever holds the hosting account and server access.
- **Design:** [ADR 0027](../adr/0027-release-and-deployment-model.md). Read it once before the first deployment; this runbook assumes its decisions rather than re-arguing them.
- **Last tested:** the **host capabilities** this procedure depends on were probed directly on 2026-09-21 and are recorded in [production readiness](production-readiness.md), section 4. **The procedure itself was executed end to end for the first time on 2026-09-22**, first deployment through administrator bootstrap, bringing v0.1.1 fully live.

> **Status: VERIFIED once manually on production, 2026-09-22.** The full procedure — first deployment, maintenance mode, migrations, caches, administrator bootstrap, scheduler cron, and normal-traffic verification — has now been executed end to end on the real host, for v0.1.1. This is one observed run, not an automated or repeated one; see [the deployment closure record](#10-first-production-deployment-closure-2026-09-22) below.
>
> The `current` symlink swap **is** observed immediately by both PHP and static requests, and **no opcache reset, restart or wait is required** — measured on 2026-09-21 and again across the real release swap on 2026-09-22. The maintenance mechanism is verified end to end on production, including the rewrite condition reading the flag through the storage symlink, and including the `/api` and `/up` carve-out under real Apache + LSAPI.
>
> **One host item remains open: outbound mail authentication** ([production readiness](production-readiness.md), section 5), deferred pending an organizational decision. It does not block deployment; it blocks inviting ordinary people, which is step 14 for anyone beyond the first administrator.
>
> **The composed public surface is now proved at three levels, including the host.** `public/.htaccess` carries the security headers, the private-path denials, the maintenance arm, the API and `/up` carve-out and the SPA fallback, in that order, and `public/maintenance.php` is the responder. The whole *contract* is driven in a real browser against Caddy, the production-equivalent development gateway, and the file's structure and rule order are pinned by a test. `scripts/tests/apache-surface.sh` runs the real committed `.htaccess` and `maintenance.php` under a disposable Apache container and asserts the routing and header-composition behaviour directly — this is what caught and now regression-tests an Apache-specific bug Caddy could not see (below). **And now the production host itself**: the first deployment (v0.1.0, then v0.1.1) exercised the exact committed file under real Apache + CloudLinux LSAPI — maintenance routing, the API/`/up` carve-out, private-path denials, and the security headers all matched what the Caddy and container levels predicted.
>
> **An Apache-specific defect was found and fixed during a pre-deployment audit (2026-09-21).** Section 6's API/`/up` rewrite used `[L]`, which ends only the current per-directory pass; Apache's own internal redirect to `index.php` re-ran the whole ruleset as a second pass, and the maintenance rule caught the rewritten request because its exclusion did not name `/index.php` — so every `/api` and `/up` request got the HTML maintenance page instead of Laravel while the flag was raised. This is precisely the gap between "proved against Caddy" and "proved against Apache": Caddy does not re-run its rules on an internal rewrite, so the browser suite and every text-comparison test kept passing while the composed file was wrong. Fixed with `[END]`, which stops rewrite processing outright; verified on a real Apache 2.4 container, then confirmed correct again during the real 2026-09-22 deployment (`GET /api/v1/health` and `GET /up` both answered `503` JSON/plain text, never the maintenance HTML), and now a standing regression test (`scripts/tests/apache-surface.sh`, `./flow check repo`). A related header-duplication defect was found and fixed the same way: Apache's `Header always set` appends to a header Laravel's own middleware already sent rather than replacing it, so every PHP-answered response carried each of the seven ADR 0026 headers twice; fixed by pairing each with `Header onsuccess unset` first (`security:headers --format=apache`).
>
> **A second, real-host finding followed on 2026-09-22 and was fixed the same way: `X-Powered-By: PHP/8.3.33` reached clients under v0.1.0**, even though the 2026-09-21 probe had read `expose_php=On` and wrongly inferred no header reached clients. This hosting account exposes no `expose_php` toggle, so the fix strips the header at the origin instead (`Header onsuccess unset X-Powered-By` and `Header always unset X-Powered-By`, both — LSAPI was observed populating either of Apache's two response-header tables). Released as v0.1.1 and **reverified absent on the real host** on `/`, `/api/v1/health` and `/up`, under maintenance and live. See [ADR 0026](../adr/0026-production-browser-security-policy.md) and [production readiness](production-readiness.md), item 2.

**Host environment, measured 2026-09-21 and confirmed again during the 2026-09-22 deployment:** Apache on CloudLinux, PHP 8.3.33 via LSAPI/`mod_lsapi` (so `PHP_SAPI` reads `litespeed` — this is **not** LiteSpeed Web Server), MariaDB `10.11.18-MariaDB-cll-lve`, direct to origin with no proxy or CDN in front.

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

Every step from here on names the release directory through **one variable, `R`**, set once and used as an absolute path in every command — never a bare `cd` that a later command silently trusts. If a step's `cd` ever fails, every command after it in this runbook is written so that it still resolves against `$R` or another absolute path, not against wherever the shell happened to land.

```bash
R=/home/<user>/commons/releases/<release-id>
sha256sum -c commons-<version>.tar.gz.sha256      # must pass before extracting
mkdir -p "$R"
tar -xzf commons-<version>.tar.gz -C "$R"
```

### 6. Wire the shared links

```bash
cp -an "$R/storage/." /home/<user>/commons/shared/storage/      # seed shared/storage from the artifact's skeleton; never overwrites
rm -rf "$R/storage" && ln -s ../../shared/storage "$R/storage"
ln -s ../../shared/.env "$R/.env"
```

No `cd` in this step, deliberately: every path is absolute or built from `$R`, so there is nothing for the destructive `rm -rf` to inherit if an earlier command in the session left the shell somewhere unexpected.

The **seeding line matters on the first deployment**: `shared/storage` starts empty, and `php artisan down` fails there (`file_put_contents(storage/framework/down): No such file or directory`) because `storage/framework/` does not exist. The artifact ships the directory skeleton (placeholder `.gitignore` files only, never runtime state, and pinned exactly by `scripts/release/artifact.php`). On the first deployment `shared/storage` exists but is **empty** (step 2 created it), so `cp -an` creates the validated skeleton there, and the modes it leaves are the artifact's own (whatever `tar` extracted them as). Confirm they are what step 12's writable-directories check expects; if not, `chmod` them explicitly rather than re-running `cp -an` hoping for a different result.

> **Before the SECOND deployment: do not repeat this seeding line as written.** Repeating `cp -an` is **not** a harmless no-op on a later release. It never overwrites an existing *file*, but `-a` re-applies the skeleton's mode and mtime to directories that **already exist** in `shared/storage` — persistent directories holding live runtime state. Measured in the pre-deployment re-audit (2026-09-21, GNU coreutils 9.7): an existing `2775` directory came out `700`. It is safe on the first deployment only because there is nothing there yet. Before any later deployment, use `cp -rn` instead: **confirmed directly on the production host** (`cp -rn src/. dst/`, GoDaddy cPanel `cp`) — an existing directory's mode stayed `2775`, existing file content was untouched, and a missing `.gitignore` was added. **This is not the same claim as "preserves mtime literally."** Not overwriting an existing directory's own metadata is the invariant `cp -rn` gives; it does not freeze the directory's mtime, because adding a new entry to a directory is itself a write that updates that directory's mtime through ordinary filesystem mutation, `cp -rn` or not. Do not describe `cp -rn` as leaving directory mtimes unchanged — only that it does not overwrite existing content or reapply the skeleton's own metadata onto what already exists.

Confirm `storage/` and `bootstrap/cache/` are writable by the PHP user.

### 7. Migrate the empty database

```bash
/usr/local/bin/php "$R/artisan" migrate --force
```

Giving `artisan` as a path (`$R/artisan`), not a bare command from an assumed working directory, is what makes this migrate the release you just wired in steps 5–6 rather than whatever release an earlier `cd` in the session happened to leave the shell inside. It also uses `/usr/local/bin/php`: bare `php` under cron on this host is a different SAPI entirely (§3, [production readiness](production-readiness.md#3-the-scheduler-one-cron-entry-and-everything-else-in-source-control)), and using the verified binary consistently in every command here is cheaper than remembering which contexts need it.

### 8. Build the caches

```bash
/usr/local/bin/php "$R/artisan" config:cache
/usr/local/bin/php "$R/artisan" event:cache
/usr/local/bin/php "$R/artisan" route:cache
```

**Not `php artisan optimize`.** It includes `view:cache`, which fails on this repository because there is no `resources/views`, and it fails *after* writing the other three caches — so a script that runs it and checks the exit status aborts with the release half-optimized. The reasoning is in [ADR 0027](../adr/0027-release-and-deployment-model.md), *Optimization model*.

**If `shared/.env` is edited after this step**, the change has no effect until the cache is rebuilt: `config:cache` bakes the resolved configuration into `$R/bootstrap/cache/config.php`, and Laravel reads that file instead of re-parsing `.env` once it exists. Re-run this step (against the same `$R`) after any `.env` edit, including one made to fix something step 12 just failed on.

### 9. Activate the release

```bash
ln -s "releases/<release-id>" /home/<user>/commons/current.next
/usr/local/bin/php -r 'rename("/home/<user>/commons/current.next","/home/<user>/commons/current") or exit(1);'
readlink /home/<user>/commons/current        # confirm it names the new release
```

No `cd` here either: the symlink's *target* text (`releases/<release-id>`) is deliberately relative, because that is what makes `current` still resolve correctly from inside a release directory, but the *command* that creates it is given the link's own location as an absolute path, so it does not matter what the shell's working directory is.

`rename(2)` replaces the symlink atomically. **Do not use `ln -sfn`**: it unlinks before re-linking, so `current` is briefly absent, and without `-n` it creates the link inside the target directory.

### 10. Nothing — the swap needs no opcache step

**Verified 2026-09-21: there is no reset, restart or wait to perform.**

Two releases were swapped by atomic `rename(2)` and observed over thirty iterations. The **first** request after the swap already served the new release, for PHP and static content alike, with `__FILE__` resolving under the new release directory. No stale bytecode, no stale realpath, at any point.

The host's settings would have bounded any staleness regardless: `opcache.validate_timestamps=1`, `opcache.revalidate_freq=2`, `realpath_cache_ttl=120`.

**Do not add a reset step "just in case."** It would be unverified cargo — nobody would know whether it worked, and it would mask a regression rather than reveal one. If a future release ever *does* serve stale code, that is a real change in host behaviour: re-run the A/B swap probe before adding anything, and record what changed.

### 11. Install the scheduler cron

See [§6](#6-scheduler). One entry, through `current`.

### 12. CLI verification

See [§7](#7-health-and-release-verification). All of it must pass, `security:production-check` included, with no "clean except".

### 13. HTTP and security verification

See [§7](#7-health-and-release-verification), including every negative check. `.env`, `vendor/` and the storage logs must not be reachable.

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

As first deployment steps 5 and 6, with `R=/home/<user>/commons/releases/<new-release-id>`. The new release is on disk and serving nothing; `current` still points at the old one.

**Except the storage seeding line.** Step 6's `cp -an` is only verified safe against an empty `shared/storage`; on a later release it re-applies attributes to existing persistent directories. Use `cp -rn "$R/storage/." /home/<user>/commons/shared/storage/` instead — confirmed on the real host (see the warning under [first deployment step 6](#6-wire-the-shared-links)): it does not overwrite existing content or reapply the skeleton's metadata onto directories that already exist, though the shared `storage/` directory's own mtime can still change as an ordinary consequence of a new entry being added to it.

### 3. Warm the caches

```bash
/usr/local/bin/php "$R/artisan" config:cache
/usr/local/bin/php "$R/artisan" event:cache
/usr/local/bin/php "$R/artisan" route:cache
```

Safe to do before the window, because this release is not yet serving. Against `$R`, the new release, deliberately — not `current`, which is still the old one.

### 4. Enter maintenance

```bash
/usr/local/bin/php /home/<user>/commons/current/artisan down
```

Against `current`, the release actually serving: `down` and `up` write `shared/storage/framework/down`, and that file is shared across every release regardless of which release's `artisan` writes it — but the *code that runs* to write it must be code that can still boot, and until the swap in step 7 that is the OLD release.

**The window starts here.** Confirm: `/api/v1/health` returns 503, and the Console shell returns the maintenance page rather than loading.

### 5. Take the pre-migration backup

See [§5](#5-taking-a-backup). Inside the window, deliberately: a backup taken before maintenance leaves a gap of live writes that a restore would lose.

### 6. Migrate

```bash
/usr/local/bin/php "$R/artisan" migrate --force
```

Against `$R`, the **new** release — this is what makes the migration run the new release's migration files, not the old release's. Do not run this against `current`: at this point in the procedure `current` is still the release you are about to replace, and it has no new migrations to run.

### 7. Switch `current`

```bash
ln -s "releases/<new-release-id>" /home/<user>/commons/current.next
/usr/local/bin/php -r 'rename("/home/<user>/commons/current.next","/home/<user>/commons/current") or exit(1);'
readlink /home/<user>/commons/current
```

As first-deployment step 9: no `cd`, every path absolute or anchored to `$R`.

### 8. No opcache step

Nothing to do — see first-deployment step 10. The swap is observed immediately on this host.

### 9. CLI verification, still in maintenance

The CLI half of [§7](#7-health-and-release-verification): `release:show`, `about`, `migrate:status`, `security:production-check`, `schedule:list`. Do as much as possible here, while nothing is exposed. **Against `current`, now the new release** — the swap in step 7 already moved it. If any of these commands need naming which release they mean, that is a sign something about the swap did not take; `readlink /home/<user>/commons/current` from step 7 is what answers it.

### 10. Leave maintenance

```bash
/usr/local/bin/php /home/<user>/commons/current/artisan up
```

### 11. HTTP and security verification, immediately

The HTTP half of [§7](#7-health-and-release-verification). Run it now, not after a coffee: this is the only unverified-exposure window in the procedure and the point is to keep it short.

### 12. On any failure

```bash
/usr/local/bin/php /home/<user>/commons/current/artisan down
```

Against `current` — whichever release is actually live at the moment of failure, which is the only release guaranteed able to boot and write the flag. If `current` itself cannot boot (a failure *during* step 7's swap, or the new release crashing immediately after it), the release still on disk at the OLD path is intact until you delete it: `/usr/local/bin/php /home/<user>/commons/releases/<old-release-id>/artisan down` reaches the same shared flag from there.

Then go to [§3](#3-rollback) and follow the release's `schema_rollback` classification. **Do not improvise this under pressure — the classification was decided when there was time to think.**

### 13. Prune releases beyond the retention policy

Five releases are retained ([ADR 0027](../adr/0027-release-and-deployment-model.md)). This is the exact, safe procedure — never freehand `rm` in `releases/`:

```bash
cd /home/<user>/commons/releases || exit 1
CURRENT_TARGET="$(basename "$(readlink -f /home/<user>/commons/current)")"
# Release directory names sort chronologically as text (UTC timestamp prefix), so the oldest are
# first. `head -n -5` prints everything EXCEPT the five most recent; `ls -1` here, and nowhere below,
# is the only place this procedure reads the working directory rather than an absolute path — the
# destructive command on the next line still takes one.
for old in $(ls -1 | sort | head -n -5); do
    if [[ "$old" == "$CURRENT_TARGET" ]]; then
        echo "refusing to delete current ($old) — retention policy or CURRENT_TARGET is wrong; stop and look" >&2
        continue
    fi
    rm -rf "/home/<user>/commons/releases/$old"
done
```

This **never touches `shared/`**: it only ever lists and removes entries under `commons/releases/`, and the `rm -rf` target is always an absolute path built from a name the loop just listed there — never an empty or unset variable, and never `shared` or `current` themselves, which are not release directory names `ls -1` in this directory can produce. It refuses `current`'s own target explicitly, as a second check beyond "the five most recent will always include it under a working retention policy." The release *before* `current` is a policy consequence of retaining five, not a separate rule this script enforces: as long as the count stays at five and deployments happen one at a time, the previous release is always inside that window.

### 14. Record the result

Append to `shared/deploy.log`, including failures and what was done about them.

---

## 3. Rollback

**Which procedure applies is not a judgement call at the time.** It is the `schema_rollback` value in the release's `release.json`, decided by a person when the artifact was built ([ADR 0027](../adr/0027-release-and-deployment-model.md), *Migration rollback classification*).

```bash
/usr/local/bin/php /home/<user>/commons/current/artisan release:show        # among other things, prints schema_rollback
```

Every step below that "points `current` back" means exactly first-deployment step 9's technique, aimed at the previous release: no `cd`, every path absolute.

```bash
ln -s "releases/<previous-release-id>" /home/<user>/commons/current.next
/usr/local/bin/php -r 'rename("/home/<user>/commons/current.next","/home/<user>/commons/current") or exit(1);'
readlink /home/<user>/commons/current        # confirm it now names the PREVIOUS release
```

Every `artisan` command after that swap is run as `/usr/local/bin/php /home/<user>/commons/current/artisan ...` — against `current`, which the swap just pointed at the previous release, never against a release directory named from memory.

### `not-applicable` — no migrations in this release

1. `artisan down` (if not already).
2. Point `current` back at the previous release, as above.
3. No opcache step is needed (see first-deployment step 10).
4. CLI verification, against `current`.
5. `artisan up`, then HTTP verification.

Nothing was done to the database. Nothing is lost.

### `code-only` — the previous release runs against the new schema

Same five steps, with one addition between 3 and 4: **verify the previous release against the already-migrated schema** before coming up. `artisan about` and `artisan migrate:status`, against `current` (now the restored release), and exercise one authenticated read if you can. The schema stays migrated; you are relying on the classification that the old code tolerates it.

Nothing is lost.

### `restore-required` — the database must go back too

**Maintenance stays on for the whole of this.**

1. `artisan down` — and confirm it, because everything below assumes no writes are arriving.
2. Point `current` back at the previous release, as above. No opcache step is needed.
3. **Restore the pre-release backup** — follow [backup and restore](backup-and-restore.md), including the keyring verification, which happens *before* the database is touched.
4. Reconcile invitations (see that runbook): revoke any invitation whose account is already active.
5. CLI verification against `current` (the restored release) and the restored database.
6. `artisan up`, then HTTP verification.
7. Record in `shared/deploy.log` what was lost (see below).

> **Data loss is explicit here.** Everything written between the pre-migration backup and the moment maintenance was re-entered is gone. Because the backup is taken inside the maintenance window, that is normally only what happened during the failed release itself — but if the release was up and serving for a while before the failure was noticed, it includes every sign-in, every audit event and every administrative act in that period.
>
> **The audit trail loses that period too** ([ADR 0019](../adr/0019-security-event-auditing-seam.md)). Record in the deploy log what the gap was, because `security_events` can no longer tell you.

**`migrate:rollback` is not a recovery mechanism.** Every migration in this application creates or adds; their `down()` methods destroy data. Do not reach for it here.

> **First deployment is a distinct case of this, and step 3 above does not apply to it.** `--previous none` is always classified `restore-required` ([ADR 0027](../adr/0027-release-and-deployment-model.md)), but the reason is not that a backup exists to restore: on a first deployment the production database **begins empty**, there is no prior application data, and no `code-only` rollback target exists because there is no earlier release's code to fall back to. If first deployment itself fails after migrations have run, step 3's "restore the pre-release backup" does not apply — there is no pre-release application backup, only an empty schema. Recovery there means dropping what the failed migration created and returning the database to that known-empty state, then re-attempting from step 5, not restoring a backup that was never taken because nothing existed yet to back up.
>
> **The general `restore-required` procedure above is the decided design, and it has never been rehearsed end to end.** No backup or restore tooling exists in this repository ([backup and restore](backup-and-restore.md)); taking a dump is a documented `mysqldump` command, verified on the host, but loading one back, checking the keyring fingerprints before the database is touched, and reconciling invitations afterward are procedures a person follows by hand, untested against a real failure. **Treat this as explicitly open work**, to be completed and rehearsed before the first *later* release whose classification is `restore-required` — do not assume it is ready merely because the steps are written down.

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

**Composed and proved at two levels since.** Against the production-equivalent Caddy origin, with the flag raised: the Console root, client-side routes and static assets all answer the responder's 503 with `Retry-After: 120` and `no-store`; `/api` and `/api/v1/...` stay JSON and are never rewritten; `/up` is answered by Laravel rather than the responder; `/maintenance.php` serves without looping; private paths stay **403 rather than 503**, because the denials run first; the 503 carries the full browser security policy; and removing the flag restores the whole surface. The flag in those journeys is the same `storage/framework/down` that `php artisan down` writes.

Separately, `scripts/tests/apache-surface.sh` runs the *actual* `.htaccess` and `maintenance.php` under a real Apache container and asserts the same "`/api` and `/up` are never rewritten" claim directly against Apache's own second-pass rewrite behaviour, which Caddy does not have and cannot exercise — this is the check that found the `[L]`/`[END]` defect above. Neither level is the production host: LSAPI on CloudLinux, not a container's mod_php, is what actually serves this file — **and now has**, at the first real deployment on 2026-09-22, confirming the same "never rewritten" behaviour on real Apache + LSAPI.

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
- **Output goes to a shared log**, not `/dev/null`. **Measured on production 2026-09-22: this is not one line an hour.** `schedule:run` fires every minute and logs `No scheduled commands are ready to run.` on every minute nothing is due, so the log grows at roughly one line a minute, not one an hour — the repeated real `INFO No scheduled commands are ready to run.` entries are themselves the proof that cPanel is invoking Laravel's scheduler every minute. At this scale that growth is acceptable and is the only evidence pruning ran at all; revisit rotation only if it becomes a real cost.
- **Cron does not name individual Laravel commands.** The application schedule (`routes/console.php`) owns the task list, so it is reviewed like code and survives a host migration. Per-task cron entries are how environments drift.

Verify: `/usr/local/bin/php /home/<user>/commons/current/artisan schedule:list` shows `identity:prune-expired` with a sane next run, and after an hour `select count(*) from sessions` stops climbing.

**During a deployment nothing needs doing.** Scheduled tasks skip while the application is in maintenance mode, and no task in this application opts out of that.

---

## 7. Health and release verification

### CLI — run these while maintenance is still active

Run every command below as `/usr/local/bin/php <artisan> COMMAND`, where `<artisan>` is the path the calling step named — `$R/artisan` for the release being brought up before the swap, `/home/<user>/commons/current/artisan` for whichever release `current` points at afterward. Never a bare `php artisan`, and never a path chosen by whatever the shell's working directory happens to be.

| Command | Proves |
| --- | --- |
| `release:show` | The release serving is the one you built. Compare its release id and commit with the artifact (`./flow release inspect` printed both). It also prints the `schema_rollback` classification and the previous release — read them now, while there is time, rather than during a rollback |
| `about` | The application boots, config resolves, the database connection reports |
| `migrate:status` | Nothing pending |
| `security:production-check` | Configuration is production-safe — clean, not "clean except" |
| `schedule:list` | The prune task is registered with a sane next run |

### HTTP — run these immediately after `artisan up`

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

`security:production-check` reports in three parts. **Checks** are configuration this deployment runs under, and any failure means do not serve: environment and debug, `APP_URL`, `APP_KEY` presence and size, the breached-password checker, bcrypt cost, every session-cookie invariant, the rate limits and reset floor, CORS, PHP version and extensions, writable runtime directories, the **file** maintenance driver, database engine and credentials (and that no development credential is in use), no dependency on a service this host lacks, and that PHP `mail()` is not the transport. **Deliberately open** lists decisions that are deferred on purpose and do not fail the command — today that is outbound mail. **Not checked here** lists the owner verifications no configuration read can establish. No check ever prints a secret; a failing one names the setting and what is wrong with it.

**VERIFIED on production 2026-09-22.** "Writable runtime directories" was green in `security:production-check` at first deployment — the first time that item was observed on the real filesystem ([production readiness](production-readiness.md), item 9) — and stayed green through the v0.1.1 redeployment.

---

## 8. `./flow release`: the developer-side tooling

Implemented, developer-side only: pure functions of a commit, run on a developer machine, holding no production credentials and unable to reach the host ([ADR 0027](../adr/0027-release-and-deployment-model.md), *Production operations boundary*).

| Command | Does |
| --- | --- |
| `./flow release build --ref <tag> --previous <ref\|none> --schema-rollback <value>` | Builds the artifact in an isolated worktree at that exact commit, from the lockfiles, in the project's own PHP and Node images. Writes `release.json`, packages `commons-<version>.tar.gz` and its `.sha256`, then re-extracts and validates what it packaged before publishing anything |
| `./flow release inspect <artifact>` | Verifies the checksum, extracts safely, validates the artifact and prints its manifest, migrations and classification. Refuses anything malformed |
| `./flow release migrations [--ref] [--previous]` | Lists a release's migrations and the scanner's warnings |

Output goes to `dist/releases/` (gitignored) unless `--out` says otherwise. An existing artifact is never overwritten. `./flow release --help` lists every option.

**This build runs on the developer's own machine, and needs GNU userland there — not merely inside a container.** `scripts/lib/release.sh` calls `date -d` and `tar --sort=name --mtime=…` directly from the outer shell, on the host, before anything reaches a container; the container images (`flowlife-dev/php:8.3`, `node:24-bookworm-slim`) only run the Composer/npm/cache steps inside. BSD `date`/`tar` (macOS, the default on a Mac without `coreutils`/`gnu-tar` installed) do not accept the same flags. The development target is Linux or WSL2 ([getting started](../development/getting-started.md)); this is a property of that same requirement, not a new one, and is not a promise of macOS support.

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
- **Deployment automation.** `./flow release build`, `inspect` and `migrations` remain developer-side artifact operations; there is still no `./flow deploy production` or equivalent, deliberately ([ADR 0027](../adr/0027-release-and-deployment-model.md), *Production operations boundary*). One successful manual deployment (below) is not "enough times" to reconsider that boundary; it is the first data point.
- **Restore, rehearsed.** The `restore-required` rollback path and general backup restore have still never been exercised end to end, on this host or any other — see [backup and restore](backup-and-restore.md) and [§3](#3-rollback).

---

## 10. First production deployment: closure (2026-09-22)

The design in [ADR 0027](../adr/0027-release-and-deployment-model.md) has now been carried out once, manually, start to finish, on the real host. This section is the closing record; the numbered procedure above is unchanged by it and remains what the *next* deployment follows.

**v0.1.0 → v0.1.1.** The first artifact built and installed was **v0.1.0** (commit `21175fe`). It was extracted behind `current`, migrated, cached and exercised entirely under maintenance mode — it was **never opened to normal traffic** — because that maintenance-mode exercise caught `X-Powered-By: PHP/8.3.33` reaching clients (above). The fix was made in source and shipped as **v0.1.1** (commit `98cf1a1`, release id `20260922T223512-98cf1a1`), which was verified clean of the header, brought out of maintenance, and is the release actually live. **v0.1.1 is therefore the first production release opened to normal traffic**, not v0.1.0.

**Apache/LSAPI, maintenance, and security headers.** All verified directly on the real host under v0.1.1: `GET /` returns the maintenance HTML under `down` and the Console shell when up; `GET /api/v1/health` and `GET /up` return Laravel's own JSON/plain-text 503 under `down` and never the maintenance page, proving the `[END]` fix holds on real Apache + LSAPI; private paths (`/release.json`, `/vendor/autoload.php`, `/storage/logs/laravel.log`) return 403 under maintenance and carry the full seven-header ADR 0026 policy; `X-Powered-By` is absent everywhere checked, under maintenance and live.

**The one exception: `/.env`.** `GET /.env` returns a **host-generated** generic 403 — cPanel/Apache denies it before the application's own `.htaccess` response-header policy ever runs, so that particular 403 carries no application security-header block. This is not evidence `.env` is exposed (it still never returns anything but 403), and it is not a gap in the application's own denial rules, which independently deny it too; it is simply intercepted one layer earlier than the paths above. Every other private path denied by the application's own rewrite rules carries the full header set. Do not generalise from `/.env` to "some 403s lack headers" without checking which layer produced them.

**`cp -rn` storage seeding.** Confirmed directly on the host for the second-deployment case (see [step 6](#6-wire-the-shared-links)): existing directory mode and file content are untouched, a missing skeleton path is added, and a directory's own mtime can still move because adding an entry to it is a write — `cp -rn` does not freeze that, it only avoids clobbering what is already there.

**Administrator bootstrap and MFA.** `identity:create-administrator` issued the first production invitation; `POST /api/v1/invitations/accept` returned `204`; the administrator signed in, enrolled TOTP, and stored the ten recovery codes. **This is the operator's normal primary administrator account, not a disposable bootstrap identity.** Production currently runs with **one** administrator — a deliberate, recorded operational choice, not an unresolved deployment failure. Creating a second administrator for redundancy (step 16, above) remains recommended and is intentionally deferred, not treated as blocking.

**Scheduler.** `crontab -l` confirms the one cron entry, unchanged from what this runbook specifies, firing every minute against `/usr/local/bin/php` (`cli`, 8.3.33). The log carries repeated real `No scheduled commands are ready to run.` entries — see the corrected note under [§6](#6-scheduler).

**Outbound mail.** Unchanged and still **OPEN, intentionally deferred** ([production readiness](production-readiness.md), section 5). Do not read anything above as mail being production-ready.

**Deployment automation.** Still future work, and still deliberately unautomated ([ADR 0027](../adr/0027-release-and-deployment-model.md)). One successful manual run is the reason a future automation design pass is now worth having, not a reason to skip it.

## Related

- [ADR 0027](../adr/0027-release-and-deployment-model.md) — the decisions behind all of the above.
- [Production readiness](production-readiness.md) — the host checklist that gates first deployment.
- [Backup and restore](backup-and-restore.md) — the restore contract and the keyring rule.
- [Administrator bootstrap](administrator-bootstrap.md) — the ceremony in step 14.
- [Rotate the application key](app-key-rotation.md) — and why a backup without its keyring is not a backup.
- [Deployment topology](../architecture/deployment-topology.md) — the serving arrangement this procedure produces.
