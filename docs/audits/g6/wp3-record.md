# G6 WP3 — remediation record

- **Package:** WP3 Data integrity, upgrade and restore safety. Remediation of the [independent audit](wp3-audit.md) as ruled in the [owner's triage](wp3-triage.md). The triage authorizes the three work units separately: **WP3a** is recorded below. **WP3b and WP3c are planned and not yet authorized**; their sections are left for that work.
- **Audit and triage files:** the audit is committed as `wp3-audit.md` from its heading (`# G6 WP3 — Data integrity…`) to its closing verdict line, unedited. The one-line "Written for:" hand-over note that preceded it was addressed to the owner, so it is not in the file (as for [WP2](wp2-record.md)). The owner's rulings are `wp3-triage.md`, verbatim in content. Its three header lines first ended in two spaces (Markdown hard line breaks), which `git diff --check` reports as trailing whitespace. At the owner's direction they now end in a backslash, CommonMark's other hard line break. The rendered text is the same and no word changed. (`wp2-triage.md`, already on `main`, keeps its two-space form.)

## WP3a — Migration freeze and immutability

- **Base:** `main` at `a7af9a0f25233244426daa2afadcaabbf32249b3` (PR #12, G6 WP2 squash-merged; post-merge CI run `39031098500`, taken from the assignment and not re-queried). **Branch:** `fix/g6-wp3a-migration-freeze`. Local only: not pushed, no PR, nothing merged or deployed. Production stays on v0.3.0.
- **Status:** implemented and validated locally. This record is the implementer's evidence. **It is not independent verification:** that is the focused Opus review the G6 contract (§5, "WP3a (D8)") requires.
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
| *closeout commit* | this record (gate outcome included); the `wp3-triage.md` hard-break reformat; debt row G6-D20 |
