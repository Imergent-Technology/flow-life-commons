# G6 WP3a — Independent verification of the migration freeze and the immutability guard

## A. Executive verdict

**WP3a meets its approved contract.** I found nothing at BLOCKER, HIGH, MEDIUM or LOW.

- **The rename's behaviour is preserved.** The method bodies in the migration match the deleted helper exactly; only the method signatures differ.
- **The decisive properties reproduce on production's MariaDB patch level (10.11.18) and on PostgreSQL:**
  - the rename is atomic, and removing its transaction is caught;
  - key matching is exact;
  - the destination refusal works;
  - the deployed-migration pin catches edits, deletions, renames and back-dated migrations.
- **I also proved directly that `migrate --database=` selects the right connection.** WP3a had deferred that proof to WP3b; it is now settled and needs no further work.
- **One informational wording note** (I1, below).

## B. Repository checkpoint

| Item | Verified |
| --- | --- |
| Baseline | `a7af9a0f25233244426daa2afadcaabbf32249b3`, equal to `origin/main` and the merge base |
| HEAD | `21f45ae30e44816033d064d8426861fc85c706b7` on `fix/g6-wp3a-migration-freeze` |
| Commits | Exactly six (`f92e610`, `f02031b`, `d60b383`, `0438a14`, `e4c265e`, `21f45ae`), with the content each message describes |
| Not pushed | No remote branch contains `21f45ae` |
| Shared checkout | Clean |
| Changed files over the baseline | 10: the migration, the deleted helper, 2 test files, the Architecture README, ADR 0038, and 4 files under `docs/audits/g6/` |
| Review worktree | Detached at `21f45ae` in the scratchpad, now removed |

## C. Migration equivalence

- **Mechanical diff.** I diffed the old helper's `up()`, `down()` and `idsWithExactKey()` against the migration's. The only differences are the method signatures (the helper took `ConnectionInterface $database`; the migration resolves `DB::connection()` itself) and blank lines. These are unchanged:
  - the SQL read;
  - the strict `===` filter;
  - the `orderBy('id')`;
  - the per-row `UPDATE`;
  - the explicit transaction in both directions;
  - the refusal checks and their messages.
- **No remaining consumers.** Nothing outside `docs/` references `RenameGuardianRoleAssignments`. The remaining mentions are historical planning facts and the committed audit.
- **The tests exercise the real file.** `runGuardianRoleKeyMigration()` `require`s the migration file fresh on every call. The tests contain no copy of the algorithm.
- **The E2 differential probe is genuine.** It runs the helper class and then the `require`d migration from the same rolled-back state, and compares outcome, final rows and SQL log. It reports 24/24 on both engines. Its fixture coverage includes the up and down refusals, the four same-Person and other-Person variants, the four legacy variants, and the A/B/C atomicity case.

## D. Atomicity

1. **The test runs the real migration.**
2. **Row order is deterministic.** Ids are fixed (`01jwp3a…0001`–`0004`), and the test asserts that order before running.
3. **MariaDB produces the intended collision.** Person A's row is renamed first, and Person B's rename then hits `1062` against B's `Guardian-Full`.
4. **The table is unchanged afterwards.** The test asserts `UniqueConstraintViolationException` and a whole-table snapshot equal to the starting state.
5. **Removing the transaction is caught.** I reproduced the mutant on 10.11.18: 1 test fails, the atomicity test, with row `…0001` left as `guardian` → `guardian-full`. That is a real partial rename. On PostgreSQL there is no collision, so all 23 pass; the triage accepts this.
6. **The test cannot pass because of an early refusal.** The refusal check's PHP `===` excludes `Guardian-Full`, and the test requires a `UniqueConstraintViolationException`, not the refusal's `RuntimeException`.

**Patch-version equivalence.** The development stack runs 10.11.19; I re-ran every decisive check on a disposable `mariadb:10.11.18` and it matched.

## E. Collation

| Case | MariaDB | PostgreSQL | Pinned |
| --- | --- | --- | --- |
| `Guardian`, `GUARDIAN`, `guardian `, `guardián` on separate Persons | not renamed; the variant row grants nothing | same | yes |
| Exact `guardian-full`, same or another Person | refused, nothing changes | same | yes |
| `Guardian-Full`, `GUARDIAN-FULL`, `guardian-full `, `guardián-full` on **another** Person | renamed beside it | same | yes |
| The same four on the **same** Person as `guardian` | `1062`, every row unchanged | renamed beside it | yes, with each engine's answer named explicitly |

The migration never normalizes or repairs a malformed key; every variant is left as stored.

**Mutants I reproduced** (results identical to the record):

| Mutant | MariaDB | PostgreSQL |
| --- | --- | --- |
| Transaction removed | killed, 1 failure | survives |
| `strcasecmp` comparison | killed, 9 | survives |
| PHP filter removed | killed, 15 | survives |
| Refusal removed | killed, 4 | killed, 4 |
| `whereRaw(lower())` with the PHP filter removed | killed, 15 | killed, 9 |
| `whereRaw(lower())` with the PHP filter kept | survives | survives |

That last mutant is genuinely equivalent: the exact key always satisfies `lower(role_key) = ?`, and the PHP filter still decides, so no test should be required to kill it. The `down()` mirror collision is development-only, unchanged and documented; it raises no new problem.

## F. Immutability guard

- **The pins are the real historical bytes.** All 15 pinned names and SHA-256 values equal `git show v0.3.0:<path> | sha256sum`, where `v0.3.0` is an annotated tag on `2f385b7`. A further test asserts there are exactly 15 entries.
- **It needs no database.** It hashes bytes with `hash_file`, never `require`s a migration, and matches Laravel's discovery rule (`glob('*_*.php')`, sorted by name).
- **It runs in the normal suite** (`phpunit.xml` declares the `Architecture` testsuite).
- **Mutations I reproduced on the real tree:**

| Planted change | Result |
| --- | --- |
| `restrictOnDelete()` → `cascadeOnDelete()` in `create_role_assignments_table` | killed: "has changed (sha256 0b159ab2…, pinned 8b63c71b…)" |
| New `2026_09_19_999999_backdated.php` | killed: "sorts before …membership_grants…" |
| Pinned file deleted | killed: "is missing" |
| Pinned file renamed to a later date | killed: "is missing" |
| Correctly dated new migration (negative control) | passes |

- **Limitation, not a defect:** someone could edit a deployed migration and regenerate its hash in the same commit. The safeguard is reviewability: an existing block must never change, and anyone can recompute it from the tag. A test cannot prevent a deliberate two-line change. The owner deferred a build-time hard refusal as G6-D20.

## G. Scope

- **No product or authorization change.** No file under `app/` changed except the helper's deletion. The role catalog, the capability bundles and every other migration are untouched.
- **No WP3b or WP3c work.** There is no upgrade suite, no rehearsal script and no runbook change.
- **WP3-01 stays open.** The triage keeps it as "FIX. Required future release prerequisite", assigned to WP3c.
- **`--database` connection selection is proven, not merely deferred.**
  - I configured a second connection on database `alt` and left the default on `flowlife_test`, each with a `guardian` row.
  - `Artisan::call('migrate', ['--database' => 'second', '--path' => <this migration>])` renamed the row in `alt` only, and logged it there.
  - `flowlife_test` kept `guardian`, and the default connection was restored afterwards.

## H. Documentation

- **The audit is intact.** `wp3-audit.md` was touched only by `f92e610`. Its 14 sections (A–N), all 13 finding IDs and severities, distinctive sentences I spot-checked and the verdict line match my handback. The "Written for" line is correctly excluded.
- **The triage is intact.** The only change is the three header lines' hard line breaks, changed from two trailing spaces to a trailing backslash. Both are CommonMark hard line breaks, so the rendered text and the rulings are identical.
- **ADR 0038's M6 and T3 corrections** state the measured behaviour exactly: only the exact key cannot collide; a same-Person variant gives `1062` atomically on MariaDB and is renamed beside on PostgreSQL; semantics are kept by owner ruling.
- **The migration comment** correctly separates "keeps the release's `guardian-full` bundle" from "expands authority by nine capabilities over production v0.3.0, which needs owner authorization".
- **G6-D20** records the deferred builder hardening, with its rationale and revisit trigger.

## I. Validation

All runs were mine, on disposable `mariadb:10.11.18` and `postgres:16-alpine` containers on a private network with no published ports.

| Run | MariaDB 10.11.18 | PostgreSQL 16 |
| --- | --- | --- |
| `GuardianRoleKeyMigrationTest` + `DeployedMigrationsTest` | 32 passed (147 assertions) | 32 passed (155) |
| 6 migration mutants, 5 guard mutations, connection proof | as in §D–§G | as in §E |

**Full-gate evidence.** I did not re-run the gate. The implementer's `gate.log` reads `HEAD e4c265e`, start 10:02:00, end 10:36:28, Pest 3,447 / 26,875 and 3,447 / 26,880, Vitest 89 files / 2,276, `✓ All checks passed`, `GATE_EXIT=0`. This matches the record, and 3,424 + 23 new tests = 3,447. `git diff --name-only e4c265e 21f45ae` lists only three files under `docs/audits/g6/`.

**Limits.** I did not run Larastan, Pint or the full gate myself.

## J. Findings

**I1 — INFORMATIONAL — the production pre-flight is described as if it already exists.**
- **Where:** ADR 0038 M6 ("The release's read-only pre-flight looks for such variants before migrating") and the migration comment ("the release's read-only pre-flight is where to look for one").
- **Fact:** the pre-flight is approved in triage (WP3-10) but not yet written. Record §8 says so explicitly and assigns it to WP3c.
- **Why it matters:** the migration file is pinned once deployed, so its comment becomes permanent. It will be accurate once WP3c delivers the pre-flight, so the risk is only a dangling reference if WP3c were dropped.
- **No implementation round is needed.** Optionally adjust the wording during PR preparation, or confirm that WP3c's scope includes the pre-flight.

## K. Next action

The owner can authorize WP3a PR preparation without another implementation round.

## L. Repository integrity

- **No changes:** no tracked file was modified, committed or pushed, and nothing was merged or deployed. Production and staging were not touched.
- **Temporary edits reverted:** mutations were temporary edits in a detached scratch worktree, each reverted (`git status` clean). The worktree, containers, network and the `mariadb:10.11.18` image I pulled are removed.
- **Untouched:** the shared checkout (still `21f45ae`, clean) and `flow-life-commons-d03`.

**G6 WP3a VERIFICATION: SAFE TO PREPARE PR**
