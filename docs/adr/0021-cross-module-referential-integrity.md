# ADR 0021: Cross-module referential integrity

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

[Data ownership](../architecture/data-ownership.md) left an open question: whether cross-module relationships may use database foreign keys, or must be application-enforced identifier references.

The question first arises with Identity and Access, but the answer is platform-wide and should not be settled as a side effect of one epic.

Three concerns are routinely conflated, and separating them resolves most of the argument:

1. **Write ownership** — which module may modify a table.
2. **Code dependency** — which module's classes may reference which.
3. **Referential integrity** — whether the database enforces that a reference points at something real.

A foreign key is not a code dependency. We run a modular monolith on a single relational database ([ADR 0001](0001-modular-monolith.md)); declining to use its integrity guarantees would trade a real, present safety property for a hypothetical future service split we have explicitly not planned.

## Decision

The three concerns are independent and governed separately.

1. **Write ownership is absolute.** Only the owning module writes its tables. Cross-module collaboration goes through the owning module's `Application` layer. Unchanged.
2. **Code dependency is unchanged** and remains enforced by the architecture tests: a module may import only another module's `Application` layer.
3. **Referential integrity is decided per relationship:**
   - A **cross-module foreign key is permitted** where the reference is a **fundamental invariant** — where a dangling value would be a defect rather than merely untidy.
   - Such keys use **`RESTRICT`/`NO ACTION`**. **`CASCADE` across module boundaries is forbidden**: one module must never silently delete another module's state.
   - **Provenance and audit references carry no foreign key.** They record what happened and must neither block an operation nor be broken by one.

Worked examples, which define the rule as much as the wording does:

| Reference | Decision | Reasoning |
|---|---|---|
| `access.role_assignments.person_id → identity.people.id` | **FK, `RESTRICT`** | An authorization grant pointing at a non-existent person is a dangling privilege, not untidiness. `RESTRICT` forces explicit, audited revocation before a Person can be removed |
| `access.role_assignments.granted_by_account_id` | **No FK** | Provenance. The authoritative record is the audit trail |
| `audit.security_events.*` | **No FK, ever** | Audit must outlive its subjects and must never block an operation |
| `identity.accounts.person_id → identity.people.id` | **FK, `RESTRICT`** | Within a module; an Account without a Person is meaningless |

Consequence for migrations: a module whose tables are referenced must migrate first. Identity's migration timestamps therefore precede Access's.

## Consequences

- The database enforces the invariants that matter, so a class of orphaned-reference bug is unrepresentable rather than merely discouraged.
- Deleting a referenced row requires explicit cleanup first. This is intended: it turns silent cascading loss into a deliberate, auditable act.
- Migration ordering becomes a real constraint between modules, and a new module referencing an existing one must respect it.
- Extracting a module into a separate database later would require dropping these keys and reintroducing application-level checks. Accepted knowingly: we are not planning that split, and ADR 0001 exists precisely because we chose not to.
- Each new cross-module reference needs a judgement — invariant or provenance? The worked examples above are the reference for that call.

## Alternatives considered

- **No cross-module foreign keys at all:** the original proposal in the Identity design. Rejected: it optimises for a hypothetical microservice split, discards real integrity guarantees, and would leave dangling privilege rows merely "discouraged".
- **Foreign keys everywhere, including audit:** would let referential integrity block or corrupt the audit trail, and make deleting a subject impossible without destroying its history.
- **`CASCADE` across modules:** convenient, and precisely the violation of write ownership this ADR exists to prevent.
