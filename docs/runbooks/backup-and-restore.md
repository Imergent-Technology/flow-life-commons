# Backup and restore: what must be kept together

**Purpose.** The security-critical facts a backup must respect, and the contract a restore must satisfy before it touches the database.

**Owner.** Whoever holds the hosting account.

**Status.** The *contract* below is decided ([ADR 0027](../adr/0027-release-and-deployment-model.md), corrected by the G6 WP3 triage). The procedure for **taking** a backup is in the [deployment runbook](deployment.md#5-taking-a-backup). **No backup or restore tooling exists**, and `./flow` has no `backup` or `restore` command; both are operator actions, deliberately ([runbooks](README.md)). The **dump command** below is verified on the production host (2026-09-21); **the restore contract has never been exercised there.** [What has and has not been verified](#what-is-established-what-was-rehearsed-and-what-is-not) is set out next, because a documented step is not a tested one.

---

## What is established, what was rehearsed, and what is not

Four terms are used in this runbook, and they are not interchangeable. **Contract** is what a restore must satisfy. **Tested against the fake daemon** means the rehearsal script's own decisions (what it checks, what it refuses, in what order) are covered by its ordinary and mutation tests, which model Docker and the database and show nothing about the real ones. **Rehearsed locally** means the step ran in the local recovery rehearsal ([development note](../development/restore-rehearsal.md)) on real isolated Docker infrastructure (MariaDB 10.11.18, the real `mariadb` client, the application's own PHP, real volumes) with synthetic data on one machine: L2 (2026-11-14), L3a (2026-11-16), L3b (2026-11-17) and the integrated lifecycles of WU6 (2026-11-21), each a small number of runs. **Not rehearsed** means exactly that, whatever else is written about it. **Nothing here has been exercised on the production host.** The rehearsal is a local script, not backup or restore tooling, and a local rehearsal is neither a verified production procedure nor an authorization to restore or deploy.

| Part of the procedure | Status |
| --- | --- |
| The two-pass dump (shape and exclusions) | Verified on the production host (2026-09-21). The rehearsal also took it, uncompressed, with `mariadb-dump` 10.19 |
| Checksum of the dump; the keyring fingerprint subset test; the schema inventory taken **before** anything is cleared | **Rehearsed locally** (every lifecycle) |
| Clearing every application table, then loading the dump, then requiring the inventory to equal the manifest's (the WP3-01 correction) | **Rehearsed locally** in two forms: the 16 tables of v0.3.0 restored over the 34 tables of the next release (the defect it corrects, loading over a database that has newer tables, was reproduced too), and the current 34-table schema restored over a changed 34-table state (the paired recovery, WU6), the dump loaded by the real `mariadb` client as the application account. Both into the rehearsal's own emptied database, as the triage's WP3-01 ruling requires; a separate fresh database was not used |
| After the restore: every durable table identical to the backup, every transient table empty, a sign-in that needs the second factor (its secret is encrypted under a *previous* key), and a later upgrade that succeeds | **Rehearsed locally.** After the **historical** restore: every durable table equal to the snapshot, the sign-in (in-process and rolled back, not through a web server or a browser) and the re-upgrade. After the **paired** restore: the 27 durable tables equal to the backup's recorded row counts and content digests, the 7 transient tables empty, the structures, the ledger and the asset rows (key, size, SHA-256), and the application's own `resources:assets:prune --dry-run` clean. **Not done after the paired restore:** a sign-in, and `relationships:check` (which no rehearsal stage runs) |
| Deriving the list of tables to clear from the live database; loading a **gzipped** dump with the `mysql` client; the `mysql` and `mysqldump` clients on the host | **Not rehearsed.** The rehearsal names the tables from its own fixed list and loads an uncompressed file |
| **Resource files:** the zero-file backup, the archive, extraction, the full agreement check, moving newer files aside | **Rehearsed locally**, on their own (L3b, [record](../development/restore-rehearsal.md#l3b-the-live-resource-files-rehearsal-passed-2026-11-17)) and with the database restore (WU6, [record](../development/restore-rehearsal.md#slice-22c-3-wu6-the-integrated-live-rehearsal-passed-2026-11-21)): the historical zero-file restore with the newer files moved aside intact and nothing extracted, and a nonempty archive restored after a change made following the backup, with the changed files moved aside intact and the restored directory equal to the backup's files, the asset rows and the archive. **Not:** a host, real Resource files, a larger library, files that Cards refer to, concurrent uploads, or cPanel's coverage of `shared/` |
| **Preserving the failed state** | **Rehearsed locally, in a variant** (WU3, WU6): the rehearsal's `preserve-failed-state` exports the failed state with its own fixed two-pass dump as the application account (the structure of all 34 tables and the rows of the 27 durable ones, not the transient rows the step below asks for) and writes a record that binds the export by checksum; its restore refuses to clear anything without a valid record, and the refusals for a missing and for an altered record were exercised live. **Not rehearsed:** this runbook's own `mysqldump --single-transaction` export, the timeline, the handling of the export, and the comparison of the export with the restored database |
| **Reconciling security state after a restore** | **Contract only. Not rehearsed.** That a restore *reverses* a security change made after the backup is demonstrated by the rehearsal, tested against the fake daemon and run live (slice 2.2C-1, L3a, 2026-11-16, synthetic data on one local machine); reconciling it is not, and no command for it exists. It stays a manual operator requirement |
| The rehearsal's own refusals and its failure reporting (a restore without a valid preservation, from the wrong state, or from an unverified unit; which phase of a failed restore may be partly changed) | **Tested against the fake daemon.** The refusals of a missing or altered preservation, of a repeated change and of a repeated restore were also run live (WU6). The partial-failure reporting of a failed paired restore is fake-daemon only: no live partial failure was provoked, and a restore from a corrupt but checksum-consistent dump was not run live |
| Restoring a database that a half-failed `migrate` left (some of a migration's tables committed, its record not) | **Rehearsed locally once** (slice 2.2C-1; L3a passed 2026-11-16 on MariaDB 10.11.18: the failure measured on MariaDB, then run live, synthetic data, one machine; [record](../development/restore-rehearsal.md#l3a-the-live-half-failed-upgrade-rehearsal-passed-2026-11-16)). The rehearsal clears only the one 32-table half-failed state it made itself. A real half-failed state on the host is not to be cleared by that, or by anything not rehearsed on it |
| What to do when `migrate` stops part-way | **Written** as an operator decision table ([below](#when-migrate-stops-part-way-which-state-is-this), G6 WP3-11). It rests on the one failure point L3a rehearsed and on the refused-upgrade tests of WP3b; it is guidance for a person, not a procedure verified on a host, and nothing in it is automated or authorizes a repair |
| A complete operational recovery **on the production host** | **Not established.** A local rehearsal does not authorize a production restore or a deployment, and does not replace the owner's decision between a rehearsal on the host and explicit risk acceptance ([next-release checklist, item 5](g10-release-readiness.md#next-release-checklist)). Also still open for the host: a gzipped load, the table inventory of the real database, whether the host's backups cover `shared/`, the security reconciliation, and the owner's release authorization |

---

## The one rule

> **A database backup is worthless without the encryption keys that were in use when it was taken.**

Not the current key — the **keyring**. A dump can contain ciphertext written under any key that was ever current, which is exactly the situation during and after a rotation, and a rotation is when a restore is most likely to be needed.

Enrolled authenticators are stored as ciphertext under the application key ([ADR 0023](../adr/0023-multi-factor-authentication.md)). Restoring `accounts` and `account_totp_factors` under a *different* key gives every enrolled person an unreadable secret: they cannot pass their second factor, and no administrator can decrypt it for them. The data is intact and permanently meaningless.

So a backup is a **pair**: the database dump, and the keyring it corresponds to. Store them so that both are available together, and so that restoring the first without the second is not a step anyone can take by accident.

Dating them identically is no longer the mechanism. Every backup carries a manifest recording **non-secret fingerprints of the complete keyring** it depends on, and the restore checks them before touching the database (see [The restore contract](#the-restore-contract)). The fingerprints identify which keys are needed; they do not contain them, and they are not a substitute for key custody. How to compute them, with test values, is in the [deployment runbook](deployment.md#the-manifest).

This is survivable, but only because of the escape hatch below. It is still a bad day.

## What must be restored consistently with each other

These are not independent tables. A restore that takes some at one point in time and some at another produces states the application's invariants say cannot exist.

| Records | Why they belong together |
| --- | --- |
| `people` and `accounts` | An Account cannot exist without its Person ([ADR 0015](../adr/0015-identity-owns-person.md)); the foreign key is `RESTRICT` and the application asserts it. |
| `role_assignments` | Authorization is derived live from these. Restoring accounts without them leaves nobody able to administer anything; restoring them without accounts leaves grants pointing at nothing. **Including the last-administrator invariant**: a restore that loses the administrator's assignment locks the platform out of its own administration, recoverable only by `identity:create-administrator` on the server. |
| `account_totp_factors` | Ciphertext, readable only under the matching keyring (above). Restored *older* than `accounts`, a person who replaced their authenticator gets the old one back. |
| `account_recovery_codes` | Digests, key-independent. Restored older than the factors, **already-spent codes become usable again** — each is single-use by a `used_at` column, and an old backup un-spends them. |
| `account_invitations` | Restored with `accounts` from one backup it is consistent with them. Restored *older* than the Account's acceptance, an accepted invitation is pending again (its Account is `invited` again, with no password), and an invitation that was revoked or reissued after the backup is live again. See [Security state after a restore](#security-state-after-a-restore-stop-unless-it-is-established). |
| `membership_grants` | Durable business data, restored with data ([ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md)). `person_id` is a `RESTRICT` foreign key to `people`, so grants and people must come from the same point in time. Revocation is a later update to the same row (`revoked_at`): restored *older* than the rest, a grant revoked in between is live again. |
| `security_events` | Append-only history ([ADR 0019](../adr/0019-security-event-auditing-seam.md)). Restoring it *older* than the rest silently erases the record of whatever happened in between — including whatever caused the restore. |
| `contact_profiles`, `contact_methods`, `contact_tags`, `contact_tag_assignments`, `contact_interactions` (CRM) | Durable ([ADR 0034](../adr/0034-crm-enriches-identity-person.md)). Keyed by `person_id` with `RESTRICT` foreign keys to `people`, so they must come from the same point in time as `people`. **`contact_interactions` holds free-text notes about people**: a backup of it is personal data, and so is the failed-state export below. |
| `discussions`, `discussion_messages` | Durable ([ADR 0035](../adr/0035-guardian-discussions-are-durable-asynchronous-threads.md)). A message's only foreign key is to its discussion; authors, editors and resolvers are Person ids with no foreign key, so the database will not stop a restore that leaves them naming a Person who is not there. |
| `person_relationships`, `person_relationship_status_changes`, `person_relationship_field_values`, `person_relationship_role_provisions` **and `sourced_role_grants`** | Durable ([ADR 0038](../adr/0038-organizational-relationships-and-resource-viewing-authority.md)). **Relationships and the grants they source must be restored from one consistent point in time.** A sourced grant exists only while its relationship qualifies; an orphaned sourced grant is honoured by the Authorizer (ADR 0038, K11), so a grant restored without the relationship change that withdrew it stays live. `relationships:check` reports such a departure, on a release that has it. |
| `resource_*` tables **and the Resource files** | Durable business data ([ADR 0037](../adr/0037-resources-are-audience-targeted-packs-of-cards.md)), and the one case where the database alone is not the record: `resource_assets` names files that live **outside the database**. Restored without the files, every File Card answers `asset_unavailable`; restored with files from a *different* point in time, some Cards lose their file and some files belong to nothing. See [Resource files](#resource-files). |

**Outside the database:** `APP_KEY` and `APP_PREVIOUS_KEYS`, which live in the environment file and are not in any dump. Back them up deliberately and separately from the database, because a single compromised store holding both the ciphertext and the key protects nothing. **And the Resource files** (below), which are part of the same backup as the dump, not a separate concern.

### The complete schema

A restore is verified against a table inventory, so the inventory is written down here. It is the 34 tables of the next release; **production runs v0.3.0, which has the first 16.** Every table added since is **durable**: it carries rows in the dump and is in no `--ignore-table`.

| | Tables |
| --- | --- |
| **v0.3.0, rows restored (9)** | `people`, `accounts`, `role_assignments`, `account_totp_factors`, `account_recovery_codes`, `account_invitations`, `membership_grants`, `security_events`, `migrations` |
| **v0.3.0, structure only (7)** | `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` |
| **Added since v0.3.0, all rows restored (18)** | CRM: `contact_profiles`, `contact_methods`, `contact_tags`, `contact_tag_assignments`, `contact_interactions`. Discussions: `discussions`, `discussion_messages`. Resources: `resource_categories`, `resource_packs`, `resource_pack_audiences`, `resource_cards`, `resource_card_audiences`, `resource_assets`. Relationships: `person_relationships`, `person_relationship_status_changes`, `person_relationship_field_values`, `person_relationship_role_provisions`. Access: `sourced_role_grants` |

A backup taken by the host running v0.3.0 holds the first 16, and **only those**. That is why loading it over a database that has already been upgraded does not give back v0.3.0.

## Resource files

> **Status: rehearsed locally, with the database restore; not a verified production procedure.** The recovery rehearsal ran these steps on synthetic files in an isolated Docker environment, on their own (L3b, 2026-11-17) and together with a database restore in the two lifecycles of WU6 (2026-11-21): the zero-file backup with newer files moved aside intact, and a nonempty archive restored after a change made following the backup, the changed files moved aside intact, the restored directory equal to the backup's files, the asset rows and the archive, and a clean `resources:assets:prune --dry-run` ([record](../development/restore-rehearsal.md#slice-22c-3-wu6-the-integrated-live-rehearsal-passed-2026-11-21)). The script's decisions are also tested against a fake daemon and, for the file work, a real directory with the real `tar`. **It has not run on a host or against real Resource files, with a larger library, with Card references or with concurrent uploads, and the commands here remain what the contract requires, not a verified production procedure.** Do not treat them as operationally proven for the host.

**What they are.** Each File Card owns one file ([ADR 0037](../adr/0037-resources-are-audience-targeted-packs-of-cards.md), decisions 62-68). The file's metadata is a row in `resource_assets` (in the dump, with data, like every durable table); its **bytes** are a file in the private `resources` disk:

```
/home/<user>/commons/shared/storage/app/private/resources/<storage key>
```

One file per asset, named by the asset's id (26 lowercase characters, no extension), mode `0600`, nothing else in the directory. It is under `shared/storage`, so it survives releases ([ADR 0027](../adr/0027-release-and-deployment-model.md)); it is outside the document root and never served except by an authorized Resources route. A stored file is **never changed**: replacing a Card's file writes a new one and removes the old, so a copy of the directory is consistent file by file.

**The directory may not exist.** `resources/` is created when Resources first stores a file. Neither storage skeleton contains it, so a host that has never run a Resources release (production today, on v0.3.0) has no such directory, and a host whose Resources have never held a file may not either.

### The backup is clean only if everything agrees

**Before the backup is accepted**, inside the maintenance window, after the dump, the files and the rows must agree completely:

1. **If the directory exists:** it holds regular files named as storage keys and nothing else (`find resources -mindepth 1 ! -type f` prints nothing: [the prune below](#what-the-prune-command-does-not-do) cannot see a subdirectory or a link).
2. **The archive is made with its exit status checked** (see [deployment §5](deployment.md#5-taking-a-backup)); a non-zero status means no usable archive, and the backup is incomplete.
3. **`resources:assets:prune --dry-run` is read in full**, on a release that has the command. `kept` must equal the number of files in the directory, which must equal `COUNT(*)` of `resource_assets`; and **all of** *unreferenced but younger than 24 hours*, *not a Resources storage key*, *orphaned*, *could NOT be removed* and *Asset rows whose file is missing* must be **0**.
4. **The directory is absent:** that is a legitimate **zero-file backup** only if `resource_assets` has no rows, or the table does not exist (as on v0.3.0). The manifest then records a count of 0 and no checksum. **An absent directory while `resource_assets` has rows is not a zero-file backup: stop.**

Any other result is **not** accepted because it can be written into the manifest. A young orphan may be an upload that failed an hour ago, a missing file means Cards that already cannot be served, and each needs a person to explain it before the upgrade goes ahead. The manifest records what a clean backup looked like; it does not excuse one that was not.

### Restoring files with the database

With maintenance mode up throughout (the scheduler does not run `resources:assets:prune` in maintenance mode, which is what makes this order safe):

1. Restore the database exactly as [the restore contract](#the-restore-contract) says.
2. **Move the live directory aside, never delete it**, if it exists: `mv .../app/private/resources .../app/private/resources.before-restore-$(date -u +%Y%m%dT%H%M%SZ)`. After a failed release this directory holds the files uploaded during it. The restored database does not refer to them, and they are not recoverable from anywhere else.
3. **A zero-file backup:** nothing is extracted and the directory stays absent. Do not put the moved-aside directory back.
4. **Otherwise** verify `resource-files.tar.gz` against `resource_files_sha256`, extract it into `.../shared/storage/app/private/`, giving `resources/` back, and check the count against the manifest and the directory against item 1 above.
5. **Check before anything removes a file**, where the restored release has the command: `php artisan resources:assets:prune --dry-run`, held to the same agreement as a clean backup (item 3 above). Anything else means the files and the dump are not from the same backup: stop and find the right pair. **Never run the real prune as a shortcut to passing this check.**
6. Only then bring the application up. Remove the moved-aside directory once the restore is accepted, not before.

**Reverting to v0.3.0.** v0.3.0 has no Resources code and **no `resources:assets:prune` command**, and it ignores the directory. So: move the live directory aside (step 2) and keep it separately; skip step 5; and do not invoke any Resources command on the restored release. The restored database has no `resource_*` tables, so nothing refers to those files. They are kept as the record of what was uploaded during the failed release. **Do not put them back before a later upgrade:** with the Resources tables empty every one is an orphan, and the first prune after the 24-hour grace period removes it, irreversibly.

### What the prune command does not do

`resources:assets:prune` is the only check there is, and it is narrower than the contract asks of it:

- **It compares names and modification times, never content.** It does not read a file, compare its size or check `resource_assets.sha256`. Two pairs that agree on every name can still disagree on bytes. The archive checksum protects content; the prune does not.
- **It lists the top level only.** A subdirectory or a link is not listed, not counted and not reported.
- **A dry run exits 0 whatever it finds.** It fails only when a real removal fails, so read the lines; do not rely on the status.
- **It does not exist on v0.3.0.**
- **The 24-hour grace uses the file's modification time.** `tar` preserves the archive's times on extraction, so an extracted file keeps the age it had.
- **A real run is irreversible.** After restoring an *older* database over *newer* files, every file uploaded since that backup is exactly the kind of orphan it removes. That is correct for the restored database and wrong if you might yet go back to the newer one, which is why step 2 moves the directory aside, why step 5 is a dry run, and why the daily schedule cannot fire during the maintenance window. It **can** fire the moment the application is up, so the check above must pass first.

**The host's own backups are supplementary.** The authoritative restore pair for Resources is the Commons backup above: the dump, the Resource files archive and the manifest, taken together in one maintenance window. Provider or cPanel home-directory backups, if they exist, are not relied on for Resources, because nothing makes them consistent with a dump. Whether they cover `/home/<user>/commons/shared` is **not confirmed**. When checked on 2026-10-05, the account had no user-level backup exclusion, but listed no retained backups to inspect ([production readiness §4c, F4](production-readiness.md#4c-resources-managed-files-wp3)). Do not plan a Resources restore around them.

## Transient state does not come back

`sessions`, `password_reset_tokens`, the cache tables and the queue tables are **not restored with data**. A restored session, a restored queued job and a restored reset token are each a live thing resurrected from the past, and the last of those is a credential.

This is enforced by **how the dump is produced**, not by an instruction someone is expected to follow at the worst possible moment: the dump is written in two passes so that every table is recreated but only durable tables carry rows ([deployment runbook](deployment.md#5-taking-a-backup)). Restoring it verbatim is therefore the correct action. A full dump paired with a written warning is a trap, and this replaces one.

The cost is small and worth naming: anyone mid-password-reset requests a new link, and everyone signs in again. The security generation ([ADR 0025](../adr/0025-account-security-generation.md)) would have refused the old sessions regardless.

This is a **restore policy, not a retention policy**. Nothing here prunes or ages out durable data; see *Audit growth* below.

## When `migrate` stops part-way: which state is this?

> **Status: written as operator guidance (G6 WP3-11). It rests on one rehearsed failure point (the half-failed migration of L3a, local and synthetic) and on the refused-upgrade tests of WP3b; it has not been verified on a host, and no part of it is automated.** **No repair is authorized**: no re-run to see what happens, no `migrate:rollback`, no SQL to complete or undo a migration, and no hand-written ledger row. The table says what to look at and what has to wait for a decision. It does not choose a fix.

**A failed `migrate` is not "nothing happened".** MariaDB commits every schema statement (`CREATE TABLE`, `ALTER TABLE`) as it runs, and Laravel does not wrap a MariaDB migration in a transaction. Whether a failed migration left schema changes behind depends on where it failed: one that fails before its first schema statement leaves nothing, while one that fails after some statements has already committed them, **with its own ledger row unwritten**, so the database is neither the old schema nor the new one. You cannot assume either, so inspect it. PostgreSQL's schema statements are transactional, so the same failure there would have rolled the whole migration back, which is why a PostgreSQL test run cannot show what the production MariaDB will do. Measured on MariaDB 10.11.18 in L3a, with the failure forced by a stub table placed beforehand ([record](../development/restore-rehearsal.md#l3a-the-live-half-failed-upgrade-rehearsal-passed-2026-11-16)): after a migration failed on `person_relationship_field_values` with `Table ... already exists` (1050), the tables it had created before that point remained and the ledger lacked its record; a second `migrate` failed again, on the first of those tables, `person_relationships`, and repaired nothing. That is what happened at that one failure point; a repeated `migrate` is not guaranteed to fail identically elsewhere (it might fail differently, or run on and change the schema further), which is one more reason it is never run blind.

**Before choosing a row (read-only; maintenance stays on).** Save the `migrate` output and `shared/deploy.log`, then record: (1) `php artisan migrate:status`, which migrations are recorded and which are pending; (2) the inventory query of [restore contract step 5](#the-restore-contract), compared with [the complete schema](#the-complete-schema) and with the tables the pending migrations would create; (3) the stored role keys **by their bytes**, with the read-only query of the [pre-flight](g10-release-readiness.md#pre-flight-read-only-queries-against-the-v030-database), compared with the pre-flight you recorded before the upgrade; (4) that the backup is intact (restore contract steps 2 to 4: its checksum and the keyring). Change nothing while you do this.

| Situation | What you will see | Safe immediate response | What needs investigation or authorization |
| --- | --- | --- | --- |
| **A. A schema migration failed** (a database error such as `Table ... already exists`, a lock or timeout, or the connection or disk failing mid-migration) | `migrate` exits non-zero with a database error. `migrate:status` shows that migration and every later one pending. **Depending on where it failed, it may have committed some schema changes (tables, columns or indexes it was creating), none, or all but its ledger row**; only the inventory and the ledger tell you which. The role keys are unchanged unless the rename was reached | Keep maintenance on. Do not run `migrate` again to find out (if schema changes were committed it can fail on the first object that now exists, as L3a measured, and it repairs nothing; if none were, it could run on and change the schema further; either way it is a blind retry), do not run `migrate:rollback` (every `down()` destroys data), and do not drop or edit the stray objects. Save the output and the readings above | An authorized operator and the product owner decide between **restoring the pre-release backup** (the `restore-required` procedure, which clears the reviewed list of tables first; L3a rehearsed this from a 32-table half-failed state) and anything else. Going forward from a half-migrated schema is not a documented procedure and is not authorized here. If the inventory and the ledger do not let you tell exactly what was committed, treat the release as failed and escalate |
| **B. The role rename refused** (`2026_10_09_000002_rename_guardian_role_key`: "Refusing to rename role key guardian to guardian-full: a guardian-full assignment already exists", or a unique-key error, SQLSTATE 23000 / 1062, where a collation-equal variant of `guardian-full` sits on a Person who holds `guardian`) | The rename ran in one transaction and **rolled back**: every `role_assignments` row is as it was (still `guardian`), the rename and the later migrations are pending, and the migrations before it are recorded. [WP3b §9](../audits/g6/wp3-record.md#9-refused-upgrades-refusedupgradetest) measured the first on both engines and the second on MariaDB: 26 of 29 migrations recorded, none renamed, `sourced_role_grants` absent | Keep maintenance on. Do not edit, delete or rename any role row to "make it pass", do not run the migration again to see, and do not write the ledger row. Save the stored keys by their bytes | The refusal means the data holds something the migration was not written for. Deciding what to do about a role assignment is a decision about who holds authority, so it is the product owner's, with the pre-flight record. After an authorized, reviewed change to the data, the migration can be re-run ([WP3b §9](../audits/g6/wp3-record.md#9-refused-upgrades-refusedupgradetest) measured that it then completes, records the remaining three and runs nothing twice), but the schema is mid-upgrade until it does, so the alternative is **restoring the pre-release backup** |
| **C. The rename committed but is not recorded** (the process, the connection or the host stopped after the rename's transaction committed and before Laravel wrote its ledger row) | `migrate:status` shows the rename pending, yet `role_assignments` holds `guardian-full` where the pre-flight recorded `guardian`, and no exact `guardian` is left; the later migrations are absent. **This case was not measured**: it follows from the migration's code and from WP3b's run-twice test (an exact `guardian-full` row makes a second run refuse and change nothing). So a re-run would refuse with the same message as in B; here the refusal is the symptom, not the cause | Keep maintenance on. Do not rename the rows back, do not write the ledger row by hand, and do not run `migrate` hoping it passes. Compare the stored keys by their bytes with the pre-flight record: every former `guardian` holder now `guardian-full` and nothing else changed means C; anything else means B or something not described here | Whether to **restore the pre-release backup** (the known state, and the default) or to go forward by an authorized, reviewed correction of the ledger is the owner's decision. No such correction is documented or authorized in this repository. If the keys fit neither B nor C, escalate |
| **D. The upgrade completed and the release must be undone** (every migration recorded, the schema complete, then verification fails, the data is wrong, or the owner decides to go back) | `migrate:status` shows everything recorded and the schema is the complete 34 tables, so nothing here is half-done. The release classified `restore-required` cannot be reverted by code alone | Keep, or return to, maintenance. Do not run `migrate:rollback`. Preserve the failed state ([below](#before-anything-is-cleared-preserve-the-failed-state)) before anything is cleared | An authorized operator follows the [restore contract](#the-restore-contract) and the [`restore-required` procedure](deployment.md#restore-required--the-database-must-go-back-too) in full, including the Resource files and establishing the security state before the application reopens. Rehearsed locally in two forms; never on the host |

**In every row:** the failed state is evidence ([below](#before-anything-is-cleared-preserve-the-failed-state)) and is preserved before anything clears it; the backup is verified before it is relied on; and the application stays in maintenance until a person has chosen and the security state is established. If what you find matches no row, or you cannot tell the rows apart, treat it as row A: maintenance stays on and the operator escalates to the product owner with the saved outputs.

## The restore contract

Checked **before the database is touched**, in this order. Each step is a stop, not a warning. Steps 1 to 9 are the database (step 6 preserves the evidence the clearing in step 7 destroys); the Resource files and the security state have their own sections and are steps 10 and 11; step 12 is verification.

**Why the schema is cleared first.** The dump's first pass drops and recreates only the tables it names. Loaded over a database that has been upgraded, it restores those tables and **leaves every newer table, and its data, in place** (G6 WP3-01, measured on MariaDB: 34 tables after loading a 16-table dump, the migration ledger saying 15, and a CRM profile surviving for a Person who no longer exists). The old release then runs normally, because it ignores unknown tables, and the next `migrate` fails with `Table ... already exists`. A restore that is judged only by the tables the dump contains passes. The step that matters is clearing first.

1. **Maintenance is on and nothing is writing.** Confirm it as in the [deployment runbook](deployment.md#4-enter-maintenance).
2. **Checksum.** `sha256sum` the dump and compare it with `dump_sha256` in the manifest. A backup you have not verified is a hope.
3. **Compute the live keyring fingerprints** from `APP_KEY` and `APP_PREVIOUS_KEYS` in the environment you are restoring into ([how](deployment.md#the-manifest)).
4. **Subset test — the load-bearing one.** Every key fingerprint the backup requires must be present in the live keyring:
   - **Live ring contains all of them** → proceed. A live ring that is a strict superset is the normal post-rotation case and is safe.
   - **Any fingerprint missing** → **stop.** Name the missing fingerprint and find that key before going further. Restoring anyway leaves every authenticator enrolled under it permanently unreadable, and no administrator can undo that.
5. **Name the target and take its inventory, read-only.** Confirm the database is the application's own (`DB_DATABASE` in `shared/.env`), then list everything in it:
   ```sql
   SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name;
   SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE();
   SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE();
   SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE();
   ```
   Every name must be in [the complete schema](#the-complete-schema): the 16 tables of the backed-up release, and after a failed upgrade some or all of the 18 added ones. Every `table_type` must be `BASE TABLE`, and the three counts must be 0. **Anything else — a table in neither list, a view, a trigger, a routine, an event — is a stop.** Do not widen the clearing to it.
6. **Preserve the failed state** ([below](#before-anything-is-cleared-preserve-the-failed-state)). The next step destroys it.
7. **Clear the application schema.** Drop every table of step 5's reviewed list, in one statement, with foreign-key checks off for that session only, with **no `IF EXISTS`** (a name that is not there should fail) and **no `DROP DATABASE`** (cPanel database users usually cannot, and the grants are not yours to recreate):
   ```sql
   SET FOREIGN_KEY_CHECKS=0;
   DROP TABLE `accounts`, `people`, /* ... exactly the reviewed names ... */ `sessions`;
   SET FOREIGN_KEY_CHECKS=1;
   ```
   Then repeat the first query of step 5: it must return **no rows**.
8. **Load the dump** into the emptied database, for example `gunzip -c "$B/dump.sql.gz" | mysql -u <user> -p <db>` (the password prompt, never the command line). The dump names no database.
9. **Verify the inventory and the ledger.** The table set must equal the manifest's `tables_with_data` ∪ `tables_schema_only` **exactly**: nothing missing and **no table left over** (restoring v0.3.0 leaves none of the 18 added). `migrations` must hold the restored release's ledger (15 rows for v0.3.0). Every transient table exists and is empty.
10. **Resource files:** [as above](#restoring-files-with-the-database).
11. **Establish the security state** ([below](#security-state-after-a-restore-stop-unless-it-is-established)). **The application does not come up until it is established.**
12. **Verify** as after a deployment ([health and release verification](deployment.md#7-health-and-release-verification)), then have someone sign in **with their second factor**: the fingerprint test shows the right keys are present, and only a TOTP sign-in shows they decrypt. On a release that has it, run `relationships:check` too (v0.3.0 does not).

**Before the release is attempted again**, the next `migrate` must run against this clean schema. If it fails with `Table ... already exists`, step 7 did not clear everything: stop and find out why.

What was **rehearsed locally** is steps 2 to 4, the inventory in step 5 (by SQL alone, before any destructive statement), the preservation in step 6 (in the rehearsal's own variant, see [the table above](#what-is-established-what-was-rehearsed-and-what-is-not)), the clearing in step 7 (naming the tables from the rehearsal's own list), the load in step 8 (an uncompressed file, by the real `mariadb` client), the checks in steps 9 and 12 (the sign-in was in-process and rolled back, and was run after the historical restore, not the paired one) and the Resource files in step 10, followed, for the historical restore, by a successful re-upgrade. **Not rehearsed:** step 1; the operator's timeline and this runbook's own `mysqldump` export in step 6; deriving the table list from the live database (step 5); step 11 (nothing reconciles the security state); `relationships:check` after a restore; a gzipped load; and everything on the production host.

If step 4 fails and the key genuinely cannot be found, the platform is still recoverable and no passwords are lost — see the next section — but every enrolled authenticator must be reset and re-enrolled.

## Before anything is cleared: preserve the failed state

> **Status: contract. A variant was rehearsed locally (WU3, WU6); the commands below were not run.** The rehearsal's `preserve-failed-state` measures the failed database and Resource files, exports them with its fixed two-pass dump as the application account (the structure of all 34 tables and the rows of the 27 durable ones, **not** the transient rows item 2 asks for), measures again and writes a record that binds the export by checksum. Its restore refuses to clear anything without a valid record, and the refusals for a missing and for an altered record were exercised live. It does not rehearse the timeline (item 1), the `mysqldump` command (item 2), the handling rules (item 3) or any comparison with the restored database.

The restore destroys the database as it stands, and that database is the only evidence of what changed after the backup. It cannot be recovered once step 7 has run.

1. **Write the timeline down first**, from `shared/deploy.log` and the manifest: when `artisan down` was run, the backup's `taken_at`, when `migrate` started and stopped, whether and when `artisan up` was run, when the failure was noticed and when maintenance was re-entered, and every command anyone ran on the server that changes who may sign in (`identity:create-administrator`, `identity:reset-mfa`). Add the web server's access and error logs for the interval **if the host retains them** (not confirmed).
2. **Export the failed-state database in full, before step 7**, as one complete dump with every table's rows, including the transient ones. Use the same client as a backup, in the same window:
   ```bash
   ( umask 077; mysqldump --single-transaction --no-tablespaces <db> > "$B/failed-state.sql" )
   sha256sum "$B/failed-state.sql"
   ```
   If the Resource directory exists, it is moved aside, never deleted ([above](#restoring-files-with-the-database)).
3. **Handle the export as the most sensitive file you hold.** It contains password hashes, authenticator ciphertext, recovery-code and invitation digests, sessions and reset-token hashes, and personal data, including the free-text notes in `contact_interactions`. Mode `0600`, in a directory outside the document root and outside `releases/`; copied off the host to the owner's secure store and **not into the store that holds the encryption keys**; never in the repository, a chat, a ticket or an email. The owner decides how long it is kept and that it is then destroyed. It is evidence for this one decision, not a second backup.

## Security state after a restore: stop unless it is established

> **Status: contract only. Not rehearsed.** No part of it is automatic, and none will be: **no automatic replay, no break-glass command and no `tinker` recovery procedure is authorized.** The first half of the table below, that a restore *undoes* a change made after the backup, is demonstrated for one case (a role revoked after the backup: the assignment comes back and the event of its revocation goes) by the local rehearsal's `revoke-after-backup` and `restore-historical` ([development note](../development/restore-rehearsal.md#slice-22c-1-a-half-failed-upgrade-and-a-security-change-made-after-the-backup)); that is tested against a fake daemon and was run live once (L3a, 2026-11-16, [record](../development/restore-rehearsal.md#l3a-the-live-half-failed-upgrade-rehearsal-passed-2026-11-16): synthetic data, one local machine, no production host). Neither the rehearsal nor L3a reconciles, replays or repairs anything: what the operator does about it is still contract only.

Restoring an older database does not only lose data. It **puts back the security state of the backup moment**, and quietly takes away the record of what changed since. Whatever was done to access after the backup is undone, and the audit trail of it goes with it:

| What was done after the backup | What the restore does | Why it matters |
| --- | --- | --- |
| A password changed or reset (`password.changed`, `password.reset_completed`) | The old password hash is back | A credential that was replaced because it was compromised works again |
| An Account disabled (`account.disabled`) | It is enabled again | Access that was withdrawn is back |
| A role revoked (`role.revoked`); a relationship withdrawn or its sourced grant withdrawn | The assignment or grant is back | The same |
| A Membership revoked (`revoked_at`) | The grant is live again | The same |
| An authenticator replaced or reset; recovery codes regenerated or used (`mfa.*`) | The old authenticator and un-spent codes are back | A second factor that was retired, or a code that was spent, works again |
| An invitation accepted, reissued or revoked (`invitation.*`) | An accepted invitation is pending again, and its Account is `invited` again; a revoked one is live | Someone holding the old link can set a password. An Account that is not `invited` cannot be activated by an invitation (`AcceptInvitation`), so this is narrower than it sounds, but it is a restored way in |
| Anything else that reduced, withdrew or replaced access | Undone | |

A change that *grants* access after the backup is simply lost; that is a data loss to record, not a security exposure. **The dangerous direction is access or a credential that was withdrawn coming back.**

**What the operator must establish before the application may come up:**

1. **The exposure interval:** from the backup's `taken_at` to the moment the database was exported (step 6). If maintenance was never lifted after the backup and no server-side command changed access in the interval, it is empty by construction. Show it: the timeline says so, and the exported database has no `security_events` row with `occurred_at` after `taken_at` (times are UTC):
   ```sql
   SELECT COUNT(*), MAX(occurred_at) FROM security_events WHERE occurred_at > '<taken_at>';
   ```
2. **If the interval is not empty, or the timeline cannot be completed:** compare the failed-state export with the restored database, in the tables that hold access, **by primary key and by the columns that decide it**: `accounts` (`status`, `disabled_at`, `password_hash`, `password_updated_at`, `security_generation`), `role_assignments`, `sourced_role_grants`, the four `person_relationship*` tables, `membership_grants` (`revoked_at`, `ends_at`), `account_totp_factors`, `account_recovery_codes` (`used_at`), `account_invitations` (`accepted_at`, `expires_at`). **Do not rely on `security_events` alone.** It is evidence, not a proven-complete record: a deleted row (a revoked role) leaves no timestamp behind, and whether every access change is audited is not established here. Load the export only into a scratch database made for the purpose, never into the production database, or read the dump directly.
3. **Corroborate with records that live outside the database:** the deploy log, the host's logs, and the people who administer access.

**The decision is binary.**

- The interval is empty, **or** every difference is explained by trustworthy independent records **and** none of them is access or a credential coming back → record the evidence in the deploy log and proceed.
- **Anything else — a timeline you cannot complete, an export you did not take or cannot read, a difference you cannot explain, or any access-reducing change the restore has reversed — means the security state is not established. The application stays in maintenance and the operator escalates to the product owner.**

**This runbook gives no way to re-apply a reversed change, on purpose.** The Console is unavailable during maintenance and no bypass exists ([ADR 0027](../adr/0027-release-and-deployment-model.md)); the platform has no server-side command to disable an Account, revoke a role, a grant or a Membership, or revoke an invitation (the commands that exist are `identity:create-administrator`, `identity:reset-mfa`, the two prune commands and the relationship reports). Re-applying through anything else is not documented, tested or authorized. The owner decides what happens next, and records it in `shared/deploy.log`. Reopening the application is that decision, not a step.

## If a database is restored under the wrong key

Not fatal, and worth knowing before it happens:

1. Everyone with an enrolled authenticator fails their second factor with a **server error**, not "wrong code" — the platform distinguishes these deliberately, so the cause is visible.
2. They sign in with a **recovery code** instead: those are digests and do not depend on the key.
3. Anyone with no recovery code left is recovered by another administrator, or by `identity:reset-mfa` on the server for the last administrator ([MFA recovery](mfa-recovery.md)).
4. Everyone enrols a new authenticator.

Passwords are unaffected throughout: they are bcrypt hashes.

## Audit growth

`security_events` is **append-only and never pruned** — the scheduled maintenance explicitly leaves it alone. It grows with every sign-in, failure, rate limit and administrative act. At the scale this platform is designed for (an invite-only operator directory) that is tens of rows a day, and a backup that grows slowly is the right trade against losing security history.

It is not unbounded attention-free forever. Recorded as a production follow-up, with no work in this phase:

- a retention policy, decided rather than assumed, with archival or export rather than deletion;
- an operator-facing audit viewer, which will need indexes this schema does not have (`occurred_at`, and probably `(subject_account_id, occurred_at)`);
- whatever legal retention obligations a charity's records carry, which is not an engineering decision.

**Do not solve this by deleting security history.**
