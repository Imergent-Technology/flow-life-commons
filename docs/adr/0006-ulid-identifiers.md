# ADR 0006: Application-generated ULID identifiers

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Platform aggregates will be referenced from WordPress, the Guardian Console, integrations, audit records and events, and may move between database engines ([ADR 0005](0005-mariadb-with-postgresql-portability.md)). Auto-increment integers leak volume and ordering, are enumerable, differ between engines, and cannot be known before insert (awkward for events and the outbox). Random UUIDv4 keys fragment indexes.

## Decision

Platform **aggregate identifiers are application-generated ULIDs** (26-character, lexicographically time-sortable, stored as fixed-width strings), unless a future ADR establishes a reason otherwise. Identifiers are created in application code before persistence. Laravel's `HasUlids` support is the expected mechanism.

This is direction for future aggregates. **No aggregates exist yet**, so nothing demonstrates it in code. Framework-owned infrastructure tables (cache, jobs, migrations) keep Laravel's defaults because they are not platform aggregates.

## Consequences

- IDs can be assigned before insert, which suits events, the outbox and idempotency keys.
- Same representation on MariaDB and PostgreSQL; safe to expose in URLs without revealing counts.
- Time-ordered, so index locality is much better than random UUIDs.
- Larger keys than integers (26 bytes as `CHAR(26)`); acceptable at our scale.
- Creation time is embedded in the ID; do not treat IDs as secrets or capabilities.

## Alternatives considered

- **Auto-increment BIGINT:** compact and fast, but enumerable, engine-specific behaviour, and unknown until insert.
- **UUIDv4:** unordered, poor index locality.
- **UUIDv7:** also time-ordered and standardised, a reasonable alternative; ULID is chosen for Laravel's first-class support and shorter, URL-friendly text form. Revisit by ADR if that changes.
- **Database-generated UUIDs:** engine-specific functions, conflicts with the portability rule.
