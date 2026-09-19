# Data ownership

## Principles

1. **The platform database is the system of record** for organizational data. Nothing of record lives in WordPress tables, options or user meta, in browser storage, or in caches.
2. **A module owns its tables.** Only that module's code writes to them. Other modules read through the owning module's `Application` layer, not by querying its tables or models directly (see [module map](module-map.md)).
3. **Identifiers** for platform aggregates are application-generated ULIDs ([ADR 0006](../adr/0006-ulid-identifiers.md)); they are created in code, not by the database, so they can be known before insert and behave the same on every engine.
4. **Timestamps** are persisted in UTC. Presentation converts to a local zone at the edge.
5. **Caches are never authoritative.** Anything in a cache must be reconstructible from the database, and correctness must not depend on a cache hit.
6. **Schema stays portable** between MariaDB and PostgreSQL ([ADR 0005](../adr/0005-mariadb-with-postgresql-portability.md)): no database enums, triggers, stored procedures, proprietary SQL or generated columns without an ADR.

## External copies

Data mirrored to other systems (for example a WordPress user display name) is a **projection** of platform data. The platform wins on any disagreement, and projections must be rebuildable from the platform. External identities are *linked to* platform identities, never the other way round.

## Current state

The only tables are framework infrastructure created by Laravel's stock migrations: `migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. They keep Laravel's default keys because they are not platform aggregates. There is deliberately no `users` table: identity is designed in its own epic ([authorization model](../security/authorization-model.md)).

## Open questions (decide with the first real module)

- Table naming: per-module prefix, or unprefixed names with module ownership recorded in docs?
- Whether cross-module foreign keys are allowed at the database level or references are by ULID only.
- Soft deletes and retention policy for personal data.
- Where the audit trail lives and how it is protected from the modules it audits.
