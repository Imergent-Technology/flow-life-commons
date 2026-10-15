# G6 — Core audit / stabilization: implementation contract

- **Status:** Approved by the product owner on 2026-10-15, with the amendments recorded in [Owner rulings and amendments](#owner-rulings-and-amendments). Approval authorized WP0 alone. Every later package starts only when the owner authorizes it, and WP1–WP6 also require WP0 to be integrated on `main`.
- **Milestone:** G6 in the [roadmap](../../roadmap.md), placed after G10 and before G7 (the WordPress companion).
- **Records:** this directory holds G6's durable audit reports, triage decisions, remediation records, validation evidence and the [debt register](debt-register.md). Audit findings stay here. Architectural decisions stay in the [ADRs](../../adr/README.md).

## 1. Purpose, scope and non-goals

**Purpose.** Before the external surface grows in G7, establish with recorded evidence that the existing platform's security, data integrity, upgrade path and module structure can be trusted.

**In scope:**
- Identity, MFA, sessions and credentials.
- Authorization: the public-route boundary, capability invariants, step-up exemptions, sourced role grants and disclosure.
- Upgrade and restore safety from v0.3.0.
- Demonstrated boundary erosion.
- The G7 entry conditions.
- Triage of the browser and test follow-ups.
- Reconciling the documentation.

**Non-goals:**
- Building service, delegated or WordPress authentication.
- An outbox or queued jobs.
- A generated API client.
- Splitting modules.
- Deduplicating helpers.
- Performance work.
- Deployment automation, or any tooling that touches the host.
- A PostgreSQL restore rehearsal.
- Redesigning the role catalog.
- Fixing LOW findings beyond trivial, directly relevant ones.
- An open-ended hunt for flaky tests.
- Any deployment.
- Repeating G10's package audits.

**Scope control.** This contract fixes the scope. New work found during G6 goes to triage ([§6](#6-decision-points-and-stopping-conditions)). It is never added to a package directly.

## 2. Entry checkpoint and production baseline

| Item | Verified value |
| --- | --- |
| `main` at approval | `9a79b3832e0a348861555c9ead7e5d4cb31de279`: G10 WP7, squash-merged from PR #9 |
| CI | PR head `cb34707`: run `38656550748`, success. Post-merge on `9a79b38`: run `38668506906`, success |
| G10 | WP0–WP7 merged (PRs #1–#9). Complete as a development and integration milestone. Not released or deployed |
| Production | **v0.3.0**, confirmed by the product owner with `release:show` on the host |
| v0.3.0 release identity | Release id `20260927T072528-2f385b7`, commit `2f385b79a7b83323c6f15a6685ee610ccd85b5bf`, built `2026-09-27T07:25:28Z`, previous deployed release v0.2.1, `schema_rollback` `not-applicable`. The annotated tag `v0.3.0` is reachable from `main`. The date production was switched to v0.3.0 is not independently confirmed |
| Release delta | 64 commits and 14 migrations since v0.3.0 (the v0.3.0 schema is exactly the first 15 of today's 29 migrations, none of them modified since). The next release is `restore-required`. It is built with `--previous v0.3.0`, provided production still runs v0.3.0 when the artifact is built |
| G6 implementation | Not started at approval |

Facts verified at planning, which the packages below rely on:
- No test prevents a deployed migration from being edited.
- `GuardianRoleKeyMigrationTest` exercises `Access\Infrastructure\RenameGuardianRoleAssignments` directly, not the migration file.
- `mariadb:10.11.18`, production's patch version, is available as an image.

**Entry.** WP0 begins after contract approval. WP1–WP6 require WP0 to be integrated, and no feature work may be in flight.

## 3. Work packages

### WP0 — Entry reconciliation (documentation only)

1. Declare G10 complete as a development and integration milestone, stated plainly as not deployed. Record its audit history, the final verification history (including that the final three corrections had no further independent re-audit), PR #9 and both CI runs.
2. Correct the stale statuses in the roadmap, ADR 0038, the ADR index and the ADRs it amends, the charter, the module map, data ownership, member access, the documentation index and the runbooks. Make G6 **Active** in the roadmap.
3. Production history: record the v0.3.0 release identity in the [deployment runbook](../../runbooks/deployment.md#11-later-deployments) and correct its latest-release statements. The build timestamp is not a deployment timestamp.
4. Release track: turn the G10 release-readiness runbook into the [next-release readiness](../../runbooks/g10-release-readiness.md) checklist for everything since v0.3.0, pointing at the existing procedures rather than duplicating them.
5. Create the [debt register](debt-register.md) with the known issues.
6. Adopt this contract here.

**Acceptance:**
- Every stale statement is corrected or marked historical, with historical evidence preserved.
- Repository and documentation checks and `git diff --check` pass.
- Applicable CI passes. Documentation-only changes skip the application CI (`paths-ignore`), and a full dual-engine application gate is not inherently required for them.
- The owner reviews the pull request.

### WP1 — Identity, session, MFA and credential audit

An independent, read-only Opus audit comes first; remediation follows triage. The audit answers:

- **Sign-in:**
  - the unauthenticated login, logout and pending-MFA routes;
  - session regeneration and fixation at the password step and at MFA completion;
  - throttling and its keys, given that there are no trusted proxies.
- **MFA:**
  - enforcement tied to `console.access`, and the fail-closed default;
  - enrollment, replacement and reset;
  - recovery codes: how they are stored, and that each works once;
  - the TOTP replay window.
- **Step-up:** the 15-minute window plus its 60 seconds of clock allowance, and its binding to the session.
- **Security generation ([ADR 0025](../../adr/0025-account-security-generation.md)):**
  - every event that changes a credential or reduces authority advances the generation and revokes sessions;
  - [identity and access](../../architecture/identity-and-access.md) describes an email-change invalidation that is not built.
- **Session bounds:**
  - idle and absolute limits, cookie attributes and pruning;
  - the separation between `/my/` password-only sessions and the Console.
- **Credentials:**
  - the password policy;
  - the breached-password lookup: how it fails, its timeout, and that it never runs inside a transaction;
  - reset and invitation tokens: hashing, expiry, single use, and whether they let someone discover which accounts exist;
  - the operator-started password reset.
- **Account lifecycle:**
  - disable racing a sign-in;
  - bootstrap and the last-administrator rule;
  - whether identity actions are fully covered in `security_events`, with no secrets recorded.
- **Authentication strength:** `Actor::$authenticatedVia` is read by no decision. The auditor classifies this as either a present defect or a G7 entry condition (WP5).

**Not in scope:**
- re-proving properties already pinned by tests, unless the auditor finds a gap;
- relationship grants, which belong to WP2.

**Acceptance:**
- The report is committed unedited as `wp1-audit.md`, with triage decisions in `wp1-triage.md`.
- Every BLOCKER and HIGH is resolved.
- Every MEDIUM is resolved or accepted in writing by the owner.
- Remediation evidence follows [§7](#7-tests-and-mutation-evidence).
- Each HIGH or MEDIUM fix receives a focused Opus re-verification.

### WP2 — Authorization, sourced grants and disclosure audit

Same audit model as WP1. The audit answers:

- **Public-route boundary:**
  - Enumerate every route in every module.
  - Establish an explicit list of the routes allowed to be unauthenticated.
  - *Expected deliverable:* a test that fails when any route outside that list lacks authentication, unless the audit finds an equivalent already exists.
  - Correct the omissions in [trust boundaries](../../architecture/trust-boundaries.md).
- **Capability invariants:**
  - The Authorizer re-resolves the Actor, an unknown role key grants nothing, and sourced grants are unioned in.
  - *Expected deliverable:* a test of the rule that every role granting any capability also grants `console.access`. **The rule covers the existing human-role catalog.** It must be written so that it does not prematurely prohibit a distinct future service-identity model (see [ADR 0033](../../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md)).
  - The admin-only capabilities stay admin-only.
- **Step-up exemptions:**
  - List every mutation the five capability-level exemptions cover, and judge the risk of each, especially `resources.manage` publishing and changing audiences.
  - Any change a user would notice needs the owner's ruling first.
- **Authorization inside the use cases**, not only at the route, for Discussions, Resources and CRM notes. Add a test wherever one is missing.
- **Sourced grants:**
  - the Authorizer honours an orphaned grant until it is removed;
  - `relationships:check` runs only when an operator runs it;
  - dormant grants (ADR 0038, K6) make invitation authority the effective grant authority.
- **Disclosure:**
  - Resources parses stored `state` and `audience_mode` strictly ([ADR 0038](../../adr/0038-organizational-relationships-and-resource-viewing-authority.md#implementation-time-observations-not-g10-scope)). **Judge a corrupt stored value by disclosure and authorization safety**: whether anything not permitted is revealed or allowed. An HTTP 500 alone does not show unauthorized disclosure.
  - An Account's capabilities and a Person's audience eligibility stay distinct.
  - Check the file-serving headers.
  - Every hidden item answers the same 404.
- **Light pass on Discussions and CRM notes:** authorization and disclosure only.

**Not in scope:** the internals of G10 WP3–WP6, and role redesign.

**Acceptance:** as WP1. In addition, both expected tests exist and are mutation-checked.

### WP3 — Data integrity, upgrade and restore safety

**Audit (Opus, read-only):**
- The 14 migrations as they would run on production MariaDB: locks, implicit commits, foreign keys added to existing tables, and the rename.
- Integrity: foreign keys are `RESTRICT`, unique indexes behave under each engine's collation, and no orphans are possible.
- The backup and restore runbooks.
- The plan to freeze the role migration's logic (D8).

**Build (Sonnet), in three review units:**

- **3a — freeze the role migration (D8):**
  - Copy the rename logic into the migration.
  - Prove equivalence by running the existing cases against the migration file itself: refusal when run twice, the exact key comparison in PHP, the seven kinds of holder, and `/me` across the rename.
  - Delete `RenameGuardianRoleAssignments`, which nothing else uses.
  - Correct the "effective capabilities are unchanged" comment so it names its baseline.
  - Add a pin on deployed migrations: content hashes of the 15 migrations shipped in v0.3.0. Later releases append to it.
  - Semantics are preserved exactly. The role catalog and the intended authorization outcome do not change.
- **3b — upgrade test:**
  - Apply the 15 v0.3.0 migrations.
  - Load realistic synthetic v0.3.0-era data (D9):
    - Persons;
    - Accounts that are invited, active or disabled;
    - administrators, including a sole one;
    - `guardian` holders in case variants;
    - MFA enrollment and recovery codes;
    - sessions, reset tokens and pending invitations;
    - Membership grants that are open-ended, bounded or expired;
    - security events and security-generation values.
  - Run the remaining 14 migrations, then assert:
    - every pre-existing row survives unchanged except `role_key`;
    - each Account's effective capabilities equal the expected matrix, with Guardian-role holders gaining exactly the documented nine;
    - the new tables are empty;
    - `relationships:check` and the adoption report give the expected output.
  - It runs on both engines in the normal suite.
- **3c — local restore rehearsal:** a reusable, local-only script (approved, U1).
  - It runs against an isolated Compose project with its own databases, volumes, ports and credentials, on `mariadb:10.11.18`.
  - It refuses any non-local target, has no production connectivity, holds no host credentials and uses no production data.
  - Steps:
    1. Build v0.3.0 state from a v0.3.0 worktree.
    2. Back it up by the documented procedure.
    3. Upgrade to `main`.
    4. Restore into a fresh database.
    5. Check the keyring fingerprints.
    6. Compare per-table checksums with the pre-upgrade snapshot.
    7. Run v0.3.0 code against the restored database (sign-in, `/me`).
  - **A half-failed run:** in a scratch copy, force a later migration to fail, then record the schema and `migrations` table left behind. MariaDB commits each schema change implicitly and PostgreSQL does not; that difference is written down.
  - **A second cycle after the upgrade,** including the Resource files archive, proves that the dump and the files restore together.
  - Correct the backup table lists, the manifest example and the restore-together table.

**Acceptance:**
- The audit is triaged as in WP1.
- **3a:** equivalence tests green on both engines; a planted difference in case handling is caught; the deployed-migration pin fails on a planted edit.
- **3b:** green on both engines; it fails on a planted case variant and on a planted capability drift.
- **3c:** a rehearsal record with commands, timings, fingerprints and checksums.

### WP4 — Module boundaries (demonstrated erosion only)

**The Relationships Domain dependency (D6):**
- The Domain layer imports Access Application types (`Capability`, `ProvisionableRole` in `RelationshipDefinition` and `DefinitionSchema`), as do `Relationships/Definitions/*.php`.
- First, write a short options note on the smallest correction that keeps Access-specific interpretation in the Relationships Application layer, for example:
  - the Domain keeps its own key values, and Application maps them to `Capability` and `ProvisionableRole`;
  - definition validation that needs Access types moves to Application.
- The Access catalog is not duplicated, no generic authorization framework is built, and Relationships is not broadly refactored.
- If the correction proves disproportionate or riskier than a documented exception, the tradeoff goes to the owner before any implementation.
- A material dependency change receives a targeted independent review.

**Guards:**
- A generic rule: a Domain layer uses no other module's Application layer.
- `Relationships/Definitions/` is brought under the layer rules.

**Documentation:**
- the charter's rule-status table (rule 1 is now Partial: `./flow check repo` runs the WordPress boundary scan);
- module-map drift (the Eloquent line, Shared's contents).

**Not built:** the preventive table-ownership and `CASCADE` scans are deferred to the [debt register](debt-register.md) (G6-D07, G6-D08), with explicit triggers.

**Acceptance:**
- The Relationships Domain imports nothing from another module's Application layer, or an owner-approved exception is recorded in ADR 0038 and pinned by a test.
- The new rule is mutation-checked.
- The full gate is green.

### WP5 — G7 entry conditions (documentation only; nothing built)

The output is `g7-entry-conditions.md`. Each condition states the fact, the risk and what G7's design gate must decide:

1. **Person-scoped Resource delivery outside Commons.** Delegated authentication (ADR 0033's trigger) or a new public-audience design. Today there is no public Resource audience.
2. **The first service identity.**
   - The boundary tests that must be deliberately revised: `MembershipTrustBoundariesTest`, `MembershipRouteSurfaceTest` and `ResolveActor`.
   - How a confused deputy, or a client-asserted `person_id`, is excluded.
3. **Authentication strength for any non-Console caller** (from WP1).
4. **ADR 0038 P8.** Revisit it before any WordPress identity is linked by CRM email.
5. **Trusted proxies,** throttle keys and the attribution of `security_events`.
6. **Step-up for publication** that becomes visible outside Commons.
7. **Rendering the document profile on another origin,** where its link allowlist becomes an XSS boundary.
8. **A minimal `/v1` error and deprecation policy,** before an external client depends on it.
9. **The browser-workflow revisit** (D7, G6-D06).
10. **Debt-register items** whose trigger is G7.

**Acceptance:** the owner accepts it.

### WP6 — Test triage and G6 closeout

- **Discussions focus restoration (G6-D01).** Reproduce it and find the root cause.
  - If it is a product accessibility defect, fix it, with a test proving where focus lands.
  - If it is a test defect, fix the test.
  - Allow at most two investigation passes before it is registered.
- **Classify** the 1023 px shell intermittency, the Vitest cold-render history, E2E leftovers and the keep-alive gap.
- **Record D7** in [testing](../../development/testing.md) and the register.
- **Close out:** finalize the register, update the roadmap and charter (G6 Complete), write the closeout record and run the final gates.

**Acceptance:** [§11](#11-g6-exit-criteria).

## 4. Dependencies and sequencing

```
WP0 ─► audits WP1 ∥ WP2 ∥ WP3   (read-only, pinned to the WP0 merge commit)
     ─► joint triage (DP1)
     ─► remediation, one at a time: WP1 → WP2 → WP3a → WP3b → WP3c
     ─► WP4   (after WP2: shared architecture and route test files)
     ─► WP5   (after WP1 and WP2 close)
     ─► WP6
```

- **Triage sets the remediation order by severity.** A BLOCKER or HIGH goes first, whichever package it is in.
- **Audits may run in parallel only because they are read-only.** Each uses its own git worktree, and only one process uses the development databases at a time.
- **Implementation is strictly one branch at a time,** each from current `main` after the previous merge. Nothing is implemented in parallel against a shared working tree or database.

## 5. Independent audit requirements

| Package | Audit | Re-verification |
| --- | --- | --- |
| WP1, WP2, WP3 | Independent, read-only Opus, at a pinned commit, before any remediation | A focused Opus check of each HIGH or MEDIUM fix, not a full re-audit |
| WP3a (D8) | Covered by WP3's audit, plus a focused review of the equivalence proof | — |
| WP4 | Focused independent review and mutation evidence; a targeted independent review if the dependency change is material | — |
| WP0, WP5, WP6 | The owner's review | — |

Sonnet carries out bounded implementation and corrections. Audits stay separate from implementation: an auditor never implements its own findings. Areas G10 already audited are not re-audited in full.

## 6. Decision points and stopping conditions

- **DP1 — Triage after each audit.** The owner rules on every finding (fix, accept or defer) before remediation begins.
- **DP2 — Changes to product behaviour** need the owner's ruling before implementation: who must use MFA, step-up requirements, what users can see.
- **DP3 — The D6 tradeoff,** if the correction proves disproportionate.
- **DP4 — Accepting a MEDIUM** needs the owner's written rationale in the triage file.
- **DP5 — A finding that needs an ADR or architectural change.** Stop and decide whether it becomes its own package. It does not grow the current one.
- **DP6 — CI runtime.** If the PR job passes 22 minutes of its 25-minute limit, the owner decides whether to raise the limit or move tests.
- **DP7 — The closeout declaration.**

**Stopping conditions:**
- One remediation round plus one focused re-verification per package. A second round happens only for a remaining BLOCKER or HIGH.
- If a remediation would change more than one module's public contract, stop (DP5).
- A browser flake that cannot be classified within two passes is registered. It is never quarantined without the owner's authorization.
- A nominal severity never overrides a genuinely release-critical consequence (D4).

## 7. Tests and mutation evidence

- **Every fix of a BLOCKER, HIGH or MEDIUM** has a test that is shown failing on the code before the fix and passing after it.
- **Every new guard** (architecture, route surface, catalog, migration pin) is mutation-checked:
  - the violation is planted in a scratch copy, or as an uncommitted temporary change;
  - the record names the failing test and its message;
  - planted violations are never committed.
- **Backend behaviour runs on both engines,** and races are tested with two processes on both engines.
- **Console changes** run Vitest, typecheck, lint, the build and `verify:build`, plus the affected journeys on the built-assets origin.
- **Gates:**
  - `./flow check all --pgsql` green locally, and PR CI green, for every change to the application.
  - `./flow test e2e --workers=4` on the built-assets origin for any change to the Console or E2E, and at closeout.
  - For documentation-only changes, the WP0 gate in [§3](#wp0--entry-reconciliation-documentation-only) applies.
- **A gate failure is never silently retried.** It is recorded and then classified by isolated runs (10 for a browser journey).
- **Evidence** goes in `wpN-record.md`: the commands, engines, counts and a table of mutations.

## 8. MariaDB and PostgreSQL

- **MariaDB 10.11 is the production target and is authoritative.**
  - The rehearsal uses `10.11.18`.
  - Development and CI stay on the floating `10.11` tag; pinning them is register item G6-D09.
- **PostgreSQL portability remains a secondary guardrail:**
  - every new backend test runs on it, including the upgrade test;
  - a PostgreSQL-only failure is fixed when it reveals a portability defect;
  - there is no PostgreSQL restore rehearsal.
- **Collation.** Tests include case variants wherever MariaDB's case-insensitive collation matters.
- **No engine-specific SQL without an ADR** ([charter rule 13](../../architecture/charter.md)).
- **WP3c documents the difference in transactional schema changes.**

## 9. Release track (outside G6)

Production release readiness is a parallel operational track, kept in the [next-release readiness](../../runbooks/g10-release-readiness.md) runbook. G6 contributes evidence to it but resolves none of it, and never deploys.

| Release item | What G6 contributes | What still needs the host or the owner |
| --- | --- | --- |
| Approving the nine-capability Guardian expansion | 3b's exact capability matrix | The owner's decision |
| Counting and reviewing production Guardian-role holders | A documented read-only query against the v0.3.0 schema | Running it on production |
| `restore-required` recovery | 3c's procedure, timings and checksum method | A host backup, and a host rehearsal or explicit risk acceptance |
| Resources storage and backup | 3c's dump-plus-files cycle; corrected lists | cPanel coverage of `shared/`; `resources:assets:probe` |
| Mail, administrator continuity, PHP limits, adoption | None | All of it |
| Host-step corrections in the runbooks | None | The corrections |
| Deployment authorization | WP1–WP3 outcomes (D10) | The owner's authorization |

A successful local rehearsal does not replace the decision on the real host.

## 10. Acceptance criteria

Each package's acceptance is in [§3](#3-work-packages). For every package:
- its records are in this directory;
- its gates are green;
- its mutation evidence is recorded where a guard or fix was added;
- it is squash-merged, with post-merge CI green where CI applies.

## 11. G6 exit criteria

1. WP0–WP6 are accepted.
2. The WP1–WP3 audits are recorded, with no open BLOCKER or HIGH, and every MEDIUM resolved or accepted in writing.
3. The public-route test, the `console.access` invariant (human-role catalog), the deployed-migration pin and the Domain-layer rule exist and are mutation-checked.
4. The upgrade test is green on both engines, and the MariaDB rehearsal is recorded.
5. The G7 entry conditions are accepted.
6. Every known item is in the register, with a disposition and a revisit trigger.
7. `./flow check all --pgsql` is green, and `./flow test e2e --workers=4` is green on the built-assets origin.
8. The roadmap, charter and module map are current.
9. Nothing was deployed, and no production change was made.

## 12. Deferred issues and the debt register

The [debt register](debt-register.md) has one row per item:
- identifier and source;
- evidence;
- severity;
- disposition (fix, accept or defer), naming who accepted any MEDIUM;
- revisit trigger;
- release impact.

The register **outlives G6**. G7's design gate must read it, and each later milestone's design gate must check its triggers. LOW items are not fixed in G6 unless they are trivial and directly relevant. Follow-ups already recorded in earlier ADR implementation notes (for example [ADR 0037's](../../adr/0037-resources-are-audience-targeted-packs-of-cards.md) G5 follow-ups) stay where they are, and enter the register only when triage makes one relevant.

## 13. Handoff to G7

G7's design gate (its WP0) addresses every G7 entry condition explicitly in its ADR, as resolved, accepted or deferred with a reason, and every register item whose trigger is G7. G6 decides none of G7's design. G7 implementation does not start until G6's exit criteria are met (D10).

## Branches and review units

- Changes are made in **coherent review units**, not a fixed number of pull requests.
- Each unit gets its own branch from current `main`, small commits, and a squash merge.
- Indicative units, which may be split or joined:
  - WP0 (`docs/g6-wp0-entry-reconciliation`);
  - each audit's report and triage followed by its remediation;
  - WP3a, WP3b and WP3c separately;
  - WP4;
  - WP5;
  - a WP6 product fix, if there is one;
  - the WP6 closeout.

## Owner rulings and amendments

Rulings D1–D10 were given at the planning gate. The amendments were given with approval on 2026-10-15.

| # | Ruling |
| --- | --- |
| D1 | G10 is complete as a development and integration milestone. Its verification, final PR and post-merge CI are recorded; production-readiness items move to a separate release track. Nothing implies G10 is deployed |
| D2 | Production runs **v0.3.0**, not v0.2.1. It is the baseline for release-delta calculations and for WP3's upgrade and recovery testing |
| D3 | Independent, read-only Opus audits for WP1–WP3, each triaged before remediation. Sonnet for bounded implementation and corrections. WP4 may use focused independent review and mutation evidence, but material changes to architectural dependencies need targeted independent scrutiny. Audits already completed in G10 are not repeated |
| D4 | BLOCKER and HIGH resolved before closeout. MEDIUM resolved, or accepted by the owner with documented risk. LOW recorded in the register unless directly relevant and trivial. INFORMATIONAL documented when material. A nominal severity never overrides a genuinely release-critical consequence |
| D5 | Durable audit records live in `docs/audits/g6/`, separate from ADRs |
| D6 | Prefer correcting the Relationships Domain dependency on Access Application types, by the smallest practical change. If correction is disproportionate, present the tradeoff before implementing. No broad refactor |
| D7 | Keep the local four-worker built-assets browser gate. Do not make the whole suite mandatory on every pull request, and do not permanently declare browser testing local-only: revisit an on-demand or scoped GitHub workflow before or during G7. No test is quarantined without authorization |
| D8 | Freeze the role-key migration's logic inside the migration, preserving its semantics exactly (refusal behaviour and MariaDB case handling included), with focused review and tests proving unchanged behaviour. No change to the role catalog or the intended authorization outcome |
| D9 | Synthetic production-era data based on the v0.3.0 schema first. No copy of production data in development without a separate authorization and data-handling plan. A local rehearsal does not replace the real-host decision |
| D10 | The accumulated milestones are not deployed until WP1–WP3's critical findings are resolved or accepted under D4. Release preparation may proceed in parallel, but G6 never deploys. Aim to finish G6 before G7 implementation, without indefinitely delaying a useful Guardian production pilot for speculative infrastructure |

**Amendments at approval:**
- **Entry:** WP0 begins after approval; WP1–WP6 require WP0's integration.
- **Audit strategy:** independent read-only Opus reviews precede remediation in WP1–WP3, separate from Sonnet implementation.
- **WP2:** the `console.access` invariant applies to the existing human-role catalog, and must not prematurely prohibit a distinct future service-identity model.
- **Resource corruption:** fail-closed behaviour is judged by disclosure and authorization safety. An HTTP 500 alone does not demonstrate unauthorized disclosure.
- **Pull requests:** coherent review units, not a fixed number.
- **WP0 validation:** repository and documentation checks, `git diff --check` and applicable CI, with no required check bypassed. A full dual-engine application gate is not inherently necessary for documentation-only changes.
- **WP3 rehearsal (U1):** a reusable local-only script is approved, with an isolated Compose project, databases, volumes and credentials. It has no production connectivity and uses no production data.
- **WP4 (U2):** the preventive table-ownership and `CASCADE` scans are deferred to the debt register, with explicit triggers.
