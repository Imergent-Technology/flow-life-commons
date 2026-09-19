# Testing

Strategy and rationale: [ADR 0014](../adr/0014-testing-and-database-compatibility-strategy.md).

## Commands

| Command | Runs |
| --- | --- |
| `./flow test` | Backend (Pest, MariaDB) and frontend (Vitest) |
| `./flow test backend [--pgsql] [pest args]` | Pest; `--pgsql` runs the same suite on PostgreSQL. Args pass through, e.g. `--filter=Health` |
| `./flow test frontend [vitest args]` | Vitest |
| `./flow test e2e` | Playwright smoke test against the running stack (`./flow up` first) |
| `./flow check [repo\|backend\|frontend] [--pgsql]` | Everything CI runs (below) |

`./flow check` runs every check even after a failure, prints a summary, and exits non-zero if anything failed.

| Scope | Checks |
| --- | --- |
| repo | Compose config validates, shellcheck on `flow`/`scripts`, actionlint on workflows, WordPress plugin PHP syntax |
| backend | `composer validate --strict`, Pint (`--test`), Larastan level `max`, Pest on MariaDB incl. architecture tests, (with `--pgsql`) Pest on PostgreSQL |
| frontend | `tsc -b` strict, ESLint, Prettier check, Vitest, production build, build verification |

## Backend

- **Pest** with Laravel's HTTP testing. Use Pest's function helpers (`getJson()`, `withHeaders()` from `Pest\Laravel`) rather than `$this->...`, so Larastan can type-check tests.
- **Feature tests** boot the app and run on a real database via `RefreshDatabase`, which migrates fresh on every run. That is how migrations are validated on both engines.
- **No SQLite.** `tests/TestCase.php` throws unless the driver is `mariadb` or `pgsql`, and unless the database name ends in `_test`. Tests run in `flowlife_test`, separate from your development database `flowlife`; `./flow` creates it.
- **Architecture tests** (`tests/Architecture`, see its README): module boundaries, no HTTP clients outside `Infrastructure`, no `env()` outside config, no debug/dangerous functions, strict types, and a portability scan that bans MariaDB-only schema/SQL unless annotated `// portability-exception: ADR-NNNN`.
- **OpenAPI contract test:** the routes under `/api/v1` and `openapi/openapi.yaml` must match exactly, and the health response must match its schema.
- **Prove a rule can fail.** When adding an architecture rule, plant a violation temporarily and confirm it goes red. (Pest passes an *array of subject namespaces* vacuously; use one subject per expectation.)

## PostgreSQL

`./flow test backend --pgsql` starts the `postgres` profile, creates `flowlife_test`, and runs the suite with the connection switched by environment variables (real environment beats `.env` and `phpunit.xml`, so only the engine changes). CI runs it on every push via `./flow check --pgsql`.

## Frontend

Vitest with Testing Library and jsdom; component tests mock the API module. ESLint uses `typescript-eslint` `strictTypeChecked`; TypeScript is strict with `noUncheckedIndexedAccess` and `exactOptionalPropertyTypes`. `npm run verify:build` confirms Tailwind utilities are in the built CSS.

## E2E

One Playwright test (`apps/guardian-console/e2e/smoke.spec.ts`) loads the console in Chromium, checks a Tailwind computed style, and checks the API health result appears, exercising gateway, Vite, CORS, Laravel and MariaDB together. It runs in the Playwright container on the `e2e` profile. In CI it is a **manually triggered** workflow to save minutes. Do not grow it into a large suite yet.

## CI

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests (docs-only changes are skipped), cancels superseded runs on the same ref, uses Linux runners, caches the PHP image layers, and runs `./flow setup --skip-build` then `./flow check --pgsql`. See also [Docker environment](docker.md#ci-parity). No deployment happens from CI.

**Before making the `./flow check` job a required status check (branch protection): revisit `paths-ignore`.** The workflow currently skips docs-only changes (`docs/**`, `**/*.md`) to save minutes. A workflow skipped by path filters never reports a status, so a *required* check would stay "pending" forever and block docs-only pull requests. Either remove `paths-ignore` (and accept the minutes), or add an always-running lightweight job/ruleset that satisfies the requirement.
