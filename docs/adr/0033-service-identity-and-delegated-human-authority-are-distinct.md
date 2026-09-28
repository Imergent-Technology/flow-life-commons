# ADR 0033: Service identity and delegated human authority are distinct

- **Status:** Accepted (direction; nothing implemented)
- **Date:** 2026-09-28
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0018](0018-client-and-delegated-authentication.md) (the invariants below are unchanged; one stated consequence is corrected, and implementation timing is settled)
- **Related:** [ADR 0032](0032-members-use-a-commons-hosted-surface.md) (why neither mechanism is needed yet)

## Context

ADR 0018 (2026-09-19) already separated "WordPress acting as an application" from "WordPress acting on behalf of a signed-in human", and settled the invariant that keeps WordPress non-authoritative: the platform never accepts a client-asserted identity, and a delegated request needs two independent proofs. Nothing under it has been built; the WordPress companion remains a skeleton (`apps/wordpress-companion`), and the current design gate (2026-09-28, [ADR 0032](0032-members-use-a-commons-hosted-surface.md)) puts the member surface on Commons itself, which removes the near-term reason to build either mechanism.

Two things need settling now, while the code doesn't exist, so a later implementer inherits the right shape rather than the wrong one:

1. **ADR 0018's Consequences overclaims.** It states a total WordPress compromise "yields the attacker only what the service client itself may do, which by construction excludes acting as any person." That is true of service authentication alone. It stops being true the moment a delegated-human mechanism exists and is in use: a compromised WordPress would then also hold whatever live delegated authority is currently issued to it. The invariant that matters — WordPress can never *mint* or *assert* authority for a person it does not currently, validly hold delegated authority for — survives; the blast-radius claim does not, and should not be repeated uncorrected.
2. **Nothing has decided how far delegation should look like standards-based OAuth versus something invented for this codebase.** Leaving that open risks two failure modes symmetrically: over-specifying now (freezing a token TTL, a storage schema, exact endpoint paths) before any real consumer exists to validate the choices against, or under-specifying later (an implementer inventing a bespoke handoff because nothing said not to).

## Decision

**Service authentication and delegated human authority remain two distinct mechanisms, proving two different things, and neither is implemented until a concrete consumer needs it.**

- **Service authentication proves *which client* is calling** — "this request came from the WordPress companion" — and nothing about any human. A service client is its own record, with its own credential and lifecycle, holds no person-scoped capability, and cannot hold a role (ADR 0018, unchanged).
- **Delegated human authority proves *which human*, acting through *which client*, with *what authority*** — three facts, not one. It requires the client's own proof and a subject credential the platform itself issued for that specific person; a client-asserted `person_id`, `user_id` or signed claim is never accepted as identity, ever (ADR 0018, unchanged).
- **A service-scoped credential can never silently become person-scoped.** There is no path by which holding a valid client credential alone lets a request act as any person; the two proofs are independent, and a design that lets one substitute for the other reopens the confused-deputy problem ADR 0018 exists to close.
- **Delegation is deferred, not designed away, and its trigger is stated precisely:** build it only when person-specific Commons data must genuinely render *inside* a WordPress-served page or experience. Linking from WordPress to a Commons-hosted page (ADR 0032) is the default for every case that does not require that. A public, non-person dataset (published programme listings, syncable content) needs at most service authentication, not delegation, and is its own, cheaper, separately-triggered piece of work.
- **The compromise consequence is corrected:** a fully compromised WordPress, under a *service-only* integration, is bounded exactly as ADR 0018 says — no path to acting as any person. Under a *delegated* integration, once built and in use, a full compromise additionally exposes whatever delegated authority is currently live in it — bounded by that authority's scope and remaining lifetime, not eliminated by the architecture. Keeping that authority short-lived and narrow is therefore a load-bearing property of the design, not a nicety.
- **When delegation is built, it follows standards-based authorization-code semantics** rather than an invented handoff: Commons as the issuer and sole authority, an exact registered redirect URI, PKCE, a single-use authorization code, the client authenticating itself where the flow calls for it, the resulting delegated authority short-lived and narrowly scoped to named capabilities, bound to the issuing client, revocable, and never reaching browser JavaScript on the WordPress side. WordPress can hold the resulting authority and present it; it can never mint it.
- **What is deliberately left open, until a real consumer exists to validate it against:** the exact token time-to-live, the exact storage or hashing representation, the exact endpoint paths and request/response shapes, and refresh behaviour beyond the one fixed principle — standing, indefinite delegated impersonation is forbidden; whatever refresh mechanism (if any) exists must not erase the "short-lived" property above. Freezing these now, with no consumer to validate them against, is exactly the speculative-surface mistake this codebase's own conventions warn against elsewhere (ADR 0017's capability rule, ADR 0031's mailer allowlist reasoning: build the check when the thing it checks exists).

## Consequences

- A future implementer has a stated shape to follow (authorization-code semantics, the properties above) without a frozen schema to fight when the real requirements turn out to differ in a specific.
- ADR 0018's own text is not rewritten — it is a dated record of its own moment — but is no longer the last word on the compromise consequence; anyone reading it should read this ADR alongside it.
- Until the trigger condition is met, no `api_clients` table, no client credential, no delegated-token store and no companion authentication code should be built. Building any of it speculatively would itself violate the pattern this ADR is restating.
- The next real decision in this area is not "how do we implement ADR 0018" but "what specific person-scoped data must render inside WordPress, and does that requirement survive being served from Commons and linked to instead" — which is a product question, not an architectural one, and is out of scope here.

## Alternatives considered

- **Rewrite ADR 0018's Consequences in place.** Rejected: ADRs in this repository are treated as dated, immutable-in-spirit records (see the ADR index's own convention of "Refined by"/"Clarified" pointers rather than silent edits); a refinement ADR that a reader is pointed to is preferred over quietly changing what an earlier decision said it concluded.
- **Freeze the delegated-token schema and TTL now, so implementation can start immediately when triggered.** Rejected: no real consumer exists to validate the choices against, and this codebase has an established, explicit aversion to speculative surface (ADR 0017, restated in ADR 0031) that this would contradict.
- **Invent a bespoke WordPress-to-Commons handoff instead of standards-based semantics.** Rejected: a proprietary protocol for exactly the problem OAuth's authorization-code grant already solves would cost more to get right and to audit, for no benefit specific to this platform.
- **Treat service authentication as sufficient groundwork for delegation, and build it now regardless of trigger.** Rejected: nothing currently needs even service authentication (ADR 0032 removes the near-term reason), and building it unused is exactly the "nothing on spec" pattern this ADR argues against.
