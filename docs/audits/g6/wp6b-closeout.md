# G6 closeout record (WP6B)

- **Package:** WP6B, the final stabilization closeout ([contract, WP6](README.md#wp6--test-triage-and-g6-closeout)). Documentation and verification only: no product code, test, dependency, timeout or worker count changed.
- **Base:** `main` at `0fce508` (WP6A, PR #35; post-merge CI run `45217125739` green). **Branch:** `g6/wp6b-closeout`.
- **Status:** **accepted by the owner** (PR #36 review, 2026-11-23), with a bounded risk acceptance for G6-D26 and the documentation corrections made after that review. **G6 is owner-accepted and is formally integrated and closed on the successful squash merge of PR #36** (DP7). The distinction is deliberate: acceptance is the owner's decision; integration is the merge.
- **Not done, by design:** no deployment, no production access or data, no host recovery, no G7 work, no change to `useModalDialog`, no deletion of development data, no new audit, no change to `composer.lock`.
- **Owner decisions:** recorded at [the end of this record](#owner-decisions-and-rulings).

## G6 exit matrix (contract §11)

*Satisfied* means the evidence is in the repository or was produced in this package and is cited. *Pending* would mean it still needs the owner (none remains). Nothing is marked satisfied on a green check alone.

| # | Criterion (verbatim) | Required evidence | Actual evidence and where | Status | Owner acceptance |
| --- | --- | --- | --- | --- | --- |
| 1 | WP0–WP6 are accepted | Each package's records, gates, mutation evidence where a guard or fix was added, squash merge and post-merge CI where CI applies ([§10](README.md#10-acceptance-criteria)) | See [WP acceptance](#wp-acceptance-criterion-1). WP0 to WP6A are merged and owner-accepted. **WP6B is this package** | **Satisfied on integration:** WP6B is owner-accepted (2026-11-23) and is integrated by the squash merge of PR #36 | Given (PR #36 review) |
| 2 | The WP1–WP3 audits are recorded, with no open BLOCKER or HIGH, and every MEDIUM resolved or accepted in writing | The three audits, their triage and verification, and each MEDIUM's disposition | [WP1 audit](wp1-audit.md), [WP2 audit](wp2-audit.md), [WP3 audit](wp3-audit.md) with triage and independent verification, below. Zero BLOCKER and zero HIGH in all three. Two MEDIUMs, both **fixed**; one more MEDIUM (G6-D06) accepted in writing. **G6-D26 is a new finding, ruled MEDIUM (application exposure not demonstrated) and accepted temporarily under DP4 to close G6** (owner, 2026-11-23): see [G6-D26](#g6-d26-the-finding-made-at-closeout) | **Satisfied** (the G6-D26 MEDIUM is accepted in writing, with remediation required before the next release) | Given (PR #36 review) |
| 3 | The public-route test, the `console.access` invariant (human-role catalog), the deployed-migration pin and the Domain-layer rule exist and are mutation-checked | Each guard in the tree, and a mutation table naming the failing test | All four exist and ran green in Gate A (below). Mutation tables: [public routes, WP2 §2](wp2-record.md#2-wp2-01-the-global-route-authentication-guard) (7 planted violations killed); [`console.access`, WP2 §3](wp2-record.md#3-wp2-02-the-human-role-console-admission-invariant) (3 killed); [deployed-migration pin, WP3a §6](wp3-record.md#6-the-deployed-migration-guard-testsarchitecturedeployedmigrationstestphp) (D1 to D5c killed, positive control passes); [Domain-layer rule, WP4 §5](wp4-record.md#5-guards-and-mutation-evidence) (A1 to A5 and the Definitions scan B1 to B5 red; **A6, a class name held only in a string, is not detected and was not built for**) | **Satisfied** | None |
| 4 | The upgrade test is green on both engines, and the MariaDB rehearsal is recorded | The upgrade tests green on MariaDB and PostgreSQL; the rehearsal's record | `tests/Upgrade` passed on **both engines** in Gate A (below), and at WP3b on MariaDB 10.11.19 and PostgreSQL 16.15 (44 tests each, [WP3 record §11](wp3-record.md#11-results-and-gates)). The local MariaDB 10.11.18 rehearsal is recorded: [L2, L3a, L3b and WU6](../../development/restore-rehearsal.md), summarized in [WP3c closeout](wp3-record.md#wp3c-closeout-the-local-recovery-rehearsal-and-its-documentation) and accepted (owner, 2026-11-22). **Limits, recorded honestly:** it is local, synthetic, one machine, one run of each lifecycle ([G6-D23](debt-register.md)); the WU6 raw evidence is kept privately outside the repository; it is **not** production recovery readiness and does not replace the host-rehearsal decision (release checklist item 5) | **Satisfied** (as local evidence only) | None |
| 5 | The G7 entry conditions are accepted | The conditions document and the owner's acceptance | [g7-entry-conditions.md](g7-entry-conditions.md), accepted with three rulings on PR #34 (squash `0b85ae2`), recorded in [wp5-record.md](wp5-record.md). The four unconditional design-gate reviews (G6-D06, D07, D08, D14) are confirmed in the register and unchanged. A WP6B addendum there records the two rows added since | **Satisfied** | None |
| 6 | Every known item is in the register, with a disposition and a revisit trigger | A complete register | [The register](debt-register.md): **26 rows**, each with evidence, a severity (G6-D17 is *unclassified* for a stated reason: an unreproduced hypothesis), a disposition, a revisit trigger and a release impact ([reconciliation](#register-reconciliation)). WP6B added **G6-D26**, found at closeout | **Satisfied** (G6-D26 classified by the owner) | Given (PR #36 review) |
| 7 | `./flow check all --pgsql` is green, and `./flow test e2e --workers=4` is green on the built-assets origin | Both gates at a recorded revision, with counts | Both gates passed at `9f3fbf8` on 2026-11-23: [Gate results](#gate-results) (`./flow check --pgsql --mutation` exit 0; `./flow test e2e --workers=4` 448 + 10 + 1 passed, 0 failed, on the built-assets origin). **The criterion names `./flow check all --pgsql`; the milestone gate used was `./flow check --pgsql --mutation`, which adds the full recovery mutation matrix to the same routine, dual-engine scope** ([testing](../../development/testing.md#verification-tiers)) | **Satisfied** | None beyond the results |
| 8 | The roadmap, charter and module map are current | Each reviewed against the repository | **Roadmap** (status paragraph, milestone table and G6 package table) and the **charter's** milestone sentence updated in this package. The **module map** was reviewed: the 11 modules on disk match its tables, and its Domain-layer rule and Relationships note were updated by WP4 (`8efce28`); **no change was needed**. The G7 entry document's stale WP6 statements were corrected | **Satisfied** | None |
| 9 | Nothing was deployed, and no production change was made | No deployment, production access or production change | None occurred in WP0 to WP6B: no release was built for deployment, no host or production data was accessed, and every rehearsal ran on a local Docker daemon with synthetic data (each package record says so). Production runs v0.3.0. The one read-only look at the `v0.3.0` tag was of the repository's tagged lockfile, not the host. This is a statement from the records and this session; it cannot be proved by a tool | **Satisfied** | None |

### WP acceptance (criterion 1)

| Package | Integrated | Post-merge CI | Records and evidence |
| --- | --- | --- | --- |
| WP0 | PR #10 `b5d37ad` | docs-only (CI skips it by path) | README, register, roadmap |
| WP1 | PR #11 `18d059e` | run `38806979095` green | [audit](wp1-audit.md), [triage](wp1-triage.md), [record](wp1-record.md), [verification: SAFE TO PREPARE PR](wp1-verification.md) |
| WP2 | PR #12 `a7af9a0` | run `39031098500` green | [audit](wp2-audit.md), [triage](wp2-triage.md), [record](wp2-record.md), [verification](wp2-verification.md), [final recheck: SAFE TO PREPARE PR](wp2-final-recheck.md) |
| WP3a | PR #13 `95bcd84` | run `39098496073` green | [record](wp3-record.md#wp3a--migration-freeze-and-immutability), [verification: SAFE TO PREPARE PR](wp3a-verification.md) |
| WP3b | PR #14 `b629a6a`, #15 `46c02b2` | runs `39184617196`, `39224450461` green | [record](wp3-record.md#wp3b--historical-upgrade-tests) with its reviews (§14 to §14.4) |
| WP3c | PRs #16 to #32; closeout `c6b093d` | every code merge green; PR #16 `24f439a` failed and was repaired by PR #17 `4d17e1a` (green); #31 and #32 docs-only | [slices and closeout](wp3-record.md#wp3c-closeout-the-local-recovery-rehearsal-and-its-documentation); owner rulings 2026-11-22 |
| WP4 | PR #33 `8efce28` | run `45056391832` green | [record](wp4-record.md) |
| WP5 | PR #34 `0b85ae2` | docs-only | [record](wp5-record.md), owner rulings applied |
| WP6A | PR #35 `0fce508` | run `45217125739` green (29m48s) | [triage](wp6a-triage.md), owner rulings |
| **WP6B** | **PR #36** (squash merge; the merge commit is reported in the handback, since a commit cannot name itself) | docs-only: CI is skipped by path | **this record**; gates below; owner-accepted 2026-11-23 |

### Audit findings and dispositions (criterion 2)

| Audit | Findings | MEDIUM | Disposition evidence |
| --- | --- | --- | --- |
| [WP1](wp1-audit.md) | 0 BLOCKER, 0 HIGH, 1 MEDIUM, 3 LOW, 1 INFORMATIONAL | WP1-01 (password change extended an MFA session): **fixed**, with a test failing before and passing after, on both engines (owner ruling M1/DP2) | [Triage](wp1-triage.md), [record](wp1-record.md), [verification](wp1-verification.md); LOWs in the register as G6-D10, G6-D11 |
| [WP2](wp2-audit.md) | 0 BLOCKER, 0 HIGH, 0 MEDIUM, 6 LOW | none | [Triage](wp2-triage.md) (K1 to K4), [record](wp2-record.md), [final recheck](wp2-final-recheck.md); LOWs in the register as G6-D12 to G6-D19 |
| [WP3](wp3-audit.md) | 0 BLOCKER, 0 HIGH, 1 MEDIUM, 6 LOW, 6 INFORMATIONAL | WP3-01 (in-place restore retained newer tables): **fixed** in the operator contract and **reproduced then corrected** in the local rehearsal (cleared-schema restore; owner ruling 2026-11-22). It remains a **release prerequisite**: blocking until the host decision (checklist item 5) | [Triage](wp3-triage.md), [record](wp3-record.md); LOWs and INFORMATIONALs in the register as G6-D20 to G6-D24 |
| Register | one further MEDIUM: G6-D06 (browser E2E not in GitHub CI) | **Accepted in writing by the owner** (ruling D7, 2026-10-15), not permanent | [README, D7](README.md#owner-rulings-and-amendments) |

### Register reconciliation

Every row was read, not only D01 to D06, D25 and D26. Each has the five required fields; nothing was reclassified. G6-D01 to D06 carry the owner's PR #35 dispositions; G6-D07 to G6-D24 are unchanged from the triage rulings (each names who accepted or deferred it). The WP5 G7-triggered set is intact: **G6-D06, D07, D08 and D14 remain the four unconditional design-gate reviews**, and their rows still name G7. G6-D17 is the only *unclassified* severity, with a stated reason (an unproven hypothesis; "never speculative changes"). There is **no open BLOCKER or HIGH and no unaccepted MEDIUM** except as G6-D26 may be ruled (below).

## G6-D26: the finding made at closeout

The weekly `Security audit` workflow has failed since 2026-11-02, and `./flow audit` reports **nine Composer advisories: eight on runtime packages and one on the development-only `symfony/yaml`**. Nothing in the register recorded it. A bounded, read-only [triage](wp6b-dependency-advisories.md) found **no advisory shown to be reachable in production**, a patched same-major release for every package, the `symfony/yaml` High-rated advisory to be development-only, and the medium (`symfony/mime`, header injection) to need untrusted MIME parameter names that the application's two plain-text mails do not take. It also lists what was **not** investigated (the host's script-name layout, direct `/index.php/...` requests, the installed dependencies on the host).

**Owner ruling (PR #36 review, 2026-11-23).** G6-D26 is **MEDIUM: application exposure not demonstrated**, not the LOW the triage proposed, and the risk is **accepted temporarily under DP4 for the purpose of closing G6 only**. Rationale: no confirmed reachable path; the High is development-only; several affected features are unused; the host's path behaviour and installed dependencies were not verified; G6 completion authorizes no deployment; patched releases exist and remediation is required before the next release. **The acceptance does not authorize using the existing lockfile for the next production deployment.** A **separate, prioritized dependency-security package** must update the dependencies, verify compatibility, investigate the unresolved path-handling cases and restore a passing runtime dependency audit. **The next release stays blocked** by its dependency-audit item ([release readiness, item 12](../../runbooks/g10-release-readiness.md)); a waiver needs a separate, advisory-specific, explicit owner decision. A green `./flow check` does not resolve it, because it does not run the audit. Full text: [the triage's ruling section](wp6b-dependency-advisories.md#owner-ruling-on-g6-d26-pr-36-review-2026-11-23).

## Gate results

Both application gates ran **sequentially on an idle host** (load average under 1.5 at each start), with no competing workload, at one revision: **`9f3fbf8f34037648b8e8032f46c2e4f1893ce3a5`**, the head of this branch when the substantive closeout content was committed. Only this section and the matrix cells that cite it were edited afterwards (documentation; they do not touch what ran). No retry, quarantine, timeout change or assertion change was used.

### Gate A: `./flow check --pgsql --mutation` (2026-11-23, 18:16 to 19:35 UTC, 1 h 19 min): **passed, exit 0**

| Part | Result |
| --- | --- |
| Rehearsal summary (the machine-readable line) | `REHEARSAL_SUMMARY mode=full result=passed ordinary_run=326 ordinary_failed=0 registry=consistent guards_registered=488 mutants_executed=488 mutants_caught=488 matrix=run workers=4` |
| The required equalities | `mode=full`; `result=passed`; `registry=consistent`; **`mutants_executed` (488) = `guards_registered` (488) = `mutants_caught` (488)** |
| Repo checks | Compose configs, shellcheck, `./flow` CLI behaviour, production public surface (Apache/Caddy contract and under real Apache), release tooling, pre-flight SQL, the rehearsal's full run, actionlint, WordPress companion checks: all passed |
| Backend | Pint and Larastan (level max) clean. **MariaDB: 3,726 passed (28,187 assertions). PostgreSQL: 3,681 passed, 45 skipped (27,890 assertions)**; the 45 are tests that declare themselves MariaDB-only with a stated reason (the rehearsal snapshot, the pre-flight SQL, the half-failed upgrade), and 3,681 + 45 = 3,726. The guards of criterion 3 and `tests/Upgrade` ran in both runs |
| Frontend | Strict TypeScript, ESLint, Prettier, **Vitest 89 files, 2,276 tests passed**, production build, `verify:build` |
| Said not to be included | Browser E2E, `./flow audit`, release checks, as the gate always states |

The live WP3c Docker rehearsal was **not** repeated (its evidence is accepted), and the matrix was run once.

### Gate B: `./flow test e2e --workers=4` on the built-assets origin (2026-11-23, 19:39 to 19:50 UTC, about 11 minutes): **passed, exit 0**

| Pass | Result |
| --- | --- |
| Main (development and built projects, four workers) | **448 passed**, 0 failed, 0 flaky, 0 not run (9.3 min) |
| Maintenance (serial) | 10 passed |
| Leftovers | 1 passed |
| `relationships:check` after the run | "Relationships are consistent with their definitions." |

The two formerly intermittent journeys **passed** in this run: *discussions › a Guardian starts a discussion, replies, edits, resolves, reopens, removes their own message and finds it again* (G6-D01, corrected) and *responsive defaults › follow the width across both breakpoints and write nothing* (G6-D02). One passing run does not settle G6-D02, which stays an unresolved intermittent. The run was 448 + 10 + 1 = 459 passes; the development database grew again as G6-D04 expects, and nothing was deleted. The seeding steps ran as the gate does and touched only development data.

### Gate C: documentation checks

Run after Gates A and B, on the closeout content including the gate results above, and **re-run on the final content after the owner-review documentation corrections** (the gates A and B were not repeated, as ruled: nothing but documentation changed):

| Check | Result |
| --- | --- |
| `./flow check repo` | **Passed, exit 0.** Its rehearsal line was the routine one (`mode=routine result=passed ordinary_run=326 ordinary_failed=0 registry=consistent guards_registered=488 mutants_executed=0 matrix=not-run`); the full matrix is Gate A's |
| Relative links and anchors in every changed Markdown file (a script over the 8 files, GitHub heading slugs) | 372 links, **0 bad** (re-run after the owner-review corrections; 368 before them) |
| `git diff --check` | Clean |
| Changed-file scope | Eight files, all under `docs/`: `docs/roadmap.md`, `docs/architecture/charter.md`, `docs/runbooks/g10-release-readiness.md`, `docs/security/dependencies.md`, and under `docs/audits/g6/` the register, the G7 entry conditions, this record and the dependency advisory triage. **No code, test, dependency, workflow or configuration file changed**, so Gates A and B (run at `9f3fbf8`) remain valid for the final head, which differs from it only by documentation commits |

## Owner decisions and rulings

Given on the independent review of PR #36 (2026-11-23):

1. **WP6B is accepted**, with the G6-D26 ruling above and five documentation corrections: the advisory count is nine in total (eight runtime, one development-only); G6-D26 in the register, the triage and this record shows the MEDIUM risk acceptance, the DP4 rationale, the uncertainty, the remediation trigger and the release restriction; the roadmap and charter show G6 Complete under this acceptance, distinguishing acceptance from integration; the release checklist item states the runtime audit must be clean unless a separate, advisory-specific acceptance is authorized; and the scheduled workflow's alerting is recorded as a separate operational follow-up.
2. **Operational follow-up, not implemented here:** the scheduled `Security audit` workflow should report failures to a monitored destination.
3. **Not authorized, and not begun:** G7, the dependency-security package, any lockfile change, any workflow change, and any production deployment.

## What G6 completion does and does not mean

- G6 is a **development and stabilization** milestone. It deployed nothing and approves no release.
- **G10 is complete as development and is not released.** Production runs **v0.3.0** unless host evidence shows otherwise; none was gathered.
- **G7 is planned, with no implementation begun.** Its design gate starts from [the entry conditions](g7-entry-conditions.md) and the [register](debt-register.md); under D10, G7 implementation waits for G6's exit criteria.
- **Production release readiness is independently gated** ([release readiness](../../runbooks/g10-release-readiness.md)): the Guardian capability authorization, the host recovery decision, the pre-flight on the host, the mail, the dependency-audit gate and a separate authorization to deploy are all open. A local rehearsal does not demonstrate production-host recovery readiness.
