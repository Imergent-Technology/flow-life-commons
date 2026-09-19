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

## Answered by the Identity and Access design gate

- **Table naming:** unprefixed. Module ownership is recorded in the [module map](module-map.md) and the module's own documentation, not encoded in table names.
- **Cross-module foreign keys:** permitted, deliberately, where the reference is a fundamental invariant — with `RESTRICT`, never `CASCADE`, and never for provenance or audit references. Write ownership, code dependency and referential integrity are three independent concerns ([ADR 0021](../adr/0021-cross-module-referential-integrity.md)).
- **Where the audit trail lives:** a small `Audit` module owning an append-only `security_events` table with no foreign keys, so it outlives its subjects and never blocks an operation ([ADR 0019](../adr/0019-security-event-auditing-seam.md)).

## Still open

- Soft deletes, retention and anonymisation policy for personal data. Anonymisation is designed as acting on the Person while preserving referential history, but the policy itself is undecided.
- How the audit trail is protected from tampering by the modules it audits; append-only is currently a code convention, since triggers are barred by the portability rule.
