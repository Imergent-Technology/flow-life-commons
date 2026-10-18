# G6 WP3 — remediation record

- **Package:** WP3 Data integrity, upgrade and restore safety. Remediation of the [independent audit](wp3-audit.md) as ruled in the [owner's triage](wp3-triage.md). The triage authorizes the three work units separately: **WP3a** and **WP3b** are recorded below. **WP3c is planned and not yet authorized**; its section is left for that work.
- **Audit and triage files:** the audit is committed as `wp3-audit.md` from its heading (`# G6 WP3 — Data integrity…`) to its closing verdict line, unedited. The one-line "Written for:" hand-over note that preceded it was addressed to the owner, so it is not in the file (as for [WP2](wp2-record.md)). The owner's rulings are `wp3-triage.md`, verbatim in content. Its three header lines first ended in two spaces (Markdown hard line breaks), which `git diff --check` reports as trailing whitespace. At the owner's direction they now end in a backslash, CommonMark's other hard line break. The rendered text is the same and no word changed. (`wp2-triage.md`, already on `main`, keeps its two-space form.)

## WP3a — Migration freeze and immutability

- **Base:** `main` at `a7af9a0f25233244426daa2afadcaabbf32249b3` (PR #12, G6 WP2 squash-merged; post-merge CI run `39031098500`, taken from the assignment and not re-queried). **Branch:** `fix/g6-wp3a-migration-freeze`. **Integrated:** squash-merged to `main` as `95bcd841ebb144ba5b4bb3c333ab90286064f8ee` (PR #13); post-merge CI run `39098496073`, taken from the WP3b assignment and not re-queried. Nothing deployed. Production stays on v0.3.0.
- **Status:** implemented, validated, and **independently verified** ([§14](#14-independent-verification), [`wp3a-verification.md`](wp3a-verification.md)): no BLOCKER, HIGH, MEDIUM or LOW finding, and one INFORMATIONAL note, accepted. Integrated (see Base). Sections 1–13 are the implementer's evidence, not the independent review.
- **Who implemented it:** the assignment named Claude Sonnet 5.5. This session ran on **Claude Opus 5.5**. The verification should therefore be a separate session that did not write this code.
- **Scope kept:** WP3a only. No WP3b historical upgrade suite, no WP3c rehearsal script or runbook work, no production or staging access, no release build, no change to the role catalog, the capability bundles or the rename's semantics, no CI change.

### 1. Checkpoint and what I checked before changing anything

- **`origin/main`:** fetched; `a7af9a0f25233244426daa2afadcaabbf32249b3`. The shared checkout was clean on `fix/g6-wp2-authorization-disclosure` at `e115dd2`, whose tree is identical to `a7af9a0`.
- **Concurrent work:** other Claude sessions had this repository as their working directory, but nothing had been written since the WP2 docs commit (2026-10-16 22:14 -0700) and the tree was clean. I branched from `a7af9a0` in the shared checkout, because `./flow check` runs on the shared Compose stack, which mounts this checkout. All mutations ran in a separate detached worktree in the session scratchpad (§4–§6), never in the shared checkout. The `flow-life-commons-d03` worktree and every other branch are untouched.
- **v0.3.0:** `v0.3.0` is an annotated tag (tagger Tennyson Edward, 2026-09-26) whose commit is `2f385b79a7b83323c6f15a6685ee610ccd85b5bf`. It ships exactly 15 migrations. Each has the same git blob id and the same SHA-256 on this branch as at the tag (§5).
- **The helper's users:** `RenameGuardianRoleAssignments` was referenced only by the migration and by `GuardianRoleKeyMigrationTest`. The two remaining mentions are historical planning facts in the [G6 contract](README.md) (§2 and §3); they are left as written.
- **The audit's claims about the framework, checked in Laravel 13.32.0's source:**
  - `Migrator::runMigration` wraps a migration in a transaction only when `supportsSchemaTransactions()` is true. That is `Grammar::$transactions`: `false` by default, `true` for PostgreSQL and SQL Server. On MariaDB the migration's own transaction is therefore the only one.
  - `Migrator::usingConnection` (used by `migrate --database=`) sets the resolver's **default** connection. An unnamed `DB::connection()` inside the migration follows it.
- **Test engines:** the dev and CI MariaDB is the floating `mariadb:10.11`, which reported **10.11.19** here. Production is 10.11.18 ([G6-D09](debt-register.md)). The audit measured the same collision on 10.11.18, and every MariaDB result below matches it.

### 2. The freeze (D8)

**Files:**
- `database/migrations/2026_10_09_000002_rename_guardian_role_key.php` now holds the rename itself.
- `app/Modules/Access/Infrastructure/RenameGuardianRoleAssignments.php` is deleted, in a separate commit, after the equivalence proof (§3).

**What moved, and how each property was preserved:**

| Property | In the migration |
| --- | --- |
| Exact `guardian` → `guardian-full`, and the reverse in `down()` | The same loop over ids, one `UPDATE … WHERE id = ?` per row |
| The explicit transaction, in both directions | `$database->transaction(…)` around the refusal check and the loop, as before. The comment says why it must stay (MariaDB) |
| Strict PHP `===` | The same `where('role_key', $key)` read, then `$row->role_key === $key` in PHP. No `BINARY`, `lower()` or collation clause |
| The active/default connection | `DB::connection()` with no name, resolved at run time as the helper's caller did. `--database` therefore still applies (§1) |
| Refusal when an exact `guardian-full` (or, in `down()`, `guardian`) row exists | The same checks, with the same `RuntimeException` messages, byte for byte |
| Row identity, Person, grantor, timestamp | Only `role_key` is written. The whole-table snapshot tests still pass (§4) |
| No security event, no relationship, no new row | Nothing else is written. The tests count `security_events`, `person_relationships`, `sourced_role_grants`, `accounts` and `people` |
| Failure behaviour | Unchanged. A refusal is a `RuntimeException`; a unique-key rejection is Laravel's `UniqueConstraintViolationException`, rolled back by the transaction |
| Private methods, not functions | `idsWithExactKey()` is a private method of the anonymous class, so loading the file twice cannot redeclare anything |
| Larastan level max | Clean (`database/` is in its paths). `assert(is_string(…))` is kept from the helper for the row types |

**Comment correction.** The old comment said "Effective capabilities are unchanged: the new key is the same bundle", without naming its baseline. The new comment separates two facts:
- the rename keeps every holder on the bundle that the release's own catalog gives `guardian-full`, and the migration defines no bundle;
- production v0.3.0's `guardian` granted `console.access` only, so measured against production the release expands every holder's authority by nine capabilities, which needs the owner's authorization.

The comment also records the WP3-02 engine difference and the rules above.

### 3. Equivalence proof (before the helper was deleted)

Both runs used commit `f02031b`, at which the helper still existed. They ran in the scratch worktree against the dev test databases, on MariaDB and PostgreSQL.

**E1: the committed tests describe the helper exactly.** I replaced the body of `runGuardianRoleKeyMigration()` in `GuardianRoleKeyMigrationTest` with a call to `RenameGuardianRoleAssignments` and ran the file: **23 passed (131 assertions) on MariaDB and 23 passed (139 assertions) on PostgreSQL**, the same counts as against the migration. The edit was then reverted.

**E2: a differential probe, fixture by fixture.**
- A scratch test (`RenameEquivalenceProbeTest`, untracked; a copy is kept in the session scratchpad) planted each fixture with fixed ids.
- It ran the helper inside a transaction, recording the outcome (exception class and message, or none), every row afterwards, and the query log (SQL with bindings), then rolled back.
- It then ran the migration file from the same state and recorded the same three things.
- It asserted all three identical. **24 of 24 fixtures matched on each engine.**

| Fixture | MariaDB (both subjects) | PostgreSQL (both subjects) |
| --- | --- | --- |
| up: empty; one `guardian`; five `guardian`; `guardian` + administrator on one Person; `guardian` + each other catalog role | completes | completes |
| up: exact `guardian-full` on the same Person, on another Person, or alone | `RuntimeException` (refusal) | `RuntimeException` (refusal) |
| up: same Person, `guardian` + `Guardian-Full` / `GUARDIAN-FULL` / `guardian-full ` / `guardián-full` | `UniqueConstraintViolationException` (1062) | completes |
| up: another Person holds one of those four variants | completes | completes |
| up: legacy variants `Guardian`, `GUARDIAN`, `guardian `, `guardián` on separate Persons | completes; only the exact key renamed | completes; only the exact key renamed |
| up: A, B (+ `Guardian-Full`), C all hold `guardian` | `UniqueConstraintViolationException` | completes |
| down: empty; `guardian-full` rows + administrator; legacy variant on another Person | completes | completes |
| down: a `guardian` row exists | `RuntimeException` (refusal) | `RuntimeException` (refusal) |
| down: same Person `guardian-full` + `Guardian`; and B (+ `GUARDIAN`) between two others | `UniqueConstraintViolationException` | completes |

**Observation (not a finding):** `down()` has the mirror image of WP3-02 on MariaDB. A legacy-key variant on the same Person blocks the development reversal, atomically. `down()` is development-only and unchanged, so I note it and did nothing.

### 4. Tests (`tests/Feature/Modules/Access/GuardianRoleKeyMigrationTest.php`)

The subject is now the migration file itself. `runGuardianRoleKeyMigration('up'|'down')` loads it with `require`, as the Migrator does, and calls the method. No copy of the algorithm exists in the tests.

**Kept, with every original assertion:**

| Test | Covers |
| --- | --- |
| renames and leaves every other assignment and the capability set | exact key; administrators; row count; no relationship; no security event; capabilities before and after |
| renames only an exact key; a wrongly cased row grants nothing | `Guardian` |
| refuses when a `guardian-full` assignment exists, and changes nothing | destination refusal |
| reverses in development, and refuses when a `guardian` row is there | `down()` |
| moves exactly the `guardian` rows, for every kind of holder, and `/me` follows | the seven kinds of holder (legacy, legacy + administrator, administrator, senior, initiate, participant, wrongly cased); whole-table snapshot; five table counts; capabilities by the real Authorizer; a session open across the rename and fresh sign-ins over HTTP (`/me`) |
| is not repeatable | second run refused, nothing changed |
| refuses on a `guardian-full` row held by the same Person or another (2) | refusal, whole-table snapshot |
| does nothing when no assignment exists | empty table |

**Added:**

| Test | Answers |
| --- | --- |
| renames a `guardian` key whoever holds it: no Account, invited, disabled | WP3-10's latent holders. All three are renamed; neither non-active Account is granted anything |
| leaves a variant of the legacy key alone, on both engines (`Guardian`, `GUARDIAN`, `guardian `, `guardián`) | exactness. On MariaDB the SQL returns the variant row and the PHP comparison rejects it |
| renames beside a variant of `guardian-full` held by **another** Person (4 variants) | variants on other Persons: no refusal and no collision on either engine; the variant grants nothing |
| on the **same** Person, a variant of `guardian-full` fails on MariaDB and is renamed beside on PostgreSQL (4 variants) | WP3-02, kept on purpose. MariaDB: `UniqueConstraintViolationException`, whole table unchanged. PostgreSQL: completes, the variant untouched, the bundle granted |
| is atomic on its own (WP3-03) | the transaction discriminator, below |

**The transaction discriminator.**
- Three Persons hold `guardian`. B, the middle one, also holds `Guardian-Full`.
- The rows have fixed ids (`01jwp3a…0001` to `…0004`), and the test asserts their order first, so the visiting order is set by the test, not by generated ids.
- The failing row sits between two that rename cleanly. Without the transaction, a row is left renamed whichever direction the loop walks.
- MariaDB must throw `UniqueConstraintViolationException` with the whole table unchanged.
- PostgreSQL has no collision, so there the test pins its own answer: the three exact keys renamed and the variant untouched. Per the triage, it cannot and does not prove MariaDB atomicity on PostgreSQL.

**Engine expectations.** `roleKeyUniqueIndexFoldsVariants()` names each engine's answer explicitly (`mariadb`/`mysql` true, `pgsql` false). An unknown engine throws rather than silently choosing a branch.

**Results** (`./flow test backend [--pgsql] -- tests/Feature/Modules/Access/GuardianRoleKeyMigrationTest.php`): **23 passed (131 assertions) on MariaDB; 23 passed (139 assertions) on PostgreSQL.**

### 5. Mutation evidence: the migration

Each mutant was planted in the scratch worktree at `d60b383`, the file run on both engines, and the migration restored with `git checkout` (`git status` clean after each).

| Mutant | MariaDB | PostgreSQL | Notes |
| --- | --- | --- | --- |
| **MT** — transaction removed (`$database->transaction(` → `call_user_func(`, in `up()`) | **killed**: 1 failed, the atomicity test. Row `01jwp3a…0001` (Person A) shows `'guardian'` → `'guardian-full'`: the partial rename | survives (23 passed) | Expected and accepted by the triage: PostgreSQL wraps the migration and sees no collision |
| **MC** — case-insensitive comparison (`strtolower(…) === strtolower(…)`) | **killed**: 9 failed (wrongly cased row, every kind of holder, legacy variants, other- and same-Person variants, atomicity) | survives | PostgreSQL's `where` is already exact, so the PHP comparison never sees a variant there |
| **MC′** — PHP comparison dropped (`if (true)`) | **killed**: 15 failed | survives | as MC |
| **MR** — destination refusal removed (`up()`) | **killed**: 4 failed (`Exception "RuntimeException" not thrown`) | **killed**: 4 failed | refusal, repeat run, same and other Person |
| **MR-down** — refusal removed in `down()` | **killed**: 1 failed | **killed**: 1 failed | |
| **ML** — `whereRaw('lower(role_key) = ?')` with the PHP comparison dropped | **killed**: 15 failed | **killed**: 9 failed | the audit's "`whereRaw(lower())` filter" |
| **ML′** — `whereRaw('lower(role_key) = ?')`, PHP comparison kept | survives | survives | **Equivalent mutant.** The PHP comparison still decides, so behaviour is identical. Recorded, not a gap |

### 6. The deployed-migration guard (`tests/Architecture/DeployedMigrationsTest.php`)

**What it holds.** `deployedMigrationReleases()` maps each confirmed production release to `[file name => SHA-256 of the file's bytes]`. Today it holds one block: `v0.3.0`, with its 15 migrations. Each hash was computed with `git show v0.3.0:apps/platform/database/migrations/<name> | sha256sum`, from the annotated tag, and then compared with the working tree: all 15 are equal, and so are their blob ids.

**What it requires**, through the pure function `deployedMigrationViolations($directory, $releases)`:
- every pinned file exists ("…was deployed in v0.3.0 and is missing");
- every pinned file's bytes match ("…has changed (sha256 …, pinned …). Restore it and make the change in a new migration");
- every migration file that is not pinned sorts after the last deployed one ("…is not deployed but sorts before 2026_09_23_000002_create_membership_grants_table.php…");
- the manifest itself is sane: no name pinned twice, and each release's files sort after the previous release's.

Files are found and ordered as Laravel's Migrator does (`*_*.php`, by name). The guard reads bytes only: it never requires a migration and needs no database. `.gitattributes` (`* text=auto eol=lf`) makes the bytes the same on every platform.

**Extending it** (in the file's docblock and in `tests/Architecture/README.md`):
- Only after `release:show` on the host confirms a release is deployed, add one block for that release.
- The block lists only the migrations that release added, each hashed from the release's tag.
- Existing blocks never change. A reviewer checks a new block against the tag the same way.

**Committed positive controls** (hand-built temporary directories, 7 cases): an untouched history plus a later migration passes; content and whitespace-only edits fail; a deletion fails; a migration dated before all, or between two, deployed ones fails; a two-release manifest passes, and one whose releases are out of order is refused.

**Mutations on the real tree** (scratch worktree at `0438a14`, `--filter='keeps every migration'`, restored with `git reset --hard` / `git clean` and checked clean after each):

| # | Planted change | Result | Message |
| --- | --- | --- | --- |
| D1 | `restrictOnDelete()` → `cascadeOnDelete()` in `2026_09_20_000003_create_role_assignments_table.php` | killed | `…create_role_assignments_table.php was deployed in v0.3.0 and has changed (sha256 0b159ab2…, pinned 8b63c71b…)` |
| D2 | whitespace only: one extra trailing newline in `create_people_table` | killed | `…create_people_table.php … has changed (sha256 2cf5a56b…, pinned 494f3b77…)` |
| D2b | whitespace only: CRLF line endings in `create_account_totp_factors_table` | killed | `… has changed (sha256 d8f5ba66…, pinned 0e88d504…)` |
| D3 | `2026_09_21_000002_create_account_recovery_codes_table.php` deleted | killed | `… was deployed in v0.3.0 and is missing. A deployed migration is never deleted` |
| D4 | `2026_09_19_999999_x.php` added (among the deployed ones) | killed | `2026_09_19_999999_x.php is not deployed but sorts before 2026_09_23_000002_create_membership_grants_table.php, the last migration deployed (v0.3.0)…` |
| D5a | `0001_01_01_000000_x.php` added (before all of them), no file altered | killed | as D4 |
| D5b | `2026_09_23_000002_a.php` added (just before the last deployed one), no file altered | killed | as D4 |
| D5c | a pinned file renamed to a later date (`2026_10_20_000001_add_channel_…`) | killed | `2026_09_22_000001_add_channel_to_account_invitations.php … is missing` |
| P1 | positive control: `2026_10_17_000001_x.php` added | **passes** | — |

The full hashes are in the test file. Nothing planted was committed.

### 7. Documentation corrections

- **[ADR 0038](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md), M6:**
  - the "cannot collide" sentence is replaced with a dated correction (2026-10-17, WP3-02). Only the exact key cannot collide. A case, trailing-space or accent variant of `guardian-full` on the same Person fails the rename atomically on MariaDB and is renamed beside on PostgreSQL. The owner kept those semantics, and the pre-flight is where to look for such keys;
  - a bullet records that the migration now holds its logic (D8), and why the transaction and the exact comparison stay.
- **ADR 0038, T3:** "identical on both engines" now names that one corrupt-data exception.
- **The migration's comment:** as in §2.
- **`tests/Architecture/README.md`:** lists the new guard.
- **Not changed:**
  - the G6 contract's planning facts;
  - the release-readiness runbook (its count query and checklist are WP3c's);
  - every runbook and data-ownership document (WP3c);
  - the debt register, until the closeout added G6-D20 (§9).

### 8. The production pre-flight: recorded need, not built

The triage approves preparing a read-only production pre-flight and documenting how to read it. That belongs to the separately authorized WP3c release documentation. WP3a records only what the audit and these tests show it must report:

- **Exact stored keys**, grouped by their bytes (for example `HEX(role_key)`), not by a collation comparison. On MariaDB the [release-readiness](../../runbooks/g10-release-readiness.md) count (`where('role_key','guardian')->count()`, step 2 of its host steps) also counts `Guardian`, `guardian ` and `guardián`, which the migration does not rename (WP3-10). For the same reason its *after* count stays above zero when such a variant exists, even though the migration did exactly what it should. WP3c's runbook work should say how to read both counts.
- **Variants** of `guardian` and of `guardian-full`: keys equal to either under the column's collation but not byte-identical.
- **The collision condition** this record pins: any Person holding exact `guardian` together with a collation-equal variant of `guardian-full`. On MariaDB the migration stops there (#12, with #1–#11 already committed). Also any exact `guardian-full` row, which the migration refuses.
- **Holders by Account status:** active, invited and disabled Accounts, and Persons with no Account. All are renamed; each gains the bundle when, or while, active.
- **The collations** of the v0.3.0 tables and of `people.id` and `role_assignments.role_key` against the connection's setting in `shared/.env` (WP3-12). A local rehearsal cannot establish them.
- **Existing sessions** pick up the nine capabilities when the application comes back up, with no new sign-in. The release checklist must say so (triage, WP3-10). The test "moves exactly the `guardian` rows…" shows it for a session open across the rename.

Nothing was run against production or staging.

### 9. Open items and observations

- **WP3-09 (deferred by the triage).** A hard refusal in `./flow release build` for a modified or removed deployed migration is not built. The guard above runs in the normal gates instead. The deferral is registered as [G6-D20](debt-register.md), with its rationale and revisit trigger, at the owner's direction.
- **`down()` mirror collision** (§3): development-only; nothing done.
- **`--database`:** preserved by construction (§1, §2) and unchanged from the helper. No test pins it: inside RefreshDatabase's transaction, a second connection cannot see the fixture rows. WP3b's separate upgrade database is the natural place to exercise `migrate --database=`.
- **Patch-version difference:** dev and CI MariaDB is 10.11.19 and production is 10.11.18 (G6-D09). The collision results agree with the audit's 10.11.18 measurements.

### 10. Validation

| Check | Result |
| --- | --- |
| `GuardianRoleKeyMigrationTest`, MariaDB / PostgreSQL | 23 passed (131) / 23 passed (139) |
| `tests/Feature/Modules/Access`, `tests/Architecture`, `tests/Unit/Modules/Access` after deleting the helper, MariaDB / PostgreSQL | 902 passed (6,191) / 902 passed (6,199) |
| `DeployedMigrationsTest` | 9 passed (16 assertions) |
| Pint, Larastan (level max) on every changed PHP file | clean |
| `./flow check all --pgsql` at `e4c265e` | passed, exit 0 (§11) |
| `./flow check repo` on the closeout tree | passed, exit 0 |
| `git diff --check a7af9a0..HEAD` | clean, after the triage's hard line breaks were reformatted (header note above) |

No timeout, retry, worker count or CI setting was changed. No Console or e2e code was touched, so no browser run was needed.

### 11. Full gate

`./flow check all --pgsql`, run once, unchanged, in the shared checkout on the executable tree **`e4c265ebe2f882f72f9b585e5c33807c1ebcb792`**. The tree was clean apart from this untracked record, which no check reads.

- **Started** 2026-10-17T10:02:00-07:00. **Ended** 10:36:28 (**34 min 28 s**).
- **Exit status 0.** The log ends `✓ All checks passed`. No step failed and nothing was re-run.
- **Host:** load about 2.0 at the start and 2.9 near the end. Another project (`intechral-client-portal`) had a job running during the frontend stage.

| Step | Result |
| --- | --- |
| Compose configuration, shellcheck, `./flow` CLI behaviour, production public surface (contract and real Apache), `./flow release` tooling, actionlint, WordPress companion syntax and authority, Composer manifest | passed |
| Pint | passed |
| Larastan, level max | passed |
| Pest, MariaDB (architecture included) | **3,447 passed** (26,875 assertions), 711.89 s |
| Pest, PostgreSQL | **3,447 passed** (26,880 assertions), 815.63 s |
| TypeScript (strict), ESLint, Prettier | passed |
| Vitest | **89 files, 2,276 tests passed**, 164.66 s |
| Production build (Vite + Tailwind), build output verification | passed |

The backend count is WP2's acceptance count (3,424 on each engine) plus the 23 tests this package adds: 14 in `GuardianRoleKeyMigrationTest` and 9 in `DeployedMigrationsTest`. The Vitest count is unchanged (2,276), as expected with no Console change.

**After the gate.** The closeout changed only `docs/`: this record, `wp3-triage.md` and `debt-register.md`. `git diff --name-only e4c265e..HEAD` lists no file outside `docs/`, so the executable tree is the one the gate passed. `./flow check repo` was re-run on the final documentation tree: exit 0, `✓ All checks passed`.

### 12. For the independent verification

Each question from the assignment, with where its evidence is:

1. **Same behaviour before and after inlining?** §3 (E1, E2), and the commit split: `f02031b` has both subjects, and `d60b383` deletes the helper.
2. **MariaDB atomicity for a failed rename?** §4 (the discriminator) and §5 MT.
3. **Case and collation edge cases preserved?** §3 (E2 rows), §4 (variant tests), §5 MC, MC′, ML and ML′.
4. **Edits to v0.3.0 migrations detected?** §6, D1–D3 and D5c.
5. **Backdated migrations rejected?** §6, D4, D5a and D5b; positive control P1.
6. **Any unintended authorization or schema change?**
   - No file under `app/` changed except the deletion.
   - No migration other than the rename changed.
   - The role catalog is untouched.
   - The 15 deployed migrations are byte-identical to the tag.
7. **All gates green?** §10, §11.

### 13. Commits

| Commit | Content |
| --- | --- |
| `f92e610` | the audit and the triage, as given |
| `f02031b` | the inlined migration; tests now run the migration file; the new variant and atomicity tests |
| `d60b383` | `RenameGuardianRoleAssignments` deleted |
| `0438a14` | `DeployedMigrationsTest` and its README entry |
| `e4c265e` | ADR 0038 M6 and T3 corrections |
| `21f45ae` | this record (gate outcome included); the `wp3-triage.md` hard-break reformat; debt row G6-D20 |
| *verification commit* | `wp3a-verification.md`; §14 and the WP3b/WP3c dependencies below |

### 14. Independent verification

A separate Opus 5.5 session, which did not write this code, verified WP3a. Its report is [`wp3a-verification.md`](wp3a-verification.md), committed from its heading to its verdict; the one-line "Written for:" note addressed to the owner is omitted, as for the audits.

- **Subject:** implementation HEAD `21f45ae`. The executable tree is that of the full-gate checkpoint `e4c265e` (§11); `21f45ae` changed only `docs/audits/g6/`.
- **Engines:** disposable `mariadb:10.11.18`, production's patch level, and `postgres:16-alpine`, on a private network. `GuardianRoleKeyMigrationTest` and `DeployedMigrationsTest`: 32 passed on each (147 and 155 assertions).
- **Migration equivalence:** a mechanical diff found the migration's methods identical to the deleted helper's apart from their signatures. The reviewer found the tests run the real file, and the E2 probe genuine.
- **MariaDB atomicity:** on 10.11.18 the atomicity test's collision occurs, the table is left unchanged, and removing the transaction fails it with a partial rename. The test cannot pass through the refusal instead.
- **Collation and mutants:** every case in §4 and every mutant in §5 reproduced with the same result. ML′ was confirmed equivalent.
- **Immutability guard:** all 15 pins equal `git show v0.3.0:<path> | sha256sum`. An edit, a back-dated migration, a deletion and a rename were killed; a correctly dated migration passed.
- **`--database`:** proven directly. `migrate --database=second` with the default connection on another database renamed and logged only on `second`. The gap §9 deferred to WP3b is closed.
- **Gate evidence:** checked against `gate.log`, consistent; not re-run.
- **Verdict:** **G6 WP3a VERIFICATION: SAFE TO PREPARE PR.**

**I1 — INFORMATIONAL: the production pre-flight is described as if it exists.** ADR 0038 M6 and the migration's comment speak of "the release's read-only pre-flight", which is approved (WP3-10) but not written. **Owner disposition: accepted for WP3a.** The ADR and the comment are not changed for it. **WP3c must deliver the production pre-flight before release**, which keeps both sentences true.

## WP3b — Historical upgrade tests

- **Base:** `main` at `95bcd841ebb144ba5b4bb3c333ab90286064f8ee` (WP3a, PR #13; post-merge CI run `39098496073`, taken from the assignment and not re-queried). **Branch:** `feat/g6-wp3b-historical-upgrade-tests`, local only. No push, pull request, merge or deployment. Production stays on v0.3.0 (`2f385b79a7b83323c6f15a6685ee610ccd85b5bf`).
- **Status:** implemented and validated; **awaiting independent verification**. Everything below is the implementer's evidence.
- **Who implemented it:** Claude Sonnet 5.5, the model the assignment named. Verification should be a separate session.
- **Scope kept:** WP3b only. No change to any migration, the role catalog, the capability bundles, the rename's refusal policy, CI settings or required checks, `app/`, or the audit, triage or WP3a verification records. No production or staging access, no restore rehearsal, no WP3c work.
- **Executable commit of the gate:** `e6a45e7e888255b8577b3f73dd64cb6d7d7d6beb`. The commits after it change only `docs/audits/g6/`.

### 1. What was built

| File | Role |
| --- | --- |
| `scripts/lib/common.sh` | `ensure_mariadb_test_db` and `ensure_postgres_test_db` also create `flowlife_upgrade_test` (MariaDB: with the same grant). `./flow test`, `./flow check`, `./flow setup` and CI therefore get it from the functions they already call. No new service. |
| `tests/Support/UpgradeDatabase.php` | The only door to that database: builds the connection, validates it, cleans it, runs the real migration runner on it, reads it back. |
| `tests/Support/HistoricalFixture.php` | The v0.3.0 rows, as literal inserts. Also the literal lists of the 14 pending migrations, the 18 new tables, and the ten Guardian capabilities. |
| `tests/Support/HistoricalSnapshot.php`, `HistoricalRun.php` | Row and structure snapshots of the 16 tables, the expected-after construction, and the comparison. |
| `tests/Upgrade/HistoricalUpgradeTest.php` | The successful upgrade, run once for the file; 29 tests. |
| `tests/Upgrade/RefusedUpgradeTest.php` | The refused upgrades, each on its own historical database; 3 tests. |
| `tests/Upgrade/UpgradeDatabaseGuardTest.php` | The safeguards; 12 tests. |
| `phpunit.xml`, `tests/Pest.php` | An `Upgrade` suite, extended with `TestCase` and **not** `RefreshDatabase`, as `Concurrency` is. |
| `docs/development/testing.md` | A paragraph on the suite and its database. |

### 2. Isolation and safeguards

`flowlife_test` is unchanged and nothing else uses `flowlife_upgrade_test`. Every statement that creates or drops anything goes through a named connection, `upgrade`, built in `UpgradeDatabase::connect()`, which on **every call**:

- requires the testing environment;
- requires the inherited default connection to be MariaDB or PostgreSQL on a `*_test` database that is not the upgrade database (the rule `tests/TestCase.php` already applies), so a shell carrying a development or production `.env` is refused;
- requires the host to be `mariadb`, `postgres`, `localhost`, `127.0.0.1` or `::1`;
- fixes the database to the constant `flowlife_upgrade_test`, and **blanks the inherited `url` and `unix_socket`**, which could otherwise name another database or server;
- asks the server which database it is on (`select database()` / `select current_database()`) and refuses a different answer, before any DDL.

`clean()` runs before the first build and `afterAll` drops the tables again (`cleanVerified()`, which opens a connection from the settings that passed these checks and checks the name once more); a refused-upgrade test also cleans after each test. After every run in this package both databases were checked empty (`show tables`: none; `\dt`: none). The default connection is switched to the upgrade database only inside `useAsDefault()` / `restoreDefault()`, so the application's own code (the Authorizer, the Artisan commands) can read the upgraded schema.

`UpgradeDatabaseGuardTest` damages each safeguard (a development, production-looking, suffixed or upgrade-named inherited database; three hosts; a production environment; a URL and a socket that name another database; a refused `useAsDefault`) and requires the refusal before a connection to the upgrade database exists. Planting the URL mutant (leaving `url` and `unix_socket` unblanked) fails the URL test on both engines.

**Two things measured on the way, kept in the code's comments:**
- `Schema::getTables()` on MariaDB lists the tables of **every database the user can see**; `flowlife_test`'s appeared in the "upgrade" list until it was asked by schema.
- The backend suite is **one PHP process** and MariaDB allows 151 connections. Earlier tests' connections are freed only when the cycle collector runs. The first full gate (below) reached "Too many connections" at the first upgrade test and in the Apprentice suite after it. Each test here now purges its connection, disconnects and collects, and each file collects once before it starts; the full MariaDB runs then peaked at 131–132 sampled connections (sampled every 10 s).

### 3. Historical schema

`migrateHistorical()` runs `migrate --database=upgrade --realpath` with exactly the 15 pinned file paths. `historicalPaths()` refuses unless those are the **first 15** migrations in the directory in the Migrator's order. `DeployedMigrationsTest` pins their bytes against the `v0.3.0` tag. The test asserts:

- the 15 migrations are recorded, all in batch 1;
- exactly the 16 expected tables exist (the 15 and `migrations`), and nothing later.

### 4. Fixture matrix (literal rows, fixed lower-case ULIDs, no application code)

Thirteen Persons; eleven Accounts (nine active, one invited, one disabled); twelve role assignments:

| Person | Account | Stored role keys |
| --- | --- | --- |
| 1 Ada | active | `platform_administrator` |
| 2 Gus | active | `guardian`, `platform_administrator` |
| 3 Guy | active | `guardian` |
| 4 Ines | invited | `guardian` |
| 5 Dora | disabled | `guardian` |
| 6 Nora | none | `guardian` |
| 7–10 | active | `Guardian`, `GUARDIAN`, `guardian ` (trailing space), `guardián`, one Person each |
| 11 | active | `Guardian-Full` (a destination-key variant, another Person) |
| 12 Mina | active | none |
| 13 Walt | none | none |

Also: four invitations (pending, lapsed, two accepted; both channels); two password-reset tokens; security-generation values 1–4; three raw `sessions` rows (two signed in, one guest; non-ASCII content); three TOTP factors (two enrolled, one used and one never; one still pending); five recovery-code digests, one used; five membership grants (open-ended, bounded to 2099, expired, revoked, two sources); eight security events with actors, subjects, IPs and JSON contexts. `cache`, `jobs` and the other queue tables are empty and are snapshotted as empty.

Created at run time and never committed: password hashes, the TOTP secret and its ciphertext (the framework encrypter, the test key), recovery-code and invitation digests. Each variant is on its **own** Person because MariaDB's unique key on `(person_id, role_key)` folds case, trailing space and accent; on PostgreSQL it does not.

*Interpretation:* "a sole platform administrator" and "another administrator" cannot both hold literally, so Ada is administrator only and Gus is administrator and Guardian. Only keys v0.3.0 could write are used, plus the variants a VARCHAR can hold ([audit §G](wp3-audit.md#g-wp3b-readiness)).

### 5. Snapshot assertions

`HistoricalSnapshot::take()` reads every row of the 16 tables as stored, ordered by primary key, before and after, on the same engine; nothing is normalised. `expected()` builds the "before" with **only** the allowed changes, each named by the test: the five role assignments listed in `RENAMED` (written out; a separate test checks the list against the rows whose key is `=== 'guardian'` in PHP) become `guardian-full`, and the 14 migrations are appended to `migrations` (batch 2). `differences()` requires the "after" to equal that, row for row and column for column. Result: no difference, on both engines. Also asserted:

- the 14 tables other than `role_assignments` and `migrations` are identical, one by one, so a failure names the table; `sessions` also compared by SHA-256 of id and payload;
- for the roles, a literal map of all twelve assignment ids to their key after the upgrade, and the Person, granter and timestamp of each unchanged;
- TOTP and recovery-code tables unchanged, the stored secret of Account 1 **decrypts with the application's own cipher to the secret enrolled**, the pending enrolment still pending, the used recovery code still the one used;
- the historical tables' columns, indexes and foreign keys identical before and after, the six foreign keys and the two named `role_assignments` indexes listed literally.

### 6. Post-upgrade schema

- `Upgrade::pendingNames()` equals a literal list of the **14** migrations; the recorded set is the 15 plus those 14 = **29**, batches 1 and 2.
- The 18 new tables are listed literally (34 tables in all) and every one has 0 rows. `person_relationships`, `sourced_role_grants` and `contact_profiles` each have a foreign key to `people`.
- Adding a migration later fails these assertions until it is listed in `HistoricalFixture::UPGRADE_MIGRATIONS` and `NEW_TABLES`. That is intended: it is the prompt to check the new migration against historical data.

### 7. Capability matrix and stored roles vs authority

`GUARDIAN_BUNDLE` is the runbook's ten capabilities, written out and **not** derived from `Role::GuardianFull->capabilities()` or `organizationalBundle()`. Each Account's authority is what the real `Authorizer` decides on the upgraded database:

| Account | Stored key afterwards | Authority |
| --- | --- | --- |
| Guy (active) | `guardian-full` | exactly the ten |
| Ada, Gus (active admins; Gus also Guardian) | `platform_administrator` (+ `guardian-full`) | the whole catalog (also 18 literal ids; includes `guardians.manage`, which the bundle lacks) |
| Ines (invited), Dora (disabled) | `guardian-full` | **nothing** |
| Nora (no Account) | `guardian-full` | nothing; no Account, no sessions |
| the five variants (active) | unchanged | **nothing** |
| Mina (no role) | none | nothing |

No `person_relationships`, `sourced_role_grants` or membership row is created, and no security event is written (the 8 events are identical).

### 8. Diagnostics

- `relationships:check` run for real: exit 0, "Relationships are consistent with their definitions."
- `relationships:guardian-adoption-report` run for real: exit 0. "Console access without a Guardian relationship" lists **exactly** Ada, Gus, Guy, Ines, Dora and Nora, in that order: the invited, disabled and Account-less holders are present, as the audit predicted. The Account and console-access sections say `none`, and the closing check is consistent. The report writes nothing: every row of every table is identical after it.

### 9. Refused upgrades (`RefusedUpgradeTest`)

Each builds its own v0.3.0 database, loads the fixture and plants an extra assignment.

1. **An exact `guardian-full` on another Person.** The runner throws the migration's `RuntimeException` ("Refusing to rename role key guardian to guardian-full: a guardian-full assignment already exists."). Recorded: the 15 and the **11** that ran before the rename; **not** the rename, and not the two after it (26 in all, batches 1 and 2). All five `guardian` rows are still `guardian`, no historical row changed (the only difference is the 11 records), `person_relationships` exists, `sourced_role_grants` and the `contact_methods` provenance column do not. **Same on both engines.**
2. **Re-run after the offending row is removed:** it completes, records the remaining three in batch 3 (29 in all), renames exactly the five, and nothing earlier runs twice.
3. **WP3-02, the engine difference.** Nora holds `guardian` and, planted, `Guardian-Full`. The migration's own check passes (not the exact key). **MariaDB:** the unique key rejects the rename (`UniqueConstraintViolationException`), every row is as it was, the rename is not recorded (26 recorded), `sourced_role_grants` does not exist. **PostgreSQL:** no collision; the upgrade completes (29), and Nora holds `Guardian-Full` and `guardian-full`. Her row is the **last** exact `guardian` the rename visits, so four are renamed before the failure on MariaDB, which is what makes a missing transaction visible there.

The refusal policy is untouched.

### 10. Mutation evidence

Planted in a scratch detached worktree of the branch (the shared checkout was never edited), the whole `tests/Upgrade` directory run on both engines, restored with `git checkout` and checked clean after each. The matrix was measured at `db24a36`; the commits after it changed only connection release and cleanup in the support class and the files' hooks.

| Mutant | MariaDB | PostgreSQL |
| --- | --- | --- |
| **A1** exactness check dropped (`if (true)`) | **killed**, 31 failed: the refusal fires on the `Guardian-Full` variant, so the whole upgrade is refused | survives (44 passed): PostgreSQL's SQL is already exact |
| **A2** PHP check accepts a case-insensitive `guardian` | **killed**, 7 failed: `Guardian` and `GUARDIAN` are renamed and gain the bundle | survives |
| **A3** SQL `lower(role_key)` for `guardian`, PHP check bypassed | **killed**, 9 failed | **killed**, 8 failed |
| **B1** `guardians.view` removed from the Guardian bundle | **killed**, 1 failed (the literal ten) | **killed**, 1 failed |
| **B2** `guardians.manage` added to the bundle | **killed**, 1 failed | **killed**, 1 failed |
| **D1** a later migration deletes one security event | **killed**, 3 failed | **killed**, 4 failed |
| **D2** the rename also moves `granted_at` | **killed**, 3 failed | **killed**, 4 failed |
| **MR** refusal removed from `up()` | **killed**, 2 failed | **killed**, 2 failed |
| **MT** transaction removed from `up()` | **killed**, 1 failed (the WP3-02 case: a partial rename) | survives: PostgreSQL wraps the migration itself |
| **F1** the fixture loses a security event | **killed**, 3 failed (the fixture's own count) | **killed**, 3 failed |
| **G** `url` / `unix_socket` not blanked | **killed**, 1 failed | **killed**, 1 failed |

A1–A3 are the **case-handling regression** (A) and B1–B2 the **capability drift** (B) the assignment named: A is caught on MariaDB, as predicted, and only an SQL-level mutant (A3) reaches PostgreSQL; B is caught on both. The two engine-specific survivors (A1, A2 and MT on PostgreSQL) are the audit's stated expectation, not a gap.

**Negative control for a missing historical row:** eight cases damage the real upgraded snapshot (a Person missing, a security event missing, a variant renamed as if exact, an exact key not renamed, a recovery-code digest changed, a timestamp moved, a row appearing, a migration unrecorded) and the comparison must name each. F1 shows the fixture itself is pinned: it cannot lose a row unnoticed.

### 11. Results and gates

- **Focused:** `tests/Upgrade`: **44 passed (261 assertions) on MariaDB 10.11.19 and on PostgreSQL 16.15**, exit 0, both databases empty afterwards.
- **`./flow check all --pgsql` at `e6a45e7`: all checks passed, exit 0**, no skipped test, no changed timeout, no retry. Pint and Larastan (level max, tests included) clean; **3,491 passed on each engine** (27,136 assertions on MariaDB, 27,141 on PostgreSQL), 44 more than WP3a's 3,447; Vitest unchanged; the whole gate took 1,934 s.
- **The first gate run failed** (at the executable state `60730e3`): 24 MariaDB tests, `SQLSTATE[HY000] [1040] Too many connections`, in the upgrade tests and the Apprentice suite after them; PostgreSQL passed. The cause is §2's connection finding. A second attempt at the fix then exited 2 with every test passing: releasing the connection left the file's `afterAll` unable to drop the tables. Both are fixed (`724a32c`, `e6a45e7`) and the gate above is the third run.
- `git diff --check origin/main..HEAD`: clean. `git diff --stat`: 11 files, all under `scripts/lib/`, `apps/platform/tests/`, `apps/platform/phpunit.xml` and `docs/`. No change under `app/`, `database/`, `config/` or `.github/`.
- **Runtime.** Summed test time of the `Upgrade` suite inside the full run: **13 s on MariaDB, 8 s on PostgreSQL**; standalone (including boot) it is 34–38 s and 11–16 s. The backend runs took 685 s and 708 s, against WP3a's 712 s and 816 s: within run-to-run noise, so no increase is measurable. WP3a's CI job took 20 m 19 s against the 25-minute limit and the 22-minute review threshold; this package has not been measured in CI. **No CI setting was changed.**
- No browser E2E was run: nothing in the Console or the E2E implementation changed.

### 12. Commits

| Commit | Content |
| --- | --- |
| `cad6a3b` | the dedicated database, `UpgradeDatabase`, the guard test, `phpunit.xml`, `Pest.php`, `common.sh` |
| `813bc8d` | fixture, snapshot, the successful and refused upgrade tests |
| `db24a36` | the same-Person case moved to the last exact row |
| `60730e3` | `docs/development/testing.md` |
| `724a32c` | connection release |
| `e6a45e7` | `afterAll` cleanup from the verified settings (**the gate's executable commit**) |
| `ec86778`, `27bb2e6` | this section; WP3a's status |
| `5484e96` | V1, L1, L2 remediation (§14); its gate is the one in §14 |

### 13. Limitations and what WP3c must still do

- This is the **development** behaviour on the **current framework** running the real v0.3.0 migration files. It does not run the v0.3.0 application, and it does not authorize the nine-capability expansion for production.
- No backup, restore, in-place rollback or partial-failure recovery on a host is exercised. Scenario 9.1 shows the *database* is left consistent and re-runnable; it does not show that a production host's recovery procedure works (WP3-01 and the rest of WP3c).
- Run on MariaDB 10.11.19 (production: 10.11.18, [G6-D09](debt-register.md)) and PostgreSQL 16.15.
- The upgrade runs once per file and the tests read different parts of it; a test that mutates the upgraded database would affect those after it. None does.
- The adoption-report assertion pins the report's wording of section headings. A change to the report must update the test, which is the intent.
- Not measured in GitHub CI; see §11.

### 14. Remediation of the independent verification (V1, L1, L2)

The independent Opus 5.5 verification returned REMEDIATION REQUIRED for one MEDIUM and two LOW findings in the test-support guard; everything else passed. The fix is `5484e96`, on top of `27bb2e6` (the eight earlier commits are unchanged). Test-support code only: nothing under `app/`, `database/` or `config/`.

- **V1 (MEDIUM): read/write routing bypassed the guard.** Laravel lets `read`, `write` and `sticky` override any top-level key, `database` included. A connection could pass the host and name checks, the server check could ask the read side, and the DDL could go to a write side on another database. `UpgradeDatabase::connect()` now refuses any `read`, `write` or `sticky` key in the inherited connection, **by presence** (`array_key_exists`), so `[]`, `null` and `false` are refused too, before a connection to the upgrade database exists. `cleanVerified()` refuses it again.
- **L2: `cleanVerified()` did not ask the server.** It now builds its connection from the verified settings, refuses routing and a wrong name, calls the same `assertServerDatabase()` and then drops the tables **on that same connection**, disconnecting in a `finally`.
- **L1: the server check was not tested.** `assertServerDatabase()` is now one public method, used by both paths. It asks `selectFromWriteConnection`, the side that takes the DDL. Guard tests build a genuine mismatch with SELECTs only: a connection whose read side is the upgrade database (a read-side question answers `flowlife_upgrade_test`) and whose write side is `flowlife_test` (refused, "The server reports database [flowlife_test]"). Another test refuses the default connection and accepts the upgrade one.

**Tests:** the guard file grows from 12 to 26 (14 new: 9 routing cases, 1 write-side, 1 accept/refuse, 3 cleanup refusals). `tests/Upgrade`: **58 passed (344 assertions) on MariaDB 10.11.19 and on PostgreSQL 16.15**; both databases empty afterwards.

**Mutations** (scratch worktree at `5484e96`, `tests/Upgrade` run on each engine, restored and checked clean after each):

| Mutant | MariaDB | PostgreSQL |
| --- | --- | --- |
| S1 server check disabled | killed, 2 failed | killed, 2 failed |
| S2 server check asks the read side | killed, 1 failed | killed, 1 failed |
| G `url`/`unix_socket` not blanked | killed, 1 failed | killed, 1 failed |
| R1 `refuseRouting` disabled | killed, 11 failed | killed, 11 failed |
| R2 routing refused by truthiness, not presence | killed, 7 failed | killed, 7 failed |
| R3 `refuseRouting` call removed from `connect()` | killed, 9 failed | killed, 9 failed |
| C2 `cleanVerified` skips the routing refusal | killed, 2 failed | killed, 2 failed |
| C3 `cleanVerified` skips its name check | killed, 1 failed | killed, 1 failed |
| **C1 `cleanVerified` skips its server check** | **survives** (58 passed) | **survives** (58 passed) |

**Residual risk (C1).** `cleanVerified()`'s own call to `assertServerDatabase()` is not covered by a test. Once routing and the name are refused, a configuration whose server answer differs from its name cannot be built from the verified settings, so no genuine mismatch can reach that call. It is defence in depth behind two checks, not a tested boundary, and the check it calls is covered through `connect()` (S1, S2). Closing it would need a mock of the connection factory, which the assignment asked not to build for a LOW finding.

**Gate:** `./flow check all --pgsql` at `5484e96`: all checks passed, exit 0, **3,505 backend tests on each engine** (3,491 + 14), 1,817 s; no skip, retry or timeout change; `git diff --check` clean.

## WP3c — pending, not yet authorized

Planned by the triage and not started. What WP3a and WP3b leave to it:

- **WP3c, before any release:**
  - **The read-only production pre-flight** (WP3-10, WP3-12; owner disposition of I1): exact keys by bytes, collation-equal variants of `guardian` and `guardian-full`, the same-Person collision condition, holders by Account status, and the collations of the v0.3.0 tables and columns (§8).
  - **The release-readiness count's MariaDB reading** (§8): the before and after counts match variants as well.
  - **WP3-01 (MEDIUM), a mandatory release prerequisite:** the corrected in-place restore, rehearsed.
  - The other WP3c findings as the triage rules them (WP3-04 to WP3-08, WP3-11, WP3-13).
  - What WP3b deliberately does not cover (see its section): the real v0.3.0 application, backup and restore, an in-place rollback, MySQL/MariaDB patch level 10.11.18, and a stopped upgrade's recovery on a host.
