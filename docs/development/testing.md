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
| repo | Compose config validates, shellcheck on `flow`/`scripts`, `./flow` CLI behaviour tests, actionlint on workflows, WordPress plugin PHP syntax |
| backend | `composer validate --strict`, Pint (`--test`), Larastan level `max`, Pest on MariaDB incl. architecture tests, (with `--pgsql`) Pest on PostgreSQL |
| frontend | `tsc -b` strict, ESLint, Prettier check, Vitest, production build, build verification |

## Backend

- **Pest** with Laravel's HTTP testing. Use Pest's function helpers (`getJson()`, `withHeaders()` from `Pest\Laravel`) rather than `$this->...`, so Larastan can type-check tests.
- **Feature tests** boot the app and run on a real database via `RefreshDatabase`, which migrates fresh on every run. That is how migrations are validated on both engines.
- **No SQLite.** `tests/TestCase.php` throws unless the driver is `mariadb` or `pgsql`, and unless the database name ends in `_test`. Tests run in `flowlife_test`, separate from your development database `flowlife`; `./flow` creates it.
- **Architecture tests** (`tests/Architecture`, see its README): module boundaries, Identity's layer rules, no HTTP clients outside `Infrastructure`, no `env()` outside config, no debug/dangerous functions, strict types, and a portability scan that bans MariaDB-only schema/SQL unless annotated `// portability-exception: ADR-NNNN`.
- **Authentication tests** use `Tests\Support\Console`, a browser stand-in with a cookie jar that starts every request from a clean session and guard, and that makes CSRF real: **Laravel skips CSRF entirely under PHPUnit** (it checks the app environment is `testing`), so a test that merely POSTs proves nothing about CSRF. Laravel's `json()` test helper also sends **no cookies** unless `withCredentials()` is set. Time-dependent tests move the clock with `Console::advance()`; the absolute-lifetime tests keep the user active (`advanceWhileActive`) so the 30-minute inactivity rule can never be what ends the session.
- **Authorization tests** set roles up with `Tests\Support\Access` (grant through the port; revoke and plant corrupt keys directly in the table), because the grant and revoke use cases do not exist yet. HTTP enforcement is tested on **test-only routes** registered inside the test (`can:console.access` and so on); no production endpoint exists to demonstrate it. Tests about the platform administrator derive the expected capabilities from the catalog (`Access::everyCapabilityId()`), so adding a capability changes only the deliberate "exact catalog" test.
- **Concurrency tests** (`tests/Concurrency`) prove the last-administrator invariant across two real PHP processes and two database connections. They commit real rows (so they sit outside `RefreshDatabase` and clean up after themselves, and refuse any database not named `*_test`). The test process runs a real use case and **pauses inside its open transaction**, after it has changed state and before it commits, by hooking the audit write. There it launches `tests/Concurrency/worker.php`, which runs a competing removal, waits for it to report READY, gives it a moment, and asserts it is **still blocked** (the lock) and then **refused with an active administrator still standing** (the re-check on committed state). Each scenario asserts both halves, since either alone can pass for the wrong reason. They run on whichever engine the suite targets (`./flow test backend [--pgsql]`), take about two seconds each, and rely on a fixed 1.5 s grace after the worker is ready, so a very slow machine could in principle make a broken lock look like a working one for that scenario; the deterministic query-order tests are the backstop. What they cannot show: a deadlock that needs a precise three-way interleaving. Lock ORDER is instead pinned by a deterministic test on the SQL actually issued.
- **Persistence tests** run against the real engine and assert refusals on real rows (a refused delete leaves the data in place). Use `Tests\Support\Identity::violation()` to run an operation the database must refuse: it wraps it in a savepoint, because on PostgreSQL a failed statement aborts the surrounding test transaction and every later query in that test would fail.
- **Mutation-check important database tests.** Because collations differ (MariaDB folds case, PostgreSQL does not), a test can pass on one engine for the wrong reason: removing canonicalisation leaves the MariaDB run green and fails only PostgreSQL. Run both engines, and break the property once to see the test go red.
- **OpenAPI contract test:** the routes under `/api/v1` and `openapi/openapi.yaml` must match exactly, and the health response must match its schema.
- **Prove a rule can fail.** When adding an architecture rule, plant a violation temporarily and confirm it goes red. (Pest passes an *array of subject namespaces* vacuously; use one subject per expectation.)

## PostgreSQL

`./flow test backend --pgsql` starts the `postgres` profile, creates `flowlife_test`, and runs the suite with the connection switched by environment variables (real environment beats `.env` and `phpunit.xml`, so only the engine changes). CI runs it on every push via `./flow check --pgsql`.

## Frontend

Vitest with Testing Library and jsdom; component tests mock the API module. ESLint uses `typescript-eslint` `strictTypeChecked`; TypeScript is strict with `noUncheckedIndexedAccess` and `exactOptionalPropertyTypes`. `npm run verify:build` confirms Tailwind utilities are in the built CSS.

## E2E

`e2e/auth.spec.ts` drives real Chromium through the gateway for session authentication: the exact production cookie is accepted and returned on plain-HTTP localhost, sign-in with the CSRF token regenerates the session, sign-out works, wrong-password and unknown-address are indistinguishable, and a page on a *different site* can neither sign the user out (a real cross-site POST) nor read the API (no CORS). `./flow test e2e` first seeds a development-only fixture account. A Playwright smoke test (`apps/guardian-console/e2e/smoke.spec.ts`) loads the console in Chromium, checks a Tailwind computed style, and checks the API health result appears from the page's own origin, exercising gateway, Vite, Laravel and MariaDB together. A small `gateway.spec.ts` pins the single-origin routing: `/api` and `/up` reach Laravel, unknown API paths are JSON rather than the Console, client-side routes still boot the Console, the HMR websocket connects through the gateway, and the retired hosts are refused. Both run in the Playwright container on the `e2e` profile. In CI it is a **manually triggered** workflow to save minutes. Do not grow it into a large suite yet.

## The CLI's own tests

`scripts/tests/cli.sh` covers `./flow` dispatch and the `./flow ci` contract. It stubs `gh` on `PATH`, so it asserts which `gh` command each subcommand delegates to — plus the guards — without needing the GitHub CLI, credentials or network. It also asserts two architectural properties: that `require_gh` is referenced only by `scripts/commands/ci.sh`, and that `ci.sh` depends on neither Docker nor a set-up environment.

## CI

`.github/workflows/ci.yml` runs on pushes to `main`, on pull requests (docs-only changes are skipped), and on manual dispatch, cancels superseded runs on the same ref, uses Linux runners, caches the PHP image layers, and runs `./flow setup --skip-build` then `./flow check --pgsql`. See also [Docker environment](docker.md#ci-parity). No deployment happens from CI.

## Triggering and reviewing CI

`./flow ci` is a deliberately thin wrapper over the [GitHub CLI](https://cli.github.com) for this project's two workflows. It is **not** a general GitHub client: authentication, remotes, secrets, artifacts and dispatching arbitrary workflows stay plain `git`/`gh` commands.

**`gh` is required only by `./flow ci`.** Every other `./flow` command works without it, and `./flow check --pgsql` remains the provider-independent local equivalent of the CI checks.

```bash
sudo apt install gh            # Debian/Ubuntu; or pacman -S github-cli on Arch
gh auth login -s workflow      # the 'workflow' scope lets you push .github/workflows changes
```

| Command | Does |
| --- | --- |
| `./flow ci run [--ref REF]` | Triggers the CI workflow (default ref: current branch) |
| `./flow ci e2e [--ref REF]` | Triggers the E2E smoke workflow |
| `./flow ci status [--limit N]` | Recent runs of both workflows |
| `./flow ci watch [RUN_ID]` | Follows a run to completion; exits non-zero if it fails |
| `./flow ci logs [RUN_ID] [--full]` | Failed steps when the run failed, otherwise the whole log |
| `./flow ci rerun [RUN_ID] [--failed]` | Re-runs a run, or only its failed jobs |

Run ids are optional and default to the most recent run, so `./flow ci run` then `./flow ci watch` is the normal loop.

A manual run needs `workflow_dispatch` on the **default branch**, so a newly added or edited workflow must be pushed before `./flow ci run` will accept it.

**Before making the `./flow check` job a required status check (branch protection): revisit `paths-ignore`.** The workflow currently skips docs-only changes (`docs/**`, `**/*.md`) to save minutes. A workflow skipped by path filters never reports a status, so a *required* check would stay "pending" forever and block docs-only pull requests. Either remove `paths-ignore` (and accept the minutes), or add an always-running lightweight job/ruleset that satisfies the requirement. `workflow_dispatch` lets you run CI by hand on a docs-only commit, but a dispatched run does not satisfy a required check on a pull request.
