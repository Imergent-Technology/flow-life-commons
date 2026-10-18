# G6 WP3 — Data integrity, upgrade and restore safety: independent audit

## A. Executive verdict

There are no BLOCKER or HIGH findings. There is one MEDIUM and six LOW findings, plus six informational notes.

- **The upgrade is low-risk on MariaDB.**
  - None of the 14 pending migrations alters a table that v0.3.0 has.
  - The only change to existing data is the `guardian` → `guardian-full` rename. It is exact and atomic, and it keeps every row. Disabled Accounts and wrongly-cased keys stay without authority.
  - The nine extra capabilities match the runbook exactly.
- **The weak point is recovery, not the upgrade.**
  - The documented rollback restores the dump into the *same* database.
  - I showed on MariaDB 10.11.18 that this leaves all 18 post-upgrade tables and their rows in place, including an orphaned row. The v0.3.0 table checksums still match, and the next upgrade attempt fails at its first migration.
  - The approved WP3c rehearsal restores into a *fresh* database, so it would pass without seeing this (WP3-01).
- **The approved order WP3a → WP3b → WP3c still holds**, with the additions in §M.

## B. Repository checkpoint

| Item | Verified |
| --- | --- |
| `origin/main` | `a7af9a0f25233244426daa2afadcaabbf32249b3`, "G6 WP2: Authorization and disclosure hardening (#12)". I fetched it and confirmed the exact SHA |
| Post-merge CI `39031098500` | Not checked independently. I took it from the prompt and did not query GitHub |
| v0.3.0 | The annotated tag `v0.3.0` resolves to `2f385b79a7b83323c6f15a6685ee610ccd85b5bf`. Release id `20260927T072528-2f385b7` and MariaDB 10.11.18 come from the repository records ([deployment.md §11](docs/runbooks/deployment.md#L713)); I did not contact the host |
| Migrations | v0.3.0 ships exactly 15. Each has the **same git blob id** on `main`, so none has been modified. `main` has 29 |
| Framework | `laravel/framework` is v13.32.0 at both v0.3.0 and `main` |
| Audit worktree | A detached worktree at `a7af9a0` in the session scratchpad. It is now removed |

## C. Migration inventory

**Deployed in v0.3.0 (15):**
- `0001_01_01_000001`–`000002`: cache and jobs
- `2026_09_19_000001`–`000003`: people, accounts, invitations
- `2026_09_20_000001`–`000005`: security events, sessions, role assignments, the `role_key` index, reset tokens
- `2026_09_21_000001`–`000002`: TOTP factors, recovery codes
- `2026_09_22_000001`: the invitation `channel` column
- `2026_09_23_000001`–`000002`: `security_generation`, Membership grants

Together they create 16 tables, counting `migrations`.

**Pending (14):**

| # | Migration | Effect on a populated v0.3.0 database | Risk |
| --- | --- | --- | --- |
| 1–4 | `2026_10_01_000001`–`000003`, `2026_10_02_000001`: CRM | 5 new tables. 4 FKs to `people.id`, all RESTRICT | Partial DDL only |
| 5–6 | `2026_10_02_000002`–`000003`: Discussions | 2 new tables. One internal FK | Partial DDL only |
| 7–10 | `2026_10_04_*`: Resources | 6 new tables. `000004` alters `resource_cards`, which was created in the same release | Partial DDL only |
| 11 | `2026_10_09_000001`: relationships | 4 new tables. FK to `people` plus 3 internal FKs | Partial DDL only |
| 12 | `2026_10_09_000002`: rename | **The only change to existing rows** | §E |
| 13 | `2026_10_09_000003`: `sourced_role_grants` | New table. FK to `people` | Partial DDL only |
| 14 | `2026_10_11_000001`: provenance | Adds a nullable column to `contact_methods`, a new table | None |

Captured with `migrate --pretend` against a v0.3.0-state MariaDB database [T]:
- **59 DDL statements across 13 migrations.** On MariaDB each one commits implicitly.
- **16 foreign keys, all `on delete restrict`.** Six reference `people.id`. There is no CASCADE.
- **No `ALTER` of any v0.3.0 table.**
- **No new constraint on existing data**, apart from how the rename interacts with `role_assignments`' unique key (WP3-02).
- **No migration seeds data**, so every new table starts empty.

## D. MariaDB upgrade risks

- **Locks.** Adding a foreign key from a new, empty table takes a brief metadata lock on `people`. The rename touches only `role_assignments` rows. Inside the maintenance window there is no competing writer: v0.3.0 is down, the scheduler skips, and production runs no queue worker. This is negligible [I].
- **Implicit commits and partial failure.**
  - On MariaDB, Laravel does not wrap a migration in a transaction (`Grammar::$transactions` is false; only PostgreSQL is true).
  - A failure inside any of the 13 DDL migrations therefore leaves its earlier tables and indexes in place, with no `migrations` row.
  - Re-running then fails with "already exists", so recovery is a restore or a manual drop.
  - On PostgreSQL the Migrator wraps each migration, so nothing is partial *within* a migration. Earlier migrations stay either way [I, Laravel source].
- **Orphans from existing data.** None are possible. Every new FK sits on an empty new table [T].
- **Nullability and defaults.** The only added column is nullable, on a table that has no production rows [I].
- **Ordering.** Dependencies are satisfied: tags come before assignments, discussions before messages, packs before cards before assets, and `contact_methods` before its provenance column [I, T].
- **Retrying partial states:**
  - **A refused or failed rename (#12)** leaves #1–11 committed and logged on both engines. `migrate` can resume after a data fix, but no document says so (WP3-11).
  - **A crash after the rename commits but before Laravel logs it** (`Migrator::runUp` logs outside the transaction) makes every re-run refuse, with a message that misleads ("guardian-full already exists").

## E. Role-key migration

| Objective | Result |
| --- | --- |
| Only exact `guardian` is renamed | Yes. SQL `where`, then a strict PHP `===` filter. `Guardian` is unchanged on both engines [T]; trailing-space and accented variants are matched by MariaDB's SQL but rejected by the PHP check [I] |
| Person, grantor, timestamp and row count are kept | Yes. The existing whole-table snapshot test passes on 10.11.18 and PostgreSQL 16 [T] |
| Administrators keep their authority | Yes, and they gain every new capability automatically through `Capability::cases()` [T] |
| Existing sessions are recalculated | Yes. Sessions hold no capabilities, the `ConsoleSession` format is unchanged since v0.3.0, and the Authorizer re-derives on every request. **Live v0.3.0 Guardian-role sessions gain the nine capabilities at `artisan up`, with no new sign-in** (WP3-10) [T, I] |
| Disabled Accounts | No authority. The Authorizer re-resolves the Account on each call [I, existing tests] |
| Second run | Refused, and nothing changes [T] |
| `down()` | Development only, and it refuses if a `guardian` row exists. The restore-required classification is correctly identified [I, T] |
| `guardian-full` already held by the same or another Person | Refused, nothing changes [T] |
| **A case, space or accent variant of `guardian-full` on the same Person as `guardian`** | **MariaDB: raw `1062 Duplicate entry`, rolled back atomically. PostgreSQL: succeeds.** Not covered by any test. It contradicts ADR 0038 M6 (WP3-02) [T] |
| Hidden transaction risk | The helper's explicit transaction is the *only* transaction on MariaDB. A mutant without it leaves a **partial rename**, and every re-run is then refused. No existing test kills that mutant (WP3-03) [T] |
| MariaDB concurrency | A plain read, then updates by primary key. Safe only because the maintenance window excludes writers. Acceptable [I] |

**Effective capabilities.** `Role::organizationalBundle()` matches [g10-release-readiness.md](docs/runbooks/g10-release-readiness.md#L35) row for row: 10 capabilities, nine of them new. The order of the `/me` literal is pinned by `CurrentAccountCapabilitiesTest:72` [T].

## F. WP3a readiness

The plan is sound. Copying the logic into the migration preserves behaviour **if**:

1. **The explicit `DB::transaction` is kept.** It is load-bearing on MariaDB only.
2. **The default connection is used** (`DB::connection()`). `Migrator::usingConnection` switches it for `--database`, so nothing should name a connection.
3. **The strict PHP `===` filter stays.** It must not be replaced with `BINARY`/`whereRaw`: that is engine-specific SQL (charter rule 13), and it is invalid on PostgreSQL.
4. **Helper functions become private methods of the anonymous class.** Tests will `require` the migration file once per test, and top-level functions would fatal with "Cannot redeclare".
5. **The code passes Larastan at level max.** `database/` is in its paths.

`RenameGuardianRoleAssignments` has no other users: only the migration and its test reference it [T, grep].

**Equivalence tests to add.** Port all 9 existing cases to the migration file, plus:
- **(a)** A MariaDB discriminator: Person A has `guardian`; Person B has `guardian` and `Guardian-Full`. Assert that no row changed. This kills the "no transaction" mutant on MariaDB; it cannot fail on PostgreSQL, by design.
- **(b)** Same-Person variant rows with `GUARDIAN-FULL`, `guardian-full ` and `guardián-full`. Pin the MariaDB 1062 and the PostgreSQL success as the *preserved* behaviour (WP3-02).

**Planted mutants that must die:**
- `===` changed to a case-insensitive comparison (killed on MariaDB)
- the refusal removed
- the transaction removed (killed on MariaDB)
- a `whereRaw(lower())` filter (killed on both engines)

**Deployed-migration pin (narrowest fit).**
- **Where:** one `tests/Architecture/DeployedMigrationsTest.php`, which needs no database.
- **What it holds:** a literal `['v0.3.0' => [filename => sha256(bytes)]]` map, computed with `hash_file('sha256', database_path('migrations/'.$name))`. It must never `require` a migration.
- **Why raw bytes are safe across platforms:** `.gitattributes` sets `* text=auto eol=lf`, so file bytes are LF on every platform.
- **Assertions:**
  1. Each pinned file exists, and its hash matches.
  2. Every unpinned migration name sorts **after** the last pinned one. A back-dated migration would otherwise run mid-sequence on fresh databases but last on production.
- **Guarding the manifest itself:** the docblock gives the regeneration command (`git show v0.3.0:<path> | sha256sum`). Each future release appends a new block only after `release:show` confirms the deployment.

**Mutation evidence to record:**
1. Change `restrictOnDelete()` to `cascadeOnDelete()` in `create_role_assignments_table`.
2. Make a whitespace-only edit.
3. Delete a pinned file.
4. Add `2026_09_19_999999_x.php`.
5. As a negative control, a new migration dated after the last pinned one passes.

## G. WP3b readiness

- **Separate database.**
  - The upgrade test cannot sit under `Feature/`. RefreshDatabase wraps each test in a transaction that MariaDB DDL would commit, and the test database is already fully migrated.
  - Put it in its own directory, outside RefreshDatabase, like `tests/Concurrency`.
  - Point it at a dedicated database (e.g. `flowlife_upgrade_test`), created by `ensure_mariadb_test_db` and `ensure_postgres_test_db`; CI uses the same functions through `./flow check`.
  - Drop all of its tables at the start and at the end.
- **Historical schema.**
  - Run the 15 pinned files with `migrate --database=upgrade --path=… --realpath`, then plain `migrate`. Both work on both engines [T].
  - Running them with current code is faithful because the framework version is the same. An isolated v0.3.0 application is not needed for 3b; 3c covers that.
- **Fixtures as literal rows** (`DB::connection('upgrade')->table()->insert`), with fixed ids. **Do not use current domain helpers or sign in before the upgrade.**
  - Current code cannot run against the v0.3.0 schema: the Authorizer reads `sourced_role_grants`, so any authorization or MFA-policy call fails.
  - Domain helpers would also couple the fixture to code that can change.
- **Encrypted data.** Build TOTP ciphertext at run time with the framework encrypter and the test key, never from a stored literal. After the upgrade, assert the bytes are identical and that the secret still decrypts.
- **Snapshot.**
  - Take `SELECT *` of all 16 v0.3.0 tables, ordered by key, before and after, on the same engine. No timestamp normalization is needed: the migrations touch none.
  - Expected differences: exact `guardian` → `guardian-full`, and 14 new `migrations` rows.
- **Fixture additions to the approved matrix** (keys are limited to what v0.3.0 could hold):
  - a `guardian` holder who is also an administrator
  - `guardian` holders whose Account is invited, or disabled, or who have no Account
  - variants `Guardian`, `GUARDIAN`, `guardian `, `guardián` on *separate* Persons
  - a `Guardian-Full` variant on another Person
  - a raw `sessions` row, which must survive byte-identical
- **Capability matrix as a literal list copied from the runbook.** It must not be derived from `Role::GuardianFull->capabilities()`, as the existing test does: a derived list cannot detect capability drift.
- **Separate negative test:** a planted exact `guardian-full`. The upgrade stops at #12 with #1–11 logged and `role_assignments` unchanged.
- **Diagnostics:** `relationships:check` exits 0. The adoption report lists every independent `console.access` holder, whatever their Account status [I], so the expected list includes the disabled, invited and no-Account holders.
- **Mutants:**
  - The case mutant dies on MariaDB only (PostgreSQL's SQL is already exact), so record it as engine-specific.
  - The capability-drift mutant (add or remove one capability in the bundle) dies on both engines.

## H. WP3c readiness

The safety boundaries are adequate if the script:

- **Uses its own minimal Compose file.** It needs `mariadb:10.11.18` plus the PHP image, a fixed distinct `-p` project name, its own network and named volumes, and **no published ports**. It must not reuse `compose.yaml` under another project name: that file publishes the gateway ports and bind-mounts `apps/guardian-console/dist` and `apps/platform`.
- **Refuses a non-local Docker daemon.** It should check that `docker context show` is `default` and that `DOCKER_HOST` is unset or a unix socket. A remote context is the realistic non-local vector.
- **Pins database hosts and credentials** to the Compose service names, and refuses any inherited `DB_*` variable.
- **Generates synthetic `APP_KEY`s per run.** It must never copy `apps/platform/.env`, and must print fingerprints only, never keys.
- **Removes only its own project** (`down -v`). It must never prune. It removes container-written root-owned files through a container, after asserting the path sits under its own root.
- **Builds v0.3.0 with its own `composer install`.** The v0.3.0 `composer.lock` differs from `main`'s by 11 lines.

**Amendments the approved plan needs:**
1. **Restore in place over the upgraded database**, which is the real rollback path (WP3-01).
   - Assert the post-restore table set equals the manifest's.
   - Re-run the upgrade successfully after the rollback.
2. **Sign in with TOTP after the restore**, not only a password. Otherwise the keyring is fingerprinted but never exercised.
3. **Take the v0.3.0 backup exactly as documented**, so the `tar` failure is reproduced (WP3-04).
4. **In Cycle B, plant an unreferenced file younger than 24 h** and show that the current check misses it (WP3-05).
5. **Half-failed demonstration:** plant a `throw` after the first `Schema::create` in `create_person_relationships_tables`.
   - Record which tables persist, which `migrations` rows exist (#1–10), and the "already exists" re-run failure.
   - Then show what an in-place restore leaves behind.
   - Write down that PostgreSQL rolls back the whole migration (Migrator wrapper).

## I. Backup and restore documentation

**Confirmed:**
- The two-pass dump uses an *ignore list*, so all 18 new durable tables are carried with data, with no command change.
- The six `resource_*` names in the runbook match the migrations.
- Only `account_totp_factors.secret` is ciphertext. No new module encrypts anything.
- The cipher and keyring config are unchanged since v0.3.0.
- The scheduler skips `resources:assets:prune` and `identity:prune-expired` during maintenance.

**Gaps:** WP3-01, and WP3-04 to WP3-08.

## J. Test evidence

All runs used **my own disposable containers**: `mariadb:10.11.18` (reports `10.11.18-MariaDB-ubu2204`) and `postgres:16-alpine`, on a private network with no ports. I never touched the shared flowlife development databases.

| Run | Engines | Result |
| --- | --- | --- |
| `pest tests/Feature/Modules/Access/GuardianRoleKeyMigrationTest.php` | both | 9 passed, 92 assertions, each engine |
| …plus `CurrentAccountCapabilitiesTest`, `CatalogTest` | both | 67 passed, 352 assertions, each engine |
| Scratch probe `RenameCollisionProbeTest` (untracked, deleted with the worktree; a copy is in the scratchpad) | both | 6 probes each. Results in §E |
| `migrate --path=<v0.3.0 files>`, seed, two-pass `mysqldump`, `migrate --pretend`, `migrate`, in-place restore, `migrate` again | MariaDB | WP3-01 evidence |
| Historical then pending migrations | PostgreSQL | 29 migrations; exact key renamed, `Guardian` untouched |
| GNU tar 1.35 on a missing `resources/` | host | exit 2, 45-byte empty archive |

**Limits:**
- No full gate was run, and no browser run.
- WP3-05, WP3-07, WP3-08, WP3-12 and WP3-13 rest on inspection only.
- I did not test the crash window between commit and log.

## K. Findings

**WP3-01 — MEDIUM — An in-place restore leaves the post-upgrade schema and data behind**
- **Where:** [backup-and-restore.md:92](docs/runbooks/backup-and-restore.md#L92) ("Restore the dump"); [deployment.md:390](docs/runbooks/deployment.md#L390).
- **Contract violated:**
  - `restore-required` means the database goes back.
  - ADR 0021 says no orphans are representable.
  - The deployment runbook says not to improvise under pressure.
- **Scenario:**
  1. The release fails after migrating (or part-way through).
  2. The operator loads the dump into the production database, as documented.
  3. Pass 1 only drops and recreates the 16 tables it names.
  4. Measured: 34 tables remain, `migrations` has 15 rows, and the v0.3.0 checksums match the pre-upgrade snapshot.
  5. A `contact_profiles` row for a Person who no longer exists survives, and so does a `sourced_role_grants` row.
  6. The next `migrate` fails: `1050 Table 'contact_profiles' already exists`.
- **Preconditions:** any `restore-required` rollback, or a restore after a partial migration.
- **Impact:**
  - v0.3.0 runs normally, because it ignores unknown tables.
  - But personal data from the failed window persists invisibly, FK invariants are broken, and re-attempting the release is blocked mid-window.
  - Working around it (dropping some tables, or marking migrations as run) could bring stale sourced grants back to life.
- **Smallest remediation (documentation):**
  - Before loading, drop every table in the database. cPanel database users usually cannot `DROP DATABASE`.
  - After loading, check that the table set equals the manifest's `tables_with_data ∪ tables_schema_only`.
- **Discriminating test:** WP3c Cycle A restores in place, asserts table-set equality, and re-runs the upgrade successfully.
- **Effect:** WP3c acceptance must include this. It is a release prerequisite (checklist item 5).

**WP3-02 — LOW — The rename collides with a key variant on MariaDB**
- **Where:** [RenameGuardianRoleAssignments.php:28](apps/platform/app/Modules/Access/Infrastructure/RenameGuardianRoleAssignments.php#L28); [ADR 0038:1213](docs/adr/0038-organizational-relationships-and-resource-viewing-authority.md#L1213) ("cannot collide").
- **Scenario:** a Person holds `guardian` and `Guardian-Full` (or `guardian-full `, or `guardián-full`).
  - MariaDB's `utf8mb4_unicode_ci` unique key treats these as equal → `1062`, rolled back atomically. The migration fails at #12 with #1–11 committed.
  - PostgreSQL succeeds.
- **Preconditions:** a manual edit; the v0.3.0 catalog could not create such a key.
- **Remediation:**
  - No code change under D8.
  - Correct the M6 sentence.
  - Detect the case beforehand with the read-only pre-flight query (WP3-10).
  - A collation-aware refusal would change semantics, so it needs an owner decision.
- **Test:** WP3a test (b).
- **Release effect:** none if the pre-flight query is clean.

**WP3-03 — LOW — The load-bearing transaction is not pinned by any test**
- **Where:** [RenameGuardianRoleAssignments.php:28](apps/platform/app/Modules/Access/Infrastructure/RenameGuardianRoleAssignments.php#L28).
- **Scenario:** WP3a drops `transaction()`, reasoning that "migrations run in a transaction" (true only on PostgreSQL).
  - A failure mid-loop on MariaDB leaves a partial rename. Measured: row A renamed, row B not.
  - Every re-run is refused.
- **Remediation and test:** WP3a test (a), with the mutant recorded as killed on MariaDB.
- **Effect:** a WP3a acceptance item.

**WP3-04 — LOW — The documented backup fails on a v0.3.0 host; Resources rollback steps call a command v0.3.0 lacks**
- **Where:** [deployment.md:499](docs/runbooks/deployment.md#L499) and [backup-and-restore.md:55](docs/runbooks/backup-and-restore.md#L55); restore step 4 at [backup-and-restore.md:66](docs/runbooks/backup-and-restore.md#L66).
- **Scenario:**
  - `shared/storage/app/private/resources` does not exist before Resources has written anything (neither storage skeleton has it). `tar` exits 2 and writes an empty archive, which the next line checksums.
  - On rollback to v0.3.0, `resources:assets:prune` does not exist.
- **Remediation (documentation):**
  - Guard the `tar` step (`[ -d … ]`, with count 0), or create the directory with mode 0700.
  - Add a rollback-to-v0.3.0 note: move `resources/` aside, keep it, and skip the prune.
- **Test:** WP3c Cycle A backup.
- **Effect:** confusion in the maintenance window; no data loss.

**WP3-05 — LOW — The post-restore Resources check reads 2 of the command's 5 lines**
- **Where:** [backup-and-restore.md:66](docs/runbooks/backup-and-restore.md#L66) against `PruneAssetsCommand`.
- **Scenario:** a mismatched pair whose extra files are younger than 24 h is listed under "unreferenced but younger…". It passes both checks the runbook names, which defeats its "same backup" purpose.
- **Remediation:**
  - Also expect "younger" = 0 and "not a Resources storage key" = 0.
  - Expect `kept` = `resource_files_count` = `COUNT(resource_assets)`.
- **Test:** WP3c Cycle B planted file.

**WP3-06 — LOW — The table inventories are stale or incomplete**
- **Where:**
  - [deployment.md:505](docs/runbooks/deployment.md#L505) and :539
  - the backup-and-restore consistency table
  - [ADR 0027:167](docs/adr/0027-release-and-deployment-model.md#L167)
  - [data-ownership.md:23](docs/architecture/data-ownership.md#L23)
- **Detail:**
  - 12 tables are missing from the backup lists: 5 CRM, 2 Discussions, 4 Relationships and `sourced_role_grants`.
  - `contact_interactions` (free-text notes about people) is missing from data-ownership too.
  - The consistency table does not say that `person_relationships*` and `sourced_role_grants` must come from one point in time, even though orphaned grants are honoured (K11).
  - Restore verification does not include `relationships:check`.
- **Remediation:**
  - Correct all four documents. The approved 3c covers only the runbooks, not ADR 0027 or data-ownership.
  - Add `relationships:check` to restore step 6.

**WP3-07 — LOW — The restore contract does not name reverted security changes**
- **Detail:** a restore reverts password changes, disables, role revocations and grant withdrawals made after the backup, and removes their `security_events`.
  - The runbook names only TOTP replacement, recovery codes, invitations and Membership revocations.
- **Remediation (documentation):** before `artisan up`, re-apply every authority-reducing action recorded since the backup.

**WP3-08 — INFORMATIONAL — Keyring fingerprints**
- **Where:** [deployment.md:554](docs/runbooks/deployment.md#L554).
- **Detail:** `k` is not defined: the literal `base64:` string, or the decoded bytes? No reference implementation exists.
- **Effect:** a mismatch fails safe (stop), but it is avoidable. WP3c should fix the specification and supply a one-liner.

**WP3-09 — INFORMATIONAL — The release build does not block an edited deployed migration**
- **Detail:** `./flow release build` already flags modified or removed deployed migrations, but a `restore-required` build needs no acknowledgement ([release.sh:283](scripts/lib/release.sh#L283)). That supports the WP3a pin.
- **Optional:** make "modified" or "removed" a hard refusal.

**WP3-10 — INFORMATIONAL — Upgrade-day facts the owner needs**
- **Sessions:** signed-in holders gain the nine capabilities at `up`, without signing in again.
- **Latent holders:** invited, disabled and Account-less holders are renamed too, and gain the bundle when they activate.
- **The documented count overcounts:** the host-step count query ([g10-release-readiness.md:110](docs/runbooks/g10-release-readiness.md#L110)) on MariaDB also matches case, space and accent variants.
- **Proposed read-only pre-flight**, for the owner's count:
  - group by `HEX(role_key)` (exact bytes);
  - break down by Account status;
  - list the collations of v0.3.0 tables and columns (WP3-12).

**WP3-11 — INFORMATIONAL — Undocumented partial states inside `migrate`**
- **What:** the resumable stop at #12, and the crash window between commit and log, are described in §D. Neither is in the runbooks.
- **Remediation:** add a short "if `migrate` fails" decision table to the runbook.

**WP3-12 — INFORMATIONAL — Production collation is unverified**
- **Detail:** new FKs need `people.id`'s character set and collation to match the connection setting in `shared/.env`. A local rehearsal cannot prove this.
- **Remediation:** the WP3-10 pre-flight query.

**WP3-13 — INFORMATIONAL — Invitation reconciliation is defence in depth**
- **Detail:** `AcceptInvitation` already refuses any Account that is not `invited` ([AcceptInvitation.php:93](apps/platform/app/Modules/Identity/Application/AcceptInvitation.php#L93)). The runbook's "a way to set someone's password" overstates the risk for active Accounts.

**Accepted design (not defects):**
- `restore-required`
- the nine-capability expansion, pending authorization
- `down()` being development-only
- the refusal on any exact `guardian-full`
- the MariaDB/PostgreSQL difference in transactional DDL

## L. Owner decisions

1. **Triage WP3-01:** accept the restore-contract change (empty the database, then check the table set), and require in-place restore in WP3c.
2. **WP3-02:** keep D8's exact semantics (recommended), or authorize a collation-aware refusal.
3. **Approve adding the WP3-10 read-only pre-flight query** to release checklist item 4.
4. **Optional:** a hard refusal for modified deployed migrations in `./flow release` (WP3-09).
5. **Unchanged and still open:** authorizing the Guardian expansion for production, and the host rehearsal versus explicit risk acceptance. Nothing in this audit authorizes either.

## M. Recommended packages

The order stays the same.

- **WP3a:**
  - freeze the logic, with all of §F's equivalence tests and mutants
  - the deployed-migration pin, with its ordering assertion and 5 mutations
  - fix the M6 sentence and the "unchanged" comment
  - **Acceptance:** both engines green; the transaction and case mutants killed on MariaDB.
- **WP3b:** §G's structure, with a separate test database added to the two `ensure_*_test_db` functions. **Acceptance:** green on both engines; the case-variant mutant and the capability-drift mutant both caught.
- **WP3c:**
  - the script, with §H's safety checks and amendments 1–5
  - documentation corrections for WP3-01 and WP3-04 to WP3-08, WP3-11 and WP3-13
  - **Acceptance:** a rehearsal record including the in-place restore, table-set equality, re-upgrade, TOTP sign-in, fingerprints, checksums and the five-line prune report.

## N. Repository integrity

- **No tracked file changed:** no commit, push, PR, merge or deployment. No production host, production data or production secrets were involved.
- **One ref moved:** `git fetch` updated the remote-tracking ref `origin/main` from `18d059e` to `a7af9a0`.
- **Removed:**
  - the scratch worktree (`git worktree list` shows the original two entries)
  - the `wp3audit-*` containers and network
  - the `mariadb:10.11.18` image I pulled
- **Untouched:** the shared checkout (`e115dd2`, clean) and `flow-life-commons-d03`.
- **Kept in the scratchpad:** the probe and the DDL capture, at `/tmp/claude-1000/-home-tennyson-development-flow-life-commons/e1e8427c-23d2-4f8c-a3a9-b7949f74e0b8/scratchpad/`.

**G6 WP3 AUDIT: READY FOR OWNER TRIAGE**
