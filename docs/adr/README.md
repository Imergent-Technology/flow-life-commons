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

Domain events and the transactional outbox are a documented *direction* ([integration model](../architecture/integration-model.md)), not yet a decision: they get an ADR when the first real consumer shapes the design.
