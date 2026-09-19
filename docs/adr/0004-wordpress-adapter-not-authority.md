# ADR 0004: WordPress is an adapter, not an authority

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Member and volunteer functionality will initially be presented largely through WordPress, which the organization already uses. WordPress has its own users, roles, capabilities and data model, and a large plugin surface we do not control. If it becomes the place where organizational truth or access decisions live, the platform is locked to it and its security posture becomes ours.

## Decision

**WordPress is a presentation surface and adapter only.** A thin companion plugin (`apps/wordpress-companion`) renders platform data and forwards user actions to the platform's REST API.

- Identity, authorization, organizational roles, workflows and data of record live in the **platform**.
- WordPress users are *linked to* platform identities; WordPress roles and capabilities **must not** become the Flow Life authorization system. A WordPress capability check may shape presentation but never grants access.
- The platform must remain fully functional, and replaceable-front-end friendly, without WordPress.

## Consequences

- WordPress can be replaced or supplemented (another site, an app) without redesigning the core.
- Every WordPress-originated action pays the cost of an API round trip and server-side authorization. That is intended.
- Some duplication of presentation logic is accepted; duplication of *business rules* is not.
- The companion plugin must stay thin; logic creeping into it is a defect.
- Local WordPress is not in the Compose stack yet; it will arrive as an optional profile when the first real feature needs it.

## Alternatives considered

- **WordPress as the system of record (custom post types, user meta):** fastest start, but ties authorization and data to WordPress, weakens security and blocks replacement.
- **Map WordPress roles to platform permissions:** convenient, but makes a plugin-extensible role system a security dependency.
- **No WordPress at all:** removes the integration cost but discards the organization's existing web presence and content workflow.
