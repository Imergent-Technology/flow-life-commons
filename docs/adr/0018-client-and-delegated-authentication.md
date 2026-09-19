# ADR 0018: Client and delegated authentication keep WordPress non-authoritative

- **Status:** Accepted (direction; nothing implemented)
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

The WordPress companion will eventually call the platform API, and later so will integrations and scheduled automation. Two conceptually different relationships hide behind "WordPress calls the API":

1. WordPress acting **as an application** — fetching published programme listings, syncing projections.
2. WordPress acting **on behalf of a signed-in human** — a member viewing their own membership.

These are not the same trust relationship, and conflating them is how a presentation surface quietly becomes an identity provider. WordPress is explicitly outside our security perimeter ([ADR 0004](0004-wordpress-adapter-not-authority.md)).

Nothing here is built during the first Identity epic. The decision is recorded now because it constrains today's model.

## Decision

- **Machine clients are separate from humans.** An `ApiClient` is its own record with its own credential and lifecycle (rotation, revocation). It is not an Account, has no Person, and **cannot hold roles**; it receives an explicit, narrow capability list.
- **A service client's capabilities must not include person-scoped ones.** A machine acting alone can never reach an individual's data.
- **Delegated requests require two independent proofs**: the client credential, *and* a subject credential **the platform itself issued** for that person.
- **The platform never accepts a client-asserted identity.** A `person_id`, `user_id` or WordPress-signed claim in a request is **not** proof of identity, ever. This single rule is what keeps WordPress non-authoritative.
- **The platform is the identity provider** in the delegated flow; the person authenticates to the platform, not to WordPress on the platform's behalf.
- The shared concept between humans and machines is the per-request **Actor**, a value object — not a shared table ([ADR 0015](0015-identity-owns-person.md)).
- Browser CORS policy is irrelevant here: service-to-service calls are not browser requests ([ADR 0016](0016-guardian-console-same-origin-session-authentication.md)).

## Consequences

- A total WordPress compromise yields the attacker only what the service client itself may do, which by construction excludes acting as any person.
- Member-facing WordPress functionality cannot be delivered by trusting WordPress sessions; it requires the delegated flow to be built first. That cost is accepted deliberately.
- A person with no platform Account cannot be acted for at all until an account-claim flow exists.
- Separate tables and lifecycles for clients and humans mean slightly more to build than one "principals" table, in exchange for making a whole class of confused-deputy bug unrepresentable.

## Alternatives considered

- **Trust a signed identity assertion from WordPress:** simplest, and rejected — it makes WordPress an identity provider, so its compromise becomes impersonation of any member, contradicting ADR 0004.
- **One principals table for humans and machines:** invites a machine to satisfy a human-only check, and forces nullable person references throughout.
- **Giving the companion a shared high-privilege credential:** a single leaked secret would expose everything; capability-scoped clients bound the damage.
