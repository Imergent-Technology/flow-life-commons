# Authorization model

**Status: designed, not implemented.** The design gate is complete and recorded in [ADRs 0015–0021](../adr/README.md), with the operative reference in [architecture/identity-and-access.md](../architecture/identity-and-access.md). No identity, authentication or authorization code exists yet. This page keeps the principles that design must continue to satisfy.

## Principles

1. **The platform decides.** Identity relationships, organizational roles and permissions are platform data, checked by platform code on every request. Any surface (WordPress, the Guardian Console, a future app) is a client.
2. **Server-side always.** UI visibility is never security. Hiding a button protects nothing.
3. **WordPress roles/capabilities are not the model.** They may shape presentation; they never grant access ([ADR 0004](../adr/0004-wordpress-adapter-not-authority.md), [WordPress integration](../integrations/wordpress.md)).
4. **Authorization is not approval.** Whether an actor *may attempt* an action is separate from whether a specific item has been *signed off* ([ADR 0009](../adr/0009-authorization-separate-from-approval.md)). Neither model encodes the other.
5. **Deny by default.** Endpoints are authenticated and authorized unless explicitly, and with reason, public.
6. **Least privilege for Guardians.** Guardian operational capability is granted through the same model, not a bypass; the Console is a client.
7. **Acting person vs calling client.** A request from WordPress carries both the client's identity and the person on whose behalf it acts; the platform authorizes the person.

## Auditing (future requirement)

Sensitive actions and privileged access will require **durable auditing**: who did what, to what, when, from where, under which authority, and with what outcome. Audit records must be append-only from the audited modules' point of view, survive deployments, and be reviewable by Guardians. Likely a dedicated module fed by the events/outbox direction ([integration model](../architecture/integration-model.md)). Designed in the Identity/Access epic; no auditing exists yet.

## Decided in the design gate

| Question | Answer | Where |
| --- | --- | --- |
| Human identity model | Person (canonical human) is separate from Account (sign-in); email is mutable, `email_canonical` is the lookup key | [ADR 0015](../adr/0015-identity-owns-person.md) |
| Console authentication | Same-origin, host-only `__Host-` session cookie with CSRF; database sessions; no bearer token in the browser | [ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md) |
| Role and permission model | Capabilities and roles defined in code; only assignments persist; administration is a system role | [ADR 0017](../adr/0017-capabilities-and-roles-in-code.md) |
| Client and delegated access | Clients authenticate as themselves with narrow, non-person-scoped capabilities; person identity must come from a platform-issued credential | [ADR 0018](../adr/0018-client-and-delegated-authentication.md) |
| Which actions are auditable | A defined minimum event set, recorded synchronously from the first epic | [ADR 0019](../adr/0019-security-event-auditing-seam.md) |
| First administrator and lockout | Console command rooted in server access; last-administrator invariant enforced by a guard chain Identity owns | [ADR 0020](../adr/0020-administrator-bootstrap-and-last-administrator-invariant.md) |
| Rate limiting | Laravel's limiter on login, reset and invitation acceptance, keyed by IP *and* identifier | [identity-and-access.md](../architecture/identity-and-access.md) |

## Still open

- **MFA / step-up for privileged accounts.** Deliberately outside the first epic, and intended as an early security follow-up before privileged access expands substantially.
- **External identity providers** and the linking flows.
- **Scoped access** (for example Guardian *of a particular programme*).
- **Anonymisation and deletion** of identity data.

Until the epic ships: **do not add accounts, roles, guards or permission checks ad hoc.** After it ships, add them the way the design says, or amend the design by ADR.
