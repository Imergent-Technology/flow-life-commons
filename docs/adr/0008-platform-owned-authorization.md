# ADR 0008: Platform-owned authorization

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Several surfaces (WordPress, the Guardian Console, later others) will let people act on organizational data. If each surface decides access on its own, rules diverge, the weakest surface sets the security level, and replacing a surface means re-deriving who may do what.

## Decision

**Authorization is decided by the platform, server-side, on every request.** Identity relationships, organizational roles and permissions are platform data and platform code. UI visibility and client-side checks are presentation only and never security. WordPress roles/capabilities are never the source of authorization ([ADR 0004](0004-wordpress-adapter-not-authority.md)).

The **design** of identity and authorization (identities, external identity mapping, roles, policies, Guardian capabilities, authentication of clients) is deliberately **not made here**; it belongs to the Identity/Access epic. This ADR records the ownership principle and the constraint that nothing is built ahead of that design. Consequently the foundation contains no users table, no authentication and no roles.

## Consequences

- One place to reason about, test and audit access; new surfaces inherit it.
- Every endpoint added later must state its authorization, and public endpoints need a stated reason.
- Sensitive actions and access will require durable auditing ([authorization model](../security/authorization-model.md)).
- We avoid a false start: nothing built now will need to be unpicked when the real design lands.

## Alternatives considered

- **WordPress roles as the authority:** rejected in ADR 0004.
- **Per-client authorization:** duplicated rules, weakest-link security.
- **Design and build authorization now:** premature; it needs its own epic and domain input.
