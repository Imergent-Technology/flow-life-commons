# ADR 0039: PostgreSQL is a deferred compatibility target; routine verification is MariaDB-only

- **Status:** Accepted
- **Date:** 2026-11-18
- **Supersedes:** none. **Amends** the *verification* parts of [ADR 0005](0005-mariadb-with-postgresql-portability.md) and [ADR 0014](0014-testing-and-database-compatibility-strategy.md) (the PostgreSQL run of the suite in `./flow check --pgsql` and in CI). Their architectural rules stand.
- **Superseded by:** none

## Context

MariaDB 10.11 is the production database and the only one anyone is deploying to. [ADR 0005](0005-mariadb-with-postgresql-portability.md) made PostgreSQL portability "a hard requirement, actively protected", and [ADR 0014](0014-testing-and-database-compatibility-strategy.md) enforced it by running the whole backend suite on PostgreSQL in CI, on every push, so that portability regressions failed the pull request that introduced them. At the time that run was expected to add about a minute.

It now adds 6 m 46 s to a pull-request run and 8 m 02 s to a post-merge run (WU2, `d440922` and `79c01d8`: 3,535 tests passed, 37 skipped, on PostgreSQL 16), against a total of 43 m 53 s and 54 m 32 s, on a repository whose only database target is MariaDB. There are no plans to migrate. The owner has decided that the run is not worth its cost on every change.

## Decision

1. **Routine CI is MariaDB-only.** `.github/workflows/ci.yml` runs `./flow check`, which runs the backend suite on MariaDB and not on PostgreSQL.
2. **PostgreSQL stays available, explicitly.** `./flow test backend --pgsql` and `./flow check --pgsql` are unchanged, and so are the `postgres` Compose profile, the PostgreSQL test infrastructure and every test (none is deleted or rewritten). Anyone can run them locally.
3. **The cheap guardrails stay in routine verification.** The portability source scan (`DatabasePortabilityTest`), the refusal of any driver but `mariadb`/`pgsql`, the rule against database-specific schema and SQL without an ADR, UTC timestamps and the case-folding rule are architectural and run on every change. The rule "no DB-specific enums, triggers, procedures, proprietary SQL or generated columns without an ADR" ([charter](../architecture/charter.md) rule 13) is unchanged.
4. **PostgreSQL is a deferred compatibility target, not a current claim.** The repository does not claim ongoing PostgreSQL compatibility, only that its portability rules are kept and that its suite passed on a named commit.
   - **Last independently evidenced PostgreSQL-green commit:** `79c01d8a623a06f55170177cd2137d278af910e4` (2026-11-18). Its post-merge CI run (GitHub Actions run 44376562316) ran `./flow check --pgsql` and passed: MariaDB 3,572 tests, PostgreSQL 3,535 passed and 37 skipped (the engine-specific ones the suite already skips on PostgreSQL). The same tree passed the pull-request run (44368339411, `d440922`).
   - Update this line whenever someone runs `./flow check --pgsql` green on a later commit and records it; do not infer it from a routine run.
5. **Reactivation.** When migration planning resumes, run `./flow check --pgsql` first, expect failures in proportion to the work done since `79c01d8`, and make the PostgreSQL run part of routine verification again for the duration of the migration.

## Consequences

- A routine run is about 7 to 8 minutes shorter, and a migration or query that works on MariaDB and not on PostgreSQL is no longer caught in the pull request that introduces it. It is caught when someone runs the PostgreSQL suite, or at the latest when migration planning resumes. The source scan and the architecture rules catch the portable-by-construction cases (enums, triggers, proprietary SQL) on every change.
- The collation difference [ADR 0005](0005-mariadb-with-postgresql-portability.md) measured (MariaDB case-folds, PostgreSQL does not) stays a known portability risk. Application code must still normalise rather than rely on case-insensitive matching.
- Documentation must not say "CI runs PostgreSQL". It does not, and a green routine run says nothing about PostgreSQL ([testing](../development/testing.md), "Verification tiers").

## Alternatives considered

- **Keep the PostgreSQL run in CI.** Rejected: about 15% of the run's cost for a database nobody deploys to. It remains the right choice if migration planning resumes.
- **Run it on a schedule.** Not adopted now (no scheduled workflow is introduced by this decision); it can be added later without changing this decision.
- **Delete the PostgreSQL tests and profile.** Rejected: they are the evidence of the last green commit and the starting point for reactivation, and they cost nothing while they are not run.
