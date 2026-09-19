# ADR 0005: MariaDB first, PostgreSQL portability required

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

Production initially runs on shared cPanel hosting, where MariaDB is what is available (10.11 here). Long term the organization may need PostgreSQL. Migrating engines must not require redesigning the domain model, and portability that is only asserted rots quickly.

## Decision

- **MariaDB 10.11 is the initial production and canonical integration-test database.**
- **PostgreSQL portability is a hard requirement**, actively protected, not aspirational.
- Use Laravel's schema builder and query builder/Eloquent normally. Do **not** wrap Laravel's database layer in fake portability abstractions.
- Avoid database-specific enums, triggers, stored procedures, proprietary SQL, generated-column behaviour and other MariaDB-specific features **unless an ADR explicitly justifies it**. An exception is marked in code with `// portability-exception: ADR-NNNN`.
- Persist timestamps in UTC on both engines.
- Enforcement: a source scan (`DatabasePortabilityTest`), tests that refuse any driver other than `mariadb`/`pgsql`, and a full run of the suite on PostgreSQL (`./flow check --pgsql`, also in CI). See [ADR 0014](0014-testing-and-database-compatibility-strategy.md).

## Consequences

- Schema and queries stay boring and portable; some engine-specific optimisations are off the table until justified.
- Every migration is exercised on both engines continuously, so incompatibilities surface in the PR that introduces them.
- The PostgreSQL run adds about a minute to `./flow check --pgsql`.
- Collation and case-sensitivity differences (MariaDB `utf8mb4_unicode_ci` is case-insensitive, PostgreSQL is not by default) are a known portability risk: never rely on implicit case-insensitive matching; normalise in application code.

  This risk has since been **measured, not merely anticipated**. Inserting `person@example.org` and then `Person@Example.org` into a table with `unique(email)` is *rejected* by MariaDB 10.11 and *accepted* by PostgreSQL 16, which creates two rows differing only in case. For identity data that is a security-relevant divergence, and a MariaDB-to-PostgreSQL migration would change authentication semantics silently. The remedy adopted in [ADR 0015](0015-identity-owns-person.md) is a separate canonical (lowercased) column carrying the unique constraint and serving as the sole lookup key; both engines then behave identically.

## Alternatives considered

- **MariaDB only:** simplest, but locks in and makes a later migration a redesign.
- **PostgreSQL only:** technically attractive, but unavailable on the initial host.
- **An ORM-independent abstraction layer:** would reimplement what Laravel already provides and hide, not remove, real differences.
- **SQLite for fast tests:** rejected; it hides both MariaDB and PostgreSQL behaviour ([ADR 0014](0014-testing-and-database-compatibility-strategy.md)).
