# ADR 0017: Capabilities and roles are defined in code; only assignments persist

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0008](0008-platform-owned-authorization.md), [ADR 0009](0009-authorization-separate-from-approval.md)

## Context

Authorization must grow well beyond a small CRUD application — Guardians with differing administrative reach, volunteers with narrow access, capabilities that follow membership status — without becoming a generalised policy engine we cannot reason about.

The usual reflex is a `permissions` table, a `roles` table and a join table. That puts authorization in data, where a permission row nothing checks is meaningless, a code reference to a missing row is a silent bug, and the whole set drifts from the code that depends on it.

A placement constraint applies. The repository's architecture tests permit a module to import only another module's `Application` layer, and forbid a module's `Domain` from importing its own `Application`. Since every module must be able to name capabilities, capabilities must live in `Access\Application` — and therefore so must everything that consumes them. This was verified against the actual guardrail: an `Authorizer` placed in `Access\Domain` consuming a `Capability` from `Access\Application` **fails** the test suite.

## Decision

- **Capabilities are a PHP enum** in `Access\Application` — the module's public API.
- **Roles are a PHP enum** in the same layer, and **each role owns its capability bundle** as a method on the enum. This is the single place the `role → capabilities` mapping exists.
- **Only assignments are persisted**: `role_assignments(person_id, role_key, granted_by_account_id, granted_at)`. There is **no `roles` table and no `role_capabilities` table.**
- **Assignment is to the Person**, not the Account, because responsibility is human-level and must survive credential changes ([ADR 0015](0015-identity-owns-person.md)).
- **A row's existence means the grant is active.** Revocation deletes the row; history lives in the audit trail ([ADR 0019](0019-security-event-auditing-seam.md)).
- **`platform_administrator` resolves to every capability**, deliberately and as the only such role. There is no `is_admin` boolean anywhere.
- **The Authorizer lives in `Access\Application`**, reads assignments, maps each `role_key` through the enum, unions the capabilities and tests membership.
- **Laravel Gates and Policies are the enforcement edge only** and delegate to the Authorizer. Framework constructs must not become the domain model.
- **Business code asks for capabilities, never role names.** `if (role === 'guardian')` is a defect.

A `roles` table was considered and each justification tested: *stable identity* — nothing external references roles yet; *display metadata* — the enum supplies it; *assignment integrity* — an enum cast makes an invalid key unrepresentable, which is stronger than a foreign key; *future migration path* — adding a table later with a nullable `role_id` beside `role_key` is additive. None of them earn the table today, and a table mirroring code would be a non-authoritative copy of it, which the charter forbids for caches and which applies equally here.

## Consequences

- Capability and role names are typo-proof, appear in diffs, and are reviewable and testable.
- A `role_key` whose enum case has been removed resolves to nothing and grants **nothing** — fail-safe by construction. A scheduled integrity check can report such orphaned rows; it must never grant on them.
- Deleting a row cannot accidentally grant access, unlike a `revoked_at IS NULL` scheme where a forgotten filter widens access.
- **Changing what a role can do requires a deploy.** Accepted for now; runtime-editable roles are deferred, with the additive path above.
- Roles carry no scope. Scoped assignment (Guardian *of a particular programme*) is deferred; adding nullable scope columns later defaults existing rows to global, which is the correct reading.
- Authorization stays separate from approval ([ADR 0009](0009-authorization-separate-from-approval.md)): a board approving somebody is a workflow fact, and the resulting role assignment is a separate, audited administrative act.

## Alternatives considered

- **Database-backed permissions and role composition:** the conventional design, rejected because it relocates authorization into data that drifts from code, and buys runtime flexibility nobody has asked for.
- **A third-party permission package:** would impose exactly that database model, plus a dependency in the most security-sensitive part of the system.
- **Capabilities in `Access\Domain`:** forbidden by the module boundary rules — other modules could not import them.
- **Roles as free-text strings without an enum:** no validation, no discoverability, and typos become silent denials.
