# G6 closeout record (WP6B)

- **Package:** WP6B, the final stabilization closeout ([contract, WP6](README.md#wp6--test-triage-and-g6-closeout)). Documentation and verification only: no product code, test, dependency, timeout or worker count changed.
- **Base:** `main` at `0fce508` (WP6A, PR #35; post-merge CI run `45217125739` green). **Branch:** `g6/wp6b-closeout`.
- **Status:** **pending the owner's acceptance.** Nothing here declares G6 complete. The roadmap says G6 is *in final acceptance* and becomes **Complete** on the owner's acceptance and integration of this package (DP7).
- **Not done, by design:** no deployment, no production access or data, no host recovery, no G7 work, no change to `useModalDialog`, no deletion of development data, no new audit, no change to `composer.lock`.
- **Decisions needed from the owner:** see [the end of this record](#owner-decisions-needed).

## G6 exit matrix (contract §11)

*Satisfied* means the evidence is in the repository or was produced in this package and is cited. *Pending* means it needs the owner. Nothing is marked satisfied on a green check alone.

| # | Criterion (verbatim) | Required evidence | Actual evidence and where | Status | Owner acceptance |
| --- | --- | --- | --- | --- | --- |
| 1 | WP0–WP6 are accepted | Each package's records, gates, mutation evidence where a guard or fix was added, squash merge and post-merge CI where CI applies ([§10](README.md#10-acceptance-criteria)) | See [WP acceptance](#wp-acceptance-criterion-1). WP0 to WP6A are merged and owner-accepted. **WP6B is this package** | **Pending: WP6B only** | Accept WP6B |
| 2 | The WP1–WP3 audits are recorded, with no open BLOCKER or HIGH, and every MEDIUM resolved or accepted in writing | The three audits, their triage and verification, and each MEDIUM's disposition | [WP1 audit](wp1-audit.md), [WP2 audit](wp2-audit.md), [WP3 audit](wp3-audit.md) with triage and independent verification, below. Zero BLOCKER and zero HIGH in all three. Two MEDIUMs, both **fixed**; one more MEDIUM (G6-D06) accepted in writing. **G6-D26 is a new finding that is not yet classified** and its published severities include a medium and (development-only) a High: see the row's note | **Satisfied for the three audits; G6-D26 pending the owner's ruling** | Rule on G6-D26 |
| 3 | The public-route test, the `console.access` invariant (human-role catalog), the deployed-migration pin and the Domain-layer rule exist and are mutation-checked | Each guard in the tree, and a mutation table naming the failing test | All four exist and ran green in Gate A (below). Mutation tables: [public routes, WP2 §2](wp2-record.md#2-wp2-01-the-global-route-authentication-guard) (7 planted violations killed); [`console.access`, WP2 §3](wp2-record.md#3-wp2-02-the-human-role-console-admission-invariant) (3 killed); [deployed-migration pin, WP3a §6](wp3-record.md#6-the-deployed-migration-guard-testsarchitecturedeployedmigrationstestphp) (D1 to D5c killed, positive control passes); [Domain-layer rule, WP4 §5](wp4-record.md#5-guards-and-mutation-evidence) (A1 to A5 and the Definitions scan B1 to B5 red; **A6, a class name held only in a string, is not detected and was not built for**) | **Satisfied** | None |
| 4 | The upgrade test is green on both engines, and the MariaDB rehearsal is recorded | The upgrade tests green on MariaDB and PostgreSQL; the rehearsal's record | `tests/Upgrade` passed on **both engines** in Gate A (below), and at WP3b on MariaDB 10.11.19 and PostgreSQL 16.15 (44 tests each, [WP3 record §11](wp3-record.md#11-results-and-gates)). The local MariaDB 10.11.18 rehearsal is recorded: [L2, L3a, L3b and WU6](../../development/restore-rehearsal.md), summarized in [WP3c closeout](wp3-record.md#wp3c-closeout-the-local-recovery-rehearsal-and-its-documentation) and accepted (owner, 2026-11-22). **Limits, recorded honestly:** it is local, synthetic, one machine, one run of each lifecycle ([G6-D23](debt-register.md)); the WU6 raw evidence is kept privately outside the repository; it is **not** production recovery readiness and does not replace the host-rehearsal decision (release checklist item 5) | **Satisfied** (as local evidence only) | None |
| 5 | The G7 entry conditions are accepted | The conditions document and the owner's acceptance | [g7-entry-conditions.md](g7-entry-conditions.md), accepted with three rulings on PR #34 (squash `0b85ae2`), recorded in [wp5-record.md](wp5-record.md). The four unconditional design-gate reviews (G6-D06, D07, D08, D14) are confirmed in the register and unchanged. A WP6B addendum there records the two rows added since | **Satisfied** | None |
| 6 | Every known item is in the register, with a disposition and a revisit trigger | A complete register | [The register](debt-register.md): **26 rows**, each with evidence, a severity (G6-D17 is *unclassified* for a stated reason: an unreproduced hypothesis), a disposition, a revisit trigger and a release impact ([reconciliation](#register-reconciliation)). WP6B added **G6-D26**, found at closeout | **Satisfied, subject to the owner's ruling on G6-D26's classification** | Rule on G6-D26 |
| 7 | `./flow check all --pgsql` is green, and `./flow test e2e --workers=4` is green on the built-assets origin | Both gates at a recorded revision, with counts | **PENDING:** see [Gate results](#gate-results) | **Pending** | None beyond the results |
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
| **WP6B** | **this PR** | docs-only: CI is skipped by path | **this record**; gates below |

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

The weekly `Security audit` workflow has failed since 2026-11-02, and `./flow audit` reports nine Composer advisories on runtime packages (plus one on a development package). Nothing in the register recorded it. A bounded, read-only [triage](wp6b-dependency-advisories.md) found **no advisory shown to be reachable in production**, a patched same-major release for every package, the `symfony/yaml` High-rated advisory to be development-only, and the medium (`symfony/mime`, header injection) to need untrusted MIME parameter names that the application's two plain-text mails do not take. It also lists what was **not** investigated (the host's script-name layout, direct `/index.php/...` requests). Proposed: LOW for this application, defer to a separate, bounded dependency-update package, a release-readiness gate, and the owner's ruling (DP4 applies if the medium is accepted). **A green `./flow check` does not resolve it**, because it does not run the audit.

## Gate results

**Tested revision and results: PENDING.** Gate A (`./flow check --pgsql --mutation`), Gate B (`./flow test e2e --workers=4`) and Gate C (documentation checks) are run after this record's substantive content is committed, and this section is then completed in a documentation-only commit.

## Owner decisions needed

1. **Rule on G6-D26** (severity, deferral to a dependency-update package, and whether to accept advisory 7 as a MEDIUM under DP4), per [the triage](wp6b-dependency-advisories.md#proposed-disposition-for-g6-d26-for-the-owners-ruling).
2. **Accept WP6B**, which makes G6 Complete under DP7, or say what must change first.
3. Whether the failing weekly `Security audit` should alert somewhere visible (not changed here).

## What G6 completion does and does not mean

- G6 is a **development and stabilization** milestone. It deployed nothing and approves no release.
- **G10 is complete as development and is not released.** Production runs **v0.3.0** unless host evidence shows otherwise; none was gathered.
- **G7 is planned, with no implementation begun.** Its design gate starts from [the entry conditions](g7-entry-conditions.md) and the [register](debt-register.md); under D10, G7 implementation waits for G6's exit criteria.
- **Production release readiness is independently gated** ([release readiness](../../runbooks/g10-release-readiness.md)): the Guardian capability authorization, the host recovery decision, the pre-flight on the host, the mail, the dependency-audit gate and a separate authorization to deploy are all open. A local rehearsal does not demonstrate production-host recovery readiness.
