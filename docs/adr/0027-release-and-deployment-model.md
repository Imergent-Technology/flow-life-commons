# ADR 0027: Release and deployment model

- **Status:** Accepted
- **Date:** 2026-09-21
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0011](0011-docker-compose-development-environment.md), [ADR 0016](0016-guardian-console-same-origin-session-authentication.md), [ADR 0023](0023-multi-factor-authentication.md), [ADR 0026](0026-production-browser-security-policy.md)
- **Clarified:** 2026-09-21, after the production host was probed. The decisions are unchanged and were validated rather than reopened. Two pieces of *wording* were corrected by measurement: the release switch is no longer provisional, and the static-half maintenance response is served by a standalone PHP responder rather than `ErrorDocument`. See [Verified on the production host](#verified-on-the-production-host-2026-09-21).

## Context

The application considers itself production-ready and is not deployable. Identity and Access is complete, the browser security policy is enforced, the configuration is checked by `security:production-check`, and none of that says how a build reaches a document root, how migrations run against live data, or what happens when a release is wrong.

Everything below is shaped by four constraints that are already decided elsewhere and are not reopened here:

- **The host is shared cPanel**: Apache, PHP 8.3, MariaDB, cron. No Docker, no Node, no Redis, no resident daemons ([charter](../architecture/charter.md)). Measured since: Apache on CloudLinux with PHP served through LSAPI/`mod_lsapi`, so `PHP_SAPI` reads `litespeed` — **not** LiteSpeed Web Server, and not a reason to assume an LSCache layer.
- **The Console and the API are one deployment unit on one origin**, because the session cookie is host-only ([ADR 0016](0016-guardian-console-same-origin-session-authentication.md)). Releasing the Console means writing its build output into the platform's document root.
- **`APP_KEY` is not a session secret.** Every enrolled authenticator is ciphertext under it ([ADR 0023](0023-multi-factor-authentication.md)), which makes backup and restore a security operation rather than an operational chore.
- **The browser security policy is generated from configuration into the web server's own files** ([ADR 0026](0026-production-browser-security-policy.md)), so the deployment carries a file that a test pins.

A fifth constraint is scale, and it earns its place because it is what makes several decisions below *simple* rather than *sophisticated*: this is an invite-only operator directory for a small charity. A handful of signed-in people, tens of audit rows a day. Designs that buy zero downtime with expand/contract migration discipline are solving a problem this platform does not have, at a cost it would pay on every release forever.

Four behaviours of the current codebase were **measured on 2026-09-21** rather than assumed, because each of them would otherwise have been guessed wrong. They are recorded in the decision itself, not as background, because the decision is only valid while they hold.

## Decision

### Release model: immutable directories behind one indirection

A release is an **immutable directory** named by UTC timestamp and short commit sha. It is never edited in place. The served path reaches it through a **`current` symlink**, and the document root is `current/public` — the Laravel public directory and nothing above it, so `.env`, `vendor/`, `storage/` and all source are physically outside the served tree rather than merely denied by rewrite rules.

**Mutable state shared across releases is exactly two things**: `shared/.env` and `shared/storage`, reached from each release by symlink. Nothing else is shared, and in particular `bootstrap/cache/` is **per-release**: cached configuration belongs to the code it was cached against, and sharing it is how a rollback quietly keeps the new release's configuration.

Five releases are retained. The previous release staying intact and complete on disk is what makes code rollback a symlink operation rather than a restore.

**The release switch is an atomic replacement of the symlink**: the new link is created at a temporary path and then `rename(2)`d over `current`. `ln -sfn` is rejected — it unlinks and re-links, so `current` is briefly absent, and without `-n` it creates the link *inside* the target directory.

**The swap mechanism was provisional and is now measured.** Two host behaviours decided it: whether the web server and PHP observe a change to the symlink's target promptly, and what operation clears the opcache. Probed on 2026-09-21, the **first** request after an atomic swap already served the new release for both PHP and static content, over thirty consecutive observations, with `__FILE__` resolving under the new release directory. **No opcache reset, restart or wait is required, and none is in the procedure.**

The rsync-in-place fallback is retained here as a record of what the alternative would have cost — instant rollback lost entirely, recovery by re-extracting the previous artifact, and a window in which the tree is a mixture of two releases — but it is **not in use**. If a future release ever serves stale code, that is a change in host behaviour: re-run the A/B swap probe before adding a reset step, rather than adding one pre-emptively.

### Build model: off-host, from an exact immutable ref

Production artifacts are **built off the production host**. Composer and npm never run there; the host needs neither Node nor a Composer binary to receive a release.

The build happens in an **isolated checkout** — a temporary git worktree at an exact ref — not in the working tree. A build that tests one state and packages another is untraceable, and traceability is the entire value of the manifest. Dependencies install from lockfiles inside that isolated checkout, so what is packaged is what the lockfiles say and not what a developer's machine had lying around. The build runs in the project's own PHP 8.3 container, so the extension set matches what production requires rather than what the builder happens to have.

Production artifacts are normally built from an **annotated tag reachable from `main`**. The tag names the code. It makes no claim about a deployment having succeeded; that claim lives in the deploy log on the host.

The Console's production build is assembled into the Laravel public surface as part of the artifact, so the two halves of the origin are versioned and shipped together and cannot drift.

### Optimization model: three commands, named explicitly

Measured on this repository on 2026-09-21:

| Command | Result |
| --- | --- |
| `php artisan config:cache` | succeeds |
| `php artisan event:cache` | succeeds |
| `php artisan route:cache` | **succeeds** |
| `php artisan view:cache` | **fails**: `resources/views` does not exist |
| `php artisan optimize` | **fails**, after creating the config, event and route caches |

Two of these deserve recording because the obvious expectation is wrong in both directions.

**Route caching works, including the `/up` closure.** Current Laravel serializes closure route actions, so the liveness probe defined in `bootstrap/app.php` caches without complaint. This was verified at runtime and not merely by exit status: with the route cache in place, `/up` answers `200 text/plain`, `/api/v1/health` answers `200` JSON, and `/api/v1/nope` answers `404 application/json`. No route needs moving to a controller and no caching is given up.

**`optimize` is inappropriate for this repository**, and specifically for this reason: it includes `view:cache`, and this platform is API-only with no `resources/` directory at all. Its only Blade templates are the two Identity mail views, registered from a module path. `ViewCacheCommand` walks the default finder paths, finds no `resources/views`, and throws. Worse, it throws *after* the config, event and route caches have been written, so a deployment script that runs `optimize` and checks the exit status aborts with the release already half-optimized.

This is a statement about the current repository, not about `optimize` in general.

**The approved production optimization sequence is therefore exactly:**

```
php artisan config:cache
php artisan event:cache
php artisan route:cache
```

A check asserts that all three still succeed and that `resources/views` still does not exist. The second half is the load-bearing one: if a Blade view is ever added, the check fails and forces the question "does `view:cache` now belong in the release?" instead of letting the answer drift silently.

### Maintenance model: one authority, two enforcement points

**`php artisan down` and `php artisan up` are the only maintenance authority.** No parallel flag is invented, because two sources of truth for "is the site down" is a way to be half-down.

The authoritative state is **`storage/framework/down`**, the JSON file `down` writes and `up` removes. (`down` also writes `storage/framework/maintenance.php`, a handler shim loaded by the front controller whose first action is to check for `down`; `up` removes both. The flag is `down`; `maintenance.php` is the mechanism that reads it.) Because `storage/` is shared across releases, **maintenance state survives the release switch** — entered in the old release, left in the new one, with nothing to carry across.

Maintenance needs two enforcement points because the origin is served by two different things, and this was measured rather than predicted. With `down` active, against the production-equivalent origin serving the real static build:

| Path | While down |
| --- | --- |
| `/` | **200 — the Console shell is served** |
| `/people/123` | **200 — the Console shell is served** |
| `/up` | 503 |
| `/api/v1/health` | 503 `application/json` |

The Console loads completely and then every API call fails. So:

- **Laravel owns `/api/*` and `/up`.** Its shim is content-negotiation aware and answers JSON callers with JSON. Apache must not intercept these: a static HTML 503 delivered to an API client is worse than the current behaviour.
- **Apache owns the static half** — the Console shell, client-side routes and assets — which PHP never sees. It tests the same `storage/framework/down` file, so there is still one authority and two places that observe it.

**The static half's 503 is produced by a standalone PHP responder, not by `ErrorDocument`.** An internal rewrite (`[L]`, never `R=503`) hands the request to `public/maintenance.php`, which sets the status, `Retry-After` and `Cache-Control: no-store` itself and loads neither Laravel, `vendor/` nor `.env` — because the situation it exists for includes a release that is broken or half-installed. The Apache idiom, `ErrorDocument 503` paired with `RewriteRule ^ - [R=503,L]`, was tried first and **does not work on this host**: the server returned its own bare 503 body instead of the page. The responder is also more portable and can set headers the idiom cannot, so it is the decision rather than a workaround.

**No maintenance bypass is supported at the Apache level.** Laravel ships `down --secret=`, and its shim honours the secret URL and cookie, but Apache's rule fires before PHP reaches it, so honouring a bypass would mean reimplementing the cookie HMAC check in `.htaccess`. Declined. The consequence is accepted deliberately: the live application cannot be exercised over HTTP while maintenance is active, which is why verification is split the way it is below.

**The scheduler needs no special handling.** `Illuminate\Console\Scheduling\Event::isDue()` skips a due task while the application is down unless `evenInMaintenanceMode()` was called, and no task in this application calls it. Scheduled pruning pauses for the window and resumes afterwards.

### Database release model: a short maintenance window, used fully

Migrations run **with writes stopped**. At this scale a window of well under a minute is free, and it removes an entire class of problem — old code against new schema, new code against old schema — that zero-downtime deployment otherwise buys with permanent expand/contract discipline on every migration. That trade is right at this scale and would be wrong at a larger one; it is recorded as scale-dependent, not as a principle.

**The pre-release backup is taken after maintenance begins and before migrations run.** A backup taken before the window leaves a gap in which people keep writing, and those writes are exactly what is lost if the release then has to be restored. Taking it inside the window costs a few seconds of downtime on a database this size and makes the backup a true recovery point.

### Migration rollback classification: a human decides, tooling never certifies

The question a rollback actually asks is not whether a migration was additive. It is:

> **Can the previous release's code execute correctly against the post-migration schema?**

Every release carries an explicit `schema_rollback` classification in its manifest:

| Value | Meaning | Rollback |
| --- | --- | --- |
| `not-applicable` | No new migrations | Switch `current` back |
| `code-only` | The previous release runs correctly against the new schema | Switch `current` back |
| `restore-required` | The previous release cannot run against the new schema, or data was destroyed | Restore the pre-release backup; everything written since is lost |

**Tooling may list migrations, inspect the SQL they generate, and emit warnings. Tooling may not set this value.** A release containing migrations absent from the previous release cannot be built into a production artifact until the classification is supplied explicitly, and the manifest records who supplied it.

Pattern scanning for `DROP`, `RENAME`, `MODIFY` and `CHANGE` is a useful red flag and nothing more, because it is wrong in the dangerous direction. A migration that adds a `NOT NULL` column with no default contains none of those keywords and is "additive" by every pattern rule — and every `INSERT` the previous release performs against it fails, because that code does not know the column exists. Keyword-clean, and `restore-required` in fact. The scanner cannot prove safety, and cannot prove danger either; it prompts a human and leaves a trace. Where the scanner's findings and the human's classification disagree, the build requires explicit acknowledgement and records both, so the disagreement is on the record rather than resolved silently.

`migrate:rollback` is **not** a production recovery mechanism. Every migration in this application creates or adds, so its `down()` destroys data. Reversibility in the schema is not reversibility in production.

### Backup and the encryption keyring: a recovery pair

A database backup and the application encryption keys are **one recovery pair**. Restoring a dump under a keyring that cannot decrypt it leaves every enrolled authenticator intact and permanently meaningless ([ADR 0023](0023-multi-factor-authentication.md)).

Pairing on the *current* `APP_KEY` alone is insufficient, because a dump may contain ciphertext written under any key that was ever current — which is precisely the situation during and after a key rotation, when a restore is most likely to be needed.

Each backup therefore carries a manifest recording **non-secret fingerprints of the complete keyring**: a domain-separated, truncated digest of each key and of the ring as a whole. The restore contract is a subset test:

> **Every key fingerprint the backup requires must be present in the live or recovery keyring.** A live ring that is a strict superset is the normal post-rotation case and is safe. Any missing fingerprint refuses the restore, by name.

Fingerprints are **metadata and never a substitute for key custody**. They identify which keys are needed; they do not contain, protect, or help recover them. The key material lives only in the production environment and the owner's secure secret store.

### Restore and transient state: enforced by the dump, not by prose

Transient state does not regain runtime authority merely because a database was restored. A restored session, a restored queued job, a restored password-reset token — each is a live thing resurrected from the past, and the last of those is a credential.

This is enforced **by how the dump is produced**, not by an instruction a rushed operator is expected to follow at the worst possible moment. The dump is produced in two passes so that every table is recreated but only durable tables carry rows:

- **Restored with data**: `people`, `accounts`, `role_assignments`, `account_totp_factors`, `account_recovery_codes`, `account_invitations`, `security_events`, `migrations` — the durable Identity, Access and Audit record.
- **Restored as structure only**: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`.

Restoring the dump verbatim is therefore the *correct* action, which is the point: a full dump paired with a written warning is a trap.

`account_invitations` is durable and is restored with data — dropping it would strand legitimately pending people. The stale-invitation risk it carries is handled by reconciliation after a restore, not by exclusion.

This is a **restore policy, not a retention framework**. Retention and archival for `security_events` remain undesigned and out of scope here.

### Production operations boundary

`./flow release` will contain **developer-side functions only**: building an artifact from an exact ref, inspecting one, and listing a release's migrations. These are pure functions of a commit, run on a developer machine, holding no production credentials.

**Production actions remain explicit operator actions, executed from a runbook.** In particular there is no automatic deploy, no automatic restore, no automatic rollback, and no `./flow` command that holds production credentials. `./flow release deploy` does not exist, deliberately, and its absence is a decision rather than an omission.

This may be reconsidered only after the manual procedure has been performed successfully on the real host several times. Automating a procedure nobody has executed produces a fast, confident, wrong deployment.

### Queue: no worker, and a defined trigger to revisit

Production runs **no queue worker**, because the application dispatches no queued work: both Identity mailers send synchronously and no class implements `ShouldQueue`. The `jobs` table exists because the framework ships it, which is not a reason to run a worker.

The decision is revisited **when the first application class begins dispatching real queued work**, and not before. Development continues to run a queue listener, so the path stays exercised locally ([ADR 0010](0010-database-queue-redis-ready.md)).

### Release identity stays off the public surface

A deployment must be able to answer which release is serving. That answer comes from a **CLI command reading the artifact's `release.json` directly**, with no additional application configuration coupling.

It is deliberately **not** added to `/api/v1/health`, which is public and unauthenticated and whose contract is to expose nothing beyond coarse status. A commit sha is a small disclosure, and the deployment procedure has shell access, so there is nothing to buy by widening a public endpoint.

*Implemented as `php artisan release:show`.* It reads the `release.json` beside `artisan` — the application base path, so the manifest of the release directory it runs from and nothing else — and is fail-closed: a missing, unreadable or malformed manifest, or any missing identity field, is an error rather than an "unknown". It needed no change to the manifest schema.


## Verified on the production host (2026-09-21)

Everything in this ADR that depended on host behaviour was probed directly on the production account. **Every check that could have forced a redesign passed**, so this ADR is validated rather than reopened. The full record, with measured values, is in the [production readiness runbook](../runbooks/production-readiness.md).

| Decision | Outcome |
| --- | --- |
| Atomic symlink replacement | Works. `rename(2)` via PHP; `ln -sfn` stays rejected |
| The swap is observed by the server | **Immediately**, PHP and static alike, first request after the swap |
| An opcache reset is needed | **No.** None required, none added |
| Independent document root reached through a symlink | Accepted by cPanel |
| `.htaccess`, `mod_rewrite`, `mod_headers` | All honoured |
| The maintenance flag is visible to `.htaccess` through the storage symlink | Yes — `%{DOCUMENT_ROOT}/../storage/framework/down -f` resolves |
| The maintenance responder returns a real 503 with `Retry-After` and `no-store` | Yes; `/api/*` never intercepted |
| Private paths unreachable over HTTP | Yes — 403 or 404, never 200 |
| `mysqldump` with `--no-tablespaces --single-transaction --quick` | Works. `--no-tablespaces` is **required**: shared-hosting users lack `PROCESS` |
| Cron every minute, with an absolute `/usr/local/bin/php` | Works, and the absolute path is **necessary** — bare `php` under cron is `cgi-fcgi`, not `cli` |
| Five retained releases fit the account | Yes — ~325 MB against 75 GB |
| An intermediary page cache needing a release-time purge | **None.** Commons is direct to origin |

**Probed individually on the host; composed and proved locally since.** Each rewrite rule above was tested on its own on the real account. The composed file now exists — header block, private-path denials, maintenance arm, `/api` and `/up` carve-out and SPA fallback, in that order — and the whole contract is driven in a real browser against the production-equivalent origin, with the file's structure and rule order pinned by a test. That is proof of the **contract**, not of Apache: there is no Apache in development, so the `.htaccess` itself is still evidenced by the per-rule host probes plus those pins until the first real deployment runs it.

**One item is open and deferred:** outbound mail authentication. SPF and DMARC records exist; a local PHP `mail()` test delivered but was unsigned and not DMARC-aligned, so `mail()` is **not approved** as the production transport. The mail topology is an organizational decision and is deliberately unmade here. It does not block release tooling; it blocks inviting people.

## Consequences

- **A release becomes a reviewable object.** A checksummed artifact with a manifest naming its commit, its tag, its lockfile digests, its migrations and its human rollback classification can be inspected before it is deployed and identified after.
- **Rollback is two mechanisms, not one**, and the manifest says which applies. This is the most important consequence: the failure mode this design exists to prevent is an operator switching `current` back and assuming a `restore-required` release is recovered.
- **Every release has downtime**, deliberately, measured in tens of seconds. This is a trade against migration complexity that is correct at the current scale and should be revisited if the platform ever serves people who notice.
- **The host has hard requirements it did not have**: symlink-following with a changing target and `mysqldump`. Both are now verified on the real account, and the third — an opcache-clearing operation — turned out not to be needed at all.
- **`php artisan optimize` must not appear in any deployment script for this repository**, and the reason is a property of the repository that a check now pins.
- **Backup acquires a security contract.** A backup without its keyring is not a backup of the authenticators, and the restore procedure refuses rather than discovering this afterwards.
- **The `.htaccess` in source control is the file this design requires.** It carries the generated security headers, the private-path denials, the maintenance arm, the API and `/up` carve-out and the SPA fallback, in an order a test pins. The production-equivalent development gateway mirrors the same contract, so the browser suite keeps proving the routing semantics production will serve.
- **The host is verified; the procedure is not.** Every host behaviour this design depends on was probed on the real account on 2026-09-21 (above). What remains unexercised is the deployment procedure itself, end to end, and with it the `.htaccess` as Apache reads it.

## Alternatives considered

**Deploy by `git pull` on the host.** The reflex for cPanel, and it fails three ways here: `vendor/`, `node_modules/` and `dist/` are gitignored, so the host would need Composer and Node; there is no atomic switch, so a pull is observable half-applied; and rollback means resolving git state under pressure. The working tree also becomes writable by the process serving it.

**Build on the server.** Removes the artifact-transfer step and requires Composer, adequate memory, and network egress to Packagist on a shared host. It cannot build the Console at all, since production has no Node ([charter](../architecture/charter.md)). Rejected: it moves failure from a developer machine, where a failed build is an inconvenience, to the production host, where it is an outage.

**Zero-downtime releases with expand/contract migrations.** Every schema change becomes a multi-release sequence in which old and new code must both work against an intermediate schema. Correct at scale and disproportionate here: it imposes permanent complexity on every migration to avoid tens of seconds of downtime for a handful of operators.

**Automatic classification of migration rollback safety by scanning SQL.** Attractive because it removes a human step, and rejected because it is confidently wrong in the dangerous direction — a `NOT NULL` column addition is keyword-clean and breaks the previous release. A tool that is right most of the time here produces exactly the false confidence a rollback procedure cannot afford. Retained as a warning generator.

**`migrate:rollback` as the rollback mechanism.** Every migration in this application creates or adds; their `down()` methods destroy data. Reversible in the schema is not recoverable in production.

**A second maintenance flag owned by the deployment scripts**, so Apache and the deploy procedure share something simpler than Laravel's state. Rejected: two authorities for "is the site down" eventually disagree, and the disagreement is discovered during an incident. Apache reads Laravel's own file instead.

**Pairing backups with the current `APP_KEY` only.** Simpler, shorter fingerprint, and wrong precisely when it matters — during a rotation, when a dump contains ciphertext under a key that is no longer current.

**Excluding `account_invitations` from restores** along with the other credential-bearing tables. Rejected: invitations are durable pending state, and dropping them strands people who were legitimately invited. The stale-invitation case is reconciled afterwards instead.

**A `./flow release deploy` command.** The obvious convenience, and the one command in this repository that could destroy production. Rejected until the manual procedure is proven on the real host: it would need production credentials, and it would encode a procedure whose host behaviour is still unknown.

**Exposing the release commit on `/api/v1/health`.** Convenient for verification from anywhere, and it widens a deliberately coarse public endpoint to report build metadata to anonymous callers. The deployment has shell access and does not need it.
