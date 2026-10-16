# G6 WP2: Owner Triage Decisions

**Milestone:** G6 Core Audit / Stabilization  
**Package:** WP2 Authorization, Sourced Grants and Disclosure  
**Audit baseline:** `18d059ec5d98f9c96147c96dea98fe754ff9db67`  
**Decision:** Authorize bounded remediation and security hardening.

## Executive decision

Accept the independent Opus audit's overall assessment: zero BLOCKER, zero HIGH, zero MEDIUM and six LOW findings.

The existing authorization architecture is substantially sound. No present route authentication bypass or unauthorized Resources disclosure was demonstrated.

Preserve the independent audit unchanged in `docs/audits/g6/wp2-audit.md`.

Record these decisions separately in `docs/audits/g6/wp2-triage.md`.

### WP2-01: LOW, Missing global route-authentication guard

**Disposition: FIX. Required G6 deliverable.**

Implement a preventive test covering every registered application route, not only `/admin`.

Use an explicit allowlist for the ten legitimately unauthenticated routes identified by the audit, distinguishing stateless public endpoints, pre-authentication session endpoints, idempotent logout and framework liveness.

Require authentication on every other route.

Account for resolved middleware, route groups, middleware exclusions and routes registered outside `/api/v1`.

Connect the allowlist to OpenAPI authentication metadata without changing existing route behavior.

Demonstrate that the guard detects deliberately introduced violations, including missing authentication, inappropriate session middleware, stale allowlist entries and unauthorized public routes.

Do not introduce new production middleware unless tests reveal an actual enforcement gap.

**Blocking:** Required before G6 WP2 closeout.

### WP2-02: LOW, Missing human-role Console admission invariant

**Disposition: FIX. Required G6 deliverable.**

Add a catalog invariant establishing that every existing human role granting any capability also grants `console.access`.

Scope the rule explicitly to the existing human-role catalog.

Do not constrain the future service-identity model contemplated by ADR 0033.

Use positive controls and mutation tests to prove the guard catches a newly added or changed human role granting capabilities without Console admission.

**Blocking:** Required before G6 WP2 closeout.

### WP2-03: LOW, Sourced grants accept non-provisionable roles

**Disposition: FIX. K1 APPROVED WITH REFINEMENT.**

Enforce the relationship-sourced role allowlist on the read side, not only when grants are created.

Apply this restriction consistently in:

1. `Authorizer`, when resolving effective capabilities.
2. `PeopleHoldingCapability`, when calculating capability holders.

A sourced grant containing `platform_administrator`, `guardian-full`, or any other non-provisionable role must confer no capability through either path.

A legitimate provisionable sourced `guardian-initiate` grant must continue to work.

Independent role assignments must retain their full approved role catalog and must not be affected.

The current `ProvisionableRole` catalog is the authority for roles that a relationship source can confer.

Preserve the accepted treatment of orphaned but otherwise valid sourced grants. This decision does not authorize automatic orphan cleanup, relationship revalidation on every authorization check or a new role-management model.

An optional low-cost improvement to `relationships:check` may identify invalid sourced-role keys even when a relationship is missing, provided it requires no new public contract and does not perform repairs.

Tests must cover both authorization and holder counting, including mixed independent and sourced assignments.

Mutation evidence must show the new guards reject a planted non-provisionable sourced role.

### WP2-04: LOW, Incomplete public-route documentation

**Disposition: FIX.**

Correct `trust-boundaries.md` to list all intentionally unauthenticated endpoints and their relevant session/CSRF requirements.

Correct OpenAPI's `POST /logout` authentication declaration to reflect its deliberately idempotent, unauthenticated route behavior.

Preserve actual runtime behavior.

Coordinate these corrections with WP2-01's route-surface test.

### WP2-05: LOW, Corrupt Resource data causes availability failures

**Disposition: TEST, ACCEPT CURRENT FAIL-CLOSED BEHAVIOR, DEFER ROBUSTNESS CHANGES. K2 APPROVED.**

The independent audit demonstrated no unauthorized content disclosure on MariaDB or PostgreSQL.

Keep strict stored-value parsing.

Add targeted regressions showing that corrupt Pack and Card publication states and audience modes cannot result in unauthorized content or file delivery across Resources Policies A, B and C.

Do not make HTTP 500 a required contractual response. Tests should protect the security invariant while allowing future controlled fail-closed handling.

Do not introduce tolerant parsing, silent substitution of Draft states or new recovery/logging mechanisms in WP2.

Record availability concerns and revisit them as appropriate during later operational hardening.

### WP2-06: LOW, CRM authorization-before-lookup proof gap

**Disposition: FIX TESTS.**

Add targeted tests proving that an Actor without the relevant CRM capability receives an authorization refusal before the requested Person, interaction or tag is looked up, including when the identifier does not exist.

Preserve the existing authorization-first code behavior.

Use mutation evidence to demonstrate that reversing authorization and lookup order is detected.

Avoid redundant tests for every operation when a representative or parameterized test covers the relevant invariant.

## K3: Resources security events and step-up exemptions

**Disposition: DEFER.**

Retain the approved G6 step-up policy for Resources, CRM, Discussions and Guardian/Volunteer relationships.

Do not add new step-up requirements.

Do not introduce new security-event types for publishing, unpublishing, audience changes or file replacement in WP2.

Record the residual risk of immediate content publication, audience expansion and file replacement without recent verification or dedicated security events.

Revisit event coverage before G7 exposes additional Resources consumption or management interfaces.

Any future change to step-up requirements requires a separate owner decision under DP2.

## K4: Accepted residual organizational authority risks

**Disposition: ACKNOWLEDGE AND ACCEPT FOR G6.**

The owner acknowledges:

- Volunteer intake and status changes can affect audience eligibility without step-up, as previously designed.
- Authorized CRM managers can rename other Persons, including administrators. This changes presentation and attribution but not Account login credentials or privileges.
- Dormant relationship-sourced grants can become effective when an Account is legitimately established for their Person.
- Orphaned valid sourced grants remain effective until removed through an authorized operational procedure.
- Resource publication and file replacement can affect readers immediately under existing management authority.

These are accepted design properties or bounded operational risks, not authorization for new features.

Record relevant future review triggers, including G7 external integration and operational recovery planning.

### Other informational observations

- Correct stale Relationships route comments and ADR line references.
- Add a narrowly scoped file-response header comparison test if practical.
- Record the Discussions author-predicate hardening observation without changing the current repository write paths.
- Record the unproven Resources delivery-read concurrency hypothesis for targeted future investigation. Do not classify it as a demonstrated disclosure vulnerability without reproduction.
- Do not create a generic orphan-grant removal tool in WP2.

## Scope and execution

Authorized work:

1. Global route-authentication guard and role-catalog invariant.
2. Access sourced-grant read-side hardening.
3. Targeted Resources and CRM security regressions.
4. Public-route documentation and OpenAPI corrections.
5. Minimal related documentation and debt-register updates.
6. Audit and remediation evidence records.

Excluded:

- Role redesign.
- New service identities or delegated authentication.
- Changes to existing step-up policy.
- Resources tolerant parsing or recovery behavior.
- New security-event infrastructure.
- New orphan cleanup workflows.
- General G10 re-auditing.
- WP3, G7 or production deployment.

## Verification and integration

Use Sonnet 5.5 for bounded implementation.

Every new preventive guard requires explicit mutation evidence.

For the sourced-grant hardening, demonstrate the unauthorized privilege before correction and the fail-closed behavior afterward.

Validate backend changes on MariaDB and PostgreSQL with the full G6 quality gate.

Prepare an independent focused Opus verification, especially for sourced-grant interpretation and the new preventive guards.

Do not push, open a PR, merge or deploy until the owner reviews the implementation handback.

**G6 WP2 TRIAGE: APPROVED FOR REMEDIATION**

**PRODUCTION RELEASE: NOT AUTHORIZED**
