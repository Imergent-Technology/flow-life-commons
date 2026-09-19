# Authorization model

**Status: direction only.** No identity, authentication or authorization exists yet, on purpose. The design belongs to the Identity/Access epic ([ADR 0008](../adr/0008-platform-owned-authorization.md)). This page records the constraints that design must satisfy, so nothing built earlier has to be undone.

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

## Open design questions

- Authentication for people (password, magic link, SSO, MFA/step-up for Guardians) and for client applications (WordPress companion, service accounts).
- Session vs bearer-token strategy, and how it interacts with CORS and the hardened console.
- Role and permission model, and how organizational roles map to policies.
- Linking external identities (WordPress users) to platform identities.
- Which actions are "sensitive" and what each must record.
- Rate limiting and abuse controls.

Until those are decided: **do not add users, roles, guards or permission checks ad hoc.**
