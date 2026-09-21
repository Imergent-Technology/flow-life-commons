# Architecture Decision Records

An ADR records one significant decision: what we chose, why, and what we gave up. They are short, dated and immutable in spirit: to change a decision, write a new ADR that supersedes the old one and update both statuses.

## When to write one

Write an ADR when a decision is hard to reverse, constrains future work, or when someone will later ask "why did we do it this way?". Deviating from a rule in the [charter](../architecture/charter.md) (for example using a MariaDB-only feature) **requires** an ADR. Do not write ADRs for generic coding preferences; those belong in [coding standards](../development/coding-standards.md).

## Format

Copy [template.md](template.md). Number sequentially (`NNNN-short-title.md`). Statuses: `Proposed`, `Accepted`, `Superseded by ADR NNNN`, `Deprecated`.

## Index

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-modular-monolith.md) | Modular monolith | Accepted |
| [0002](0002-laravel-php-platform.md) | Laravel on PHP 8.3 for the platform | Accepted |
| [0003](0003-react-guardian-console.md) | React, TypeScript and Vite for the Guardian Console | Accepted |
| [0004](0004-wordpress-adapter-not-authority.md) | WordPress is an adapter, not an authority | Accepted |
| [0005](0005-mariadb-with-postgresql-portability.md) | MariaDB first, PostgreSQL portability required | Accepted |
| [0006](0006-ulid-identifiers.md) | Application-generated ULID identifiers | Accepted |
| [0007](0007-versioned-rest-api-openapi.md) | Versioned REST API with an OpenAPI contract | Accepted |
| [0008](0008-platform-owned-authorization.md) | Platform-owned authorization | Accepted |
| [0009](0009-authorization-separate-from-approval.md) | Authorization is separate from approval | Accepted |
| [0010](0010-database-queue-redis-ready.md) | Database queue first, Redis-ready | Accepted |
| [0011](0011-docker-compose-development-environment.md) | Docker Compose development environment and `./flow` | Accepted |
| [0012](0012-tailwind-4-via-vite.md) | Tailwind CSS 4 via the Vite plugin | Accepted |
| [0013](0013-monorepo.md) | Monorepo | Accepted |
| [0014](0014-testing-and-database-compatibility-strategy.md) | Testing and database compatibility strategy | Accepted |
| [0015](0015-identity-owns-person.md) | Identity owns Person; Person is not Account | Accepted |
| [0016](0016-guardian-console-same-origin-session-authentication.md) | Guardian Console authenticates via a same-origin, host-only session cookie | Accepted |
| [0017](0017-capabilities-and-roles-in-code.md) | Capabilities and roles are defined in code; only assignments persist | Accepted |
| [0018](0018-client-and-delegated-authentication.md) | Client and delegated authentication keep WordPress non-authoritative | Accepted (direction) |
| [0019](0019-security-event-auditing-seam.md) | Security event auditing seam | Accepted |
| [0020](0020-administrator-bootstrap-and-last-administrator-invariant.md) | Administrator bootstrap and the last-administrator invariant | Accepted |
| [0021](0021-cross-module-referential-integrity.md) | Cross-module referential integrity | Accepted |
| [0022](0022-password-policy-and-credential-handling.md) | Password policy and credential handling | Accepted |
| [0023](0023-multi-factor-authentication.md) | Multi-factor authentication for the Guardian Console | Accepted |
| [0024](0024-privileged-operator-administration.md) | Privileged operator administration | Accepted |
| [0025](0025-account-security-generation.md) | The Account security generation | Accepted |
| [0026](0026-production-browser-security-policy.md) | Production browser security policy | Accepted |

Domain events and the transactional outbox are a documented *direction* ([integration model](../architecture/integration-model.md)), not yet a decision: they get an ADR when the first real consumer shapes the design.

ADRs 0015–0021 record the Identity and Access design gate; ADR 0022 records the password policy and credential decisions made while implementing it, ADR 0024 the privileged operator administration that builds on them, and ADR 0023 the multi-factor authentication that the Console now requires. ADRs 0025 and 0026 come from the production-hardening phase: the first closes the last known stale-authentication window, the second states the browser security policy the production origin serves under. The consolidated design they refer to — vocabulary, module layout, schema, lifecycles and the scope of the first implementation epic — is in [architecture/identity-and-access.md](../architecture/identity-and-access.md). It is being implemented in phases; its *Implementation status* section records how far.

Some ADRs carry a **Refined by** or **Amended by** line. Those decisions remain Accepted and unchanged; the pointer records that a later ADR adds detail within the same direction, as distinct from superseding it. A **Clarified** line records that the *wording* of a decision was corrected in light of implementation, with the decision itself unchanged.
