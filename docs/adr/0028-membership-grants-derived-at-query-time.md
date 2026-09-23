# ADR 0028: Membership access is time-bounded grants, derived at query time

- **Status:** Accepted
- **Date:** 2026-09-23
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0015](0015-identity-owns-person.md)
- **Clarified:** 2026-09-23, after the backend was built. The decision is unchanged. `membership_grants`, the `Membership` module (`Domain`, `Application`, `Infrastructure`), the temporal derivation, race-safe one-way revocation, `Identity\Application\RegisterPerson`, and the `membership.records.view`/`membership.records.manage` capabilities now exist and are covered by tests, including the MariaDB/PostgreSQL temporal matrix and a two-process concurrency proof of the conditional-update revocation. **Not built:** any HTTP/admin surface, the Guardian Console Membership UI, WordPress integration, and Zeffy/Luma automation.

## Context

Flow Life has members. Their records currently live in Luma — a handful of people, a transitional system — and the platform has no representation of membership at all. Identity, Access and Audit are implemented and in production ([identity and access](../architecture/identity-and-access.md)); no business module exists yet. Membership Foundation is the first.

Membership has one property that shapes everything below: **it lapses through the passage of time, with no mutation and no event.** A year-long membership that began on 1 March ends on 1 March whether or not anything happens in the system that day. Nobody clicks anything, no webhook arrives, no job need run. Any representation that stores "is a member" as a fact must therefore arrange to go and change that fact on a schedule, and is wrong in the window before it does.

Two shapes are the obvious reflexes, and both fail on that property:

- **A stored status** (`memberships.status = active|ended`) needs a sweep to move rows to `ended`. Until it runs, the database asserts something false. The sweep becomes load-bearing for correctness rather than for tidiness, on a host whose only scheduler is a cron tick ([ADR 0027](0027-release-and-deployment-model.md)).
- **A role assignment** (`role_assignments`, `role_key = 'member'`) is worse, and is the trap this ADR most exists to close. Role assignments are deliberately durable and have no expiry: a row's existence means the grant is active, and revocation deletes it ([ADR 0017](0017-capabilities-and-roles-in-code.md)). A membership expressed that way **silently outlives the membership term forever**, and the failure is invisible — the person keeps their access, and nothing anywhere records that they should not have it.

The platform also does not yet know most of its members as People. Membership must be able to attach to a human who has never signed in and may never sign in: members are not Console users ([ADR 0015](0015-identity-owns-person.md)).

## Decision

Membership access is represented as **time-bounded grants**, and whether a Person is a member **right now** is derived from those grants at query time. There is no stored membership status, and there is no expiry job.

### The grant

A new `Membership` module owns one table, `membership_grants`. A row records that Commons granted a Person membership access for a term:

| Column | Notes |
| --- | --- |
| `id` | ULID ([ADR 0006](0006-ulid-identifiers.md)) |
| `person_id` | The human the access belongs to. FK to `people.id`, `RESTRICT` |
| `starts_at` | Inclusive start of the term, UTC |
| `ends_at` | Exclusive end, UTC. **Nullable**: null means open-ended |
| `source` | Why Commons granted it — provenance ([ADR 0029](0029-commerce-providers-own-payment-facts.md)) |
| `source_reference` | Nullable, opaque provenance handle ([ADR 0029](0029-commerce-providers-own-payment-facts.md)) |
| `granted_by_account_id` | Nullable provenance: the operator who granted it. No FK |
| `revoked_at` | Nullable, UTC. Null means not revoked |
| `revoked_by_account_id` | Nullable provenance. No FK |
| `created_at` | UTC |

Referential integrity follows [ADR 0021](0021-cross-module-referential-integrity.md) exactly, and the two calls it asks for are made the same way `role_assignments` made them: `person_id` is a **fundamental invariant** and carries a cross-module foreign key with `RESTRICT`, because a membership grant pointing at a non-existent person is dangling entitlement rather than untidiness; the two `*_by_account_id` columns are **provenance** and carry no foreign key, so an Account can be removed without blocking or rewriting membership history. Membership's migrations must therefore run after Identity's.

There is no `updated_at`. The only mutation a grant ever undergoes is revocation, which stamps `revoked_at` and `revoked_by_account_id`; a generic modification timestamp would carry nothing the row does not already state.

### What may change after a grant is written

`person_id`, `starts_at`, `ends_at`, `source`, `source_reference`, `granted_by_account_id` and `created_at` **never change**. A grant is **never deleted**. **Revocation is the only mutation**: `revoked_at` and `revoked_by_account_id` are set together, exactly once, and a revoked grant can never become unrevoked.

This is a **non-deleting grant history with explicit one-way revocation**. It is deliberately not described as an immutable or append-only table, because one column pair does change and the wording should not have to be walked back the first time someone reads the revocation path.

One-way revocation is enforced by a conditional update rather than by a read-then-write, so concurrent revocations cannot both succeed:

```sql
UPDATE membership_grants
   SET revoked_at = ?, revoked_by_account_id = ?
 WHERE id = ? AND revoked_at IS NULL
```

An affected-row count of zero means the grant was already revoked, and the caller treats that as the outcome rather than as a failure to retry.

### Derivation

Intervals are **half-open**, `[starts_at, ends_at)`, and all instants are UTC. Half-open is what makes a renewal that begins exactly when the previous term ends contain no gap and no double-counted instant.

A Person has **active membership access at instant T** if and only if at least one grant exists where:

```
revoked_at IS NULL
AND starts_at <= T
AND (ends_at IS NULL OR T < ends_at)
```

That predicate is the whole definition. It reads current persisted state, needs no maintenance, and is correct at every instant including the one the term expires.

**Access-through** — the date the current membership actually runs to — is derived, not stored:

1. Take the Person's non-revoked grant intervals.
2. Merge intervals that overlap **and** intervals that exactly touch (one's `ends_at` equals the next's `starts_at`).
3. Find the merged interval containing T. Its end is `current_access_ends_at`.
4. If any grant in that continuous run is open-ended, current access is **open-ended**.

**Open-ended grants (`ends_at = NULL`) are approved** for honorary, founding, legacy, goodwill and other genuinely indefinite access. When the operator surface is built, an open-ended grant must be an **explicit operator choice** and must never be what happens when the end date is left blank.

**"Member since" is not the earliest grant start.** A person may hold grant A, then a gap, then grant B; the date they first became a member and the date their current continuous coverage began are two different facts, and conflating them silently overstates continuity. Phase 1 derives only what it needs — current active/inactive, current access-through or open-ended, and the full grant history — and the data supports the other questions later without a schema change.

### Temporal membership is never mirrored into a role

**Temporal membership eligibility must never be represented solely by a durable role assignment.** This is the anti-regression rule this ADR most needs to outlive its authors: a role assignment does not expire, so it would keep granting access after the term it stands for has lapsed, and nothing would report the discrepancy.

This is **not** a rule that Commons may never have member, volunteer, partner or moderator roles, or capability bundles serving member experiences. Those remain entirely available. The rule is narrower and permanent: **a durable role assignment must never be the sole source of truth for eligibility that expires by the passage of time.**

Phase 1 has no member-facing authorization consumer, so no mechanism is designed for this yet and none should be. When a real member-facing authorization surface is built, membership-derived authority must be evaluated from **current persisted grant state** through an inversion port defined by `Access` and implemented by `Membership`, so that the dependency direction stays acyclic and Access still does not depend on a business module.

### When a `memberships` relationship row appears

Not now. The explicit trigger is **the first non-temporal administrative state that cannot honestly be derived from grants** — *suspended* despite otherwise-valid paid access, or *terminated/barred* despite otherwise-valid paid access.

Phase 1 accepts the resulting limitation openly: revoking a grant conflates "this was a correction" with "this membership was administratively terminated". At the current scale, with a handful of members and an operator present for every change, that conflation is cheap. When the distinction becomes real, a `memberships` relationship aggregate can be added **additively**, above the grants, without rewriting them.

### Person and Account

Person remains the canonical human anchor; Account remains sign-in identity ([ADR 0015](0015-identity-owns-person.md)). Four invariants hold:

- A Person may exist with no Account.
- A Person may hold membership grants with no Account.
- An Account may exist for a Person with no membership.
- At most one Account per Person, unchanged.

Membership grants attach to the **Person**, which is what ADR 0015 already anticipated when it placed business relationships there.

Creating a Person who has no Account needs a use case that does not exist: today the only path that creates a Person is `Identity\Application\InviteAccount`, which necessarily creates an Account too. **`RegisterPerson` is therefore added to `Identity`, not to `Membership`** — Identity remains the sole owner of Person persistence, so ADR 0015's extraction trigger ("a second module needs to create People independently of Identity") is deliberately **not** tripped.

Membership may orchestrate *register a Person and grant membership* inside **one database transaction**. The precedent is already in the codebase and needs no new machinery: `Access\Application\BootstrapAdministrator` opens a transaction and calls `Identity\Application\InviteAccount` within it, relying on the framework's nested-transaction/savepoint semantics. No distributed transaction and no compensation design is required.

### Capabilities

Phase 1 defines two capabilities, named here so the surface is decided before it is built:

| Capability | Means |
| --- | --- |
| `membership.records.view` | List and inspect membership state and grant history |
| `membership.records.manage` | Register a Person for membership purposes, and create or revoke membership access grants |

**They are not added to the catalog by this ADR.** `Capability` is code-owned and a capability is added when the functionality that checks it exists, never speculatively ([ADR 0017](0017-capabilities-and-roles-in-code.md)); adding them now would create exactly the speculative entry that rule forbids.

**No role is added in Phase 1.** `platform_administrator` resolves to `Capability::cases()` and so acquires both capabilities automatically when they are defined. `guardian` is unchanged and holds neither.

### Module boundary

`Membership` owns `membership_grants`, the temporal derivation, and the grant/revoke/query use cases.

Its **direct** dependencies in Phase 1 are `Access\Application` (authorization), `Identity\Application` (Person operations and existence) and `Shared`:

```
Membership\Application ──► Access\Application, Identity\Application, Shared
```

**Membership has no direct `Audit` dependency.** Access and Identity each depend on Audit, but that is *their* dependency and is not inherited or implied by calling them. Writing the chain as `Membership → Access → Identity → Audit → Shared` would suggest otherwise and should not be written that way.

Nothing outside Membership may query `membership_grants` or use `Membership\Domain`. Membership must not query `people`, `accounts` or `role_assignments` directly, must not own Person persistence, and must not name role keys — the same rules every module already works under ([module map](../architecture/module-map.md)).

### History and auditing

The **grant row is the business history**: term, provenance, who granted it, when it was created, whether and when it was revoked, and by whom.

- **`security_events` is not widened** to carry ordinary membership grants and revocations. ADR 0019 built that table as the *security* event seam and says plainly that the full application audit architecture is a later concern; routine business records are not what it is for.
- **No second Membership history table is created either.** The grant row already holds what Phase 1 needs, and a history table beside it would duplicate that with no consumer.
- **No free-text revocation reason.** A prose field invites operational narrative into a schema that cannot validate, query or retain it responsibly.
- **`RegisterPerson` emits no security event merely because a Person was created.** Creating a contact record is not a security-relevant act; Identity's existing events continue to cover the credential and account lifecycle.

A future general business audit/history design may add richer history. It is not designed here.

## Consequences

- **The expiry question disappears.** There is no sweep, no status drift, and no window in which the database asserts a membership that has lapsed. Correctness stops depending on a cron tick having run.
- **Every membership answer is a query, not a lookup.** Derivation runs per request, on a handful of rows per person, and can be cached later if it ever matters. At the current scale it does not.
- **The history is complete by construction.** Because nothing is deleted or edited, "what did Commons believe on this date, and who decided it?" is answerable from the table itself.
- **Reconciliation is honest work, not an import.** Legacy Luma members are brought across by an operator creating a Person where needed and a backdated grant with `source = luma_legacy`; backdating is a first-class capability of the model rather than a workaround.
- **Revoking is blunt in Phase 1.** Correction and administrative termination look identical in the data. Accepted, with the trigger for fixing it written down above.
- **The `memberships` row is deferred, not designed away.** The additive path is stated so that adding it later is a decision rather than a discovery.
- **A future member-facing authorization surface has an unavoidable obligation:** it must read current grant state. The anti-regression rule above is the whole reason this ADR is worth its length.

## Alternatives considered

- **A `memberships` row with a stored `status`, swept by a job.** The reflex design. Rejected: it makes a scheduled task load-bearing for correctness, it is wrong between sweeps, and on this host the only scheduler is a cron tick. It also answers "is this person a member?" with a cached opinion rather than with the facts.
- **A `member` role assignment.** Rejected emphatically, and the reason is recorded above as a permanent rule: `role_assignments` rows never expire, so the grant would outlive the term silently and invisibly. Roles remain available for other purposes; they are simply not a temporal mechanism.
- **A single mutable membership row updated on renewal** (moving `ends_at` forward). Rejected: it destroys the history of what was granted and why, makes a refund or correction unrepresentable, and turns every renewal into a lossy write.
- **Deleting grants instead of revoking them.** Rejected: revocation is exactly the event most worth keeping. A deleted row cannot answer why access ended.
- **Storing `current_access_ends_at` as a maintained column.** Rejected for the same reason as the stored status: it is a derived fact that must be kept true, and would need recomputing on every write and on every expiry.
- **Putting `RegisterPerson` in Membership.** Rejected: it would make a second module the creator of People, splitting ownership of the human registry and tripping ADR 0015's extraction trigger for no benefit. The use case is Identity's, and is reusable by CRM and every later business module.
- **A free-text reason on revocation.** Rejected for Phase 1: unvalidatable prose in a schema with no retention policy, and no consumer asking for it.
