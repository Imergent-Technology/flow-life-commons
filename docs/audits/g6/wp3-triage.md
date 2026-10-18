# G6 WP3: Owner Triage Decisions

**Milestone:** G6 Core Audit / Stabilization\
**Package:** WP3, Data Integrity, Upgrade and Restore Safety\
**Audit baseline:** `a7af9a0f25233244426daa2afadcaabbf32249b3`\
**Production baseline:** v0.3.0, `2f385b79a7b83323c6f15a6685ee610ccd85b5bf`

**Decision: APPROVED FOR BOUNDED REMEDIATION**

## Executive ruling

Accept the independent Opus audit's findings:

- Zero BLOCKER
- Zero HIGH
- One MEDIUM
- Six LOW
- Six INFORMATIONAL

The MariaDB migration path is fundamentally sound, but the manual recovery procedure contains a demonstrated flaw.

The owner approves correcting that flaw and the directly related findings within the existing WP3a, WP3b and WP3c packages.

**Automatic production rollback is explicitly out of scope.**

The approved recovery model remains:

1. Verify and preserve a complete pre-upgrade backup.
2. Apply the upgrade during a maintenance window.
3. If recovery is necessary, keep the application in maintenance mode.
4. Have an authorized operator deliberately initiate the documented manual restore.
5. Restore to the intended historical schema and data, including the matching encryption keyring and Resource files.
6. Verify recovery and reconcile security-sensitive changes before returning to service.

WP3c may automate the local rehearsal, but it must not implement automatic production restoration.

## Finding dispositions

### WP3-01: MEDIUM — In-place restoration retains newer tables

**Disposition: FIX. Required future release prerequisite.**

The audit reproduced a restoration that preserved newer tables and data despite matching the original database checksums.

The manual restore contract must require:

- A verified backup and matching encryption keyring.
- Explicit verification of the database being restored.
- Returning the target database to an empty application-schema state before loading the historical dump.
- Restoration of the correct table inventory, records and supporting files.
- Verification that no post-upgrade tables remain.
- A successful subsequent upgrade after restoring the old release.

The operator must retain control of destructive production actions.

WP3c must reproduce the original defect and demonstrate the corrected procedure using isolated synthetic data.

A fresh-database restore alone is not sufficient evidence.

**Release impact:** Blocking until corrected and rehearsed.

### WP3-02: LOW — MariaDB role-key variant collision

**Disposition: PRESERVE CURRENT SEMANTICS; TEST AND DOCUMENT.**

Do not introduce collation-aware normalization, automatic key correction or a changed refusal policy.

Preserve strict PHP role-key comparisons and existing database-specific outcomes.

WP3a must test variants including case, trailing spaces and accents. Correct the overstated collision guarantee in ADR 0038.

The future production preflight must identify suspicious role-key variants before migration.

### WP3-03: LOW — Explicit rename transaction unprotected

**Disposition: FIX IN WP3a.**

The explicit transaction is required for atomic role-key updates on MariaDB.

Preserve it when copying the implementation into the migration file.

Introduce a deterministic failure case in which a transaction-free implementation would partially rename rows. Mutation-check this on MariaDB.

Do not rely on PostgreSQL's migration transaction behavior as proof of MariaDB atomicity.

### WP3-04: LOW — Backup command fails without Resources directory

**Disposition: FIX IN WP3c.**

Handle an absent Resources directory as a legitimate zero-file state without accepting a failed or incomplete archive.

Document that v0.3.0 lacks `resources:assets:prune`. During rollback to v0.3.0, preserve any existing newer Resource files separately and do not invoke commands unavailable in the restored code.

Verify the zero-file backup path in the local rehearsal.

### WP3-05: LOW — Incomplete Resources consistency verification

**Disposition: FIX IN WP3c.**

Require all relevant asset-prune categories to match expected values, including unreferenced files younger than 24 hours and invalid storage keys.

Verify the number of kept files equals the asset inventory, alongside database and file checksums.

Prove that a deliberately planted young orphan file fails the consistency check.

Do not invoke destructive pruning as a shortcut to passing validation.

### WP3-06: LOW — Incomplete table inventories

**Disposition: FIX DOCUMENTATION IN WP3c.**

Reconcile the deployment and backup runbooks, ADR 0027 and data-ownership documentation with the complete current schema.

Include CRM, Discussions, organizational relationships and sourced-role grants.

Specify that relationships and their sourced grants must be restored from one consistent point in time.

Include `relationships:check` among post-restoration integrity checks.

Preserve historical ADR decisions while correcting current operational guidance.

### WP3-07: LOW — Restoration reverses security changes

**Disposition: FIX THE MANUAL RECOVERY CONTRACT IN WP3c.**

Document that restoring an older database can reverse:

- Password changes.
- Account disabling.
- Role revocations.
- Relationship or sourced-grant withdrawals.
- MFA and recovery-code changes.
- Other security-sensitive actions after the backup.

Before returning the restored application to service, the operator must reconcile authority-reducing actions using trustworthy records preserved outside the restored database.

If the security state cannot be established confidently, the operator must stop and escalate rather than reopen service.

Do not implement automatic replay of security actions.

## Informational findings

**WP3-08: Keyring fingerprint ambiguity**

Clarify the fingerprint algorithm, input representation and verification procedure in WP3c. Supply a tested example that does not disclose secret keys.

**WP3-09: Release-builder handling of modified deployed migrations**

Defer optional changes to `./flow release build`.

Implement the approved deployed-migration immutability test in WP3a and rely on normal validation gates. Revisit stronger build-time enforcement separately if justified.

**WP3-10: Upgrade-day Guardian authority**

Approve preparing a read-only production preflight query and documenting its interpretation.

It must distinguish exact stored role keys from collation-equivalent variants, identify Account status and Account-less holders, and report possible collision conditions.

Existing sessions may acquire the nine additional capabilities when the upgraded application resumes. The release checklist must state this explicitly.

Do not execute the preflight against production during WP3.

**WP3-11: Partial migration states**

Add a short operator decision table distinguishing:

- Failure during a schema migration.
- Refusal before a role rename.
- A committed rename not yet recorded by Laravel.
- A complete upgrade requiring restoration.

No automatic repair is authorized.

**WP3-12: Production collation uncertainty**

Include read-only collation inspection in the production preflight plan.

The local rehearsal does not establish the actual production database or column collations.

**WP3-13: Invitation reconciliation**

Correct overstated invitation risk while retaining post-restore reconciliation as a safety measure.

## Implementation sequencing

Retain the approved sequence:

**WP3a: Migration freeze and immutability**

Copy Guardian-role rename logic into its migration without changing semantics. Add exactness, collision, atomicity and deployed-migration immutability regressions.

**WP3b: Historical upgrade tests**

Apply the 15 deployed migrations, seed synthetic historical records, apply the remaining 14, and verify exact preservation and expected capability changes on MariaDB and PostgreSQL.

**WP3c: Local recovery rehearsal and documentation**

Use a strictly isolated local environment to test backup, upgrade, in-place rollback, restored-schema equality, subsequent re-upgrade, MFA recovery, Resource files and partial migration failures.

Correct the documented operator procedures.

Each work unit requires its own implementation review and acceptance. Approval of this triage does not authorize beginning all three simultaneously.

## Production boundaries

The following remain explicitly unapproved:

- Automatic production rollback.
- Production deployment.
- Running database migrations on production.
- Destructive production restoration.
- Executing preflight queries against the production host.
- Applying the nine-capability Guardian expansion to current production holders.
- Choosing between a host-level rehearsal and explicit production risk acceptance.

These remain separate release-readiness decisions.

**G6 WP3 TRIAGE: APPROVED**

**WP3a: AUTHORIZED TO IMPLEMENT**

**WP3b/WP3c: PLANNED, NOT YET AUTHORIZED TO IMPLEMENT**

**PRODUCTION RELEASE: NOT AUTHORIZED**
