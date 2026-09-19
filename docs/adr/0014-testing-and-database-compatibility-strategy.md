# ADR 0014: Testing and database compatibility strategy

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

We need fast feedback and real confidence, and we must protect MariaDB/PostgreSQL portability ([ADR 0005](0005-mariadb-with-postgresql-portability.md)) from day one. Tests that run on a different engine than production (typically SQLite) hide exactly the bugs that portability work is meant to find. CI is on a private repository, so cost matters.

## Decision

**Backend:** Pest on Laravel, static analysis with Larastan at level `max`, Laravel Pint. Tests run against a **real database**: MariaDB is the canonical integration environment, and the *same suite* also runs on **PostgreSQL** (`./flow test backend --pgsql`; `./flow check --pgsql` in CI). **SQLite is not used**: `tests/TestCase.php` refuses any driver other than `mariadb`/`pgsql`, and refuses any database whose name does not end in `_test` so tests can never wipe the development database. Feature tests use `RefreshDatabase`, so every migration runs on both engines on every run.

**Architecture tests** (`tests/Architecture`) encode module boundaries and rules simple enough to check by namespace or source scan, including a portability scan for engine-specific schema and SQL. Rules must be proven able to fail; see `tests/Architecture/README.md` (Pest evaluates an array of *subject* namespaces vacuously, so each rule uses one subject).

**Frontend:** strict TypeScript, ESLint, Prettier, Vitest with Testing Library, a production build with Tailwind verification.

**System/browser:** Playwright, deliberately tiny: one smoke test proving the Guardian Console loads, styles apply and the API is reachable. It runs from a Compose profile (`./flow test e2e`) and in a manually triggered workflow, not on every push.

**CI runs `./flow check`**: no CI-only test logic; the workflow only prepares the environment (layer-cached image build, `./flow setup`).

## Consequences

- Portability regressions fail the PR that introduces them.
- Tests are slower than SQLite in memory; acceptable and intentional (whole gate about 75 s locally).
- Requires MariaDB and PostgreSQL containers for the full gate, and separate `*_test` databases alongside development data.
- A green suite proves portability only for what the suite exercises; coverage of real queries grows with the domain.

## Alternatives considered

- **SQLite for unit/feature tests:** fast, but hides engine differences; rejected.
- **MariaDB only in CI, PostgreSQL later:** cheaper now, but portability erodes silently until the migration becomes a rewrite.
- **A CI test matrix across many PHP/DB versions:** unjustified cost for a private repo at this stage.
- **Full Playwright suite on every push:** too slow and costly for the value at this stage.
