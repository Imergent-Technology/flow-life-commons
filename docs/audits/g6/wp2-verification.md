# G6 WP2: independent remediation verification

## A. Executive security verdict

The security fix itself is correct. A relationship-sourced grant now confers only a provisionable role, consistently in both the Authorizer and holder counting. I reproduced its defect and mutation evidence on both engines and killed three mutants of my own.

The Resources, CRM and Console-admission tests have real discriminating power.

One narrow LOW keeps WP2-01 from being verified. The route guard ignores every `HEAD` method, so a HEAD-only route with no authentication is served and the guard passes it. I measured this. The fix is a one-line change to the test helper plus one test.

## B. Checkpoint and reviewed diff

- **Commits:** HEAD `f10e948`. The merge base equals `origin/main`, which is `18d059ec5d98f9c96147c96dea98fe754ff9db67`.
- **Branch state:** exactly 5 commits (`8c5f228`, `fc1308e`, `03acd92`, `486b0b2`, `f10e948`), clean tree, not on origin.
- **Diff reviewed:** the whole of `18d059e..f10e948`, 21 files.
- **Production changes:** only `ProvisionableRole`, `Authorizer`, `PeopleHoldingCapability` and `CheckRelationships`. On top of those, there is one docblock line in `Relationships/Http/routes.php` and one description in `openapi.yaml`.

## C. Global route guard (WP2-01)

**What it does right:**
- It reads the effective route table through `Router::gatherRouteMiddleware`. That is the same resolver the runtime pipeline uses, so aliases, groups and `withoutMiddleware` resolve exactly as they do for a real request.
- No route is registered conditionally: none depends on environment or on running in the console, and storage `serve` is off.
- The helper is not tautological. The committed hand-built controls and my mutations both fail it.

| # | Mutation on the real route files (scratch worktree) | Result |
|---|---|---|
| V1 | `/me` without `auth:web` | killed: "GET api/v1/me is not authenticated…" |
| V2 | `->withoutMiddleware('auth:web')` on `security/verify` | killed |
| V2b | `->withoutMiddleware(Authenticate::class)` on `security/verify` | guard passes, and that is correct: the runtime still answers **401**, because Laravel does not strip a parameterised `auth:web` this way |
| V3 | an unexpected `POST crm/export` with no authentication | killed |
| V4 | `mfa/challenge` without `StartSession` | killed: "listed as 'session' but lacks…" |
| V6 | `Route::fallback` | killed: `GET api/v1/{fallbackPlaceholder}` |
| **V5** | **`Route::match(['HEAD'], 'probe', …)` with no authentication** | **survives.** The guard passes, and the route answers **200** with its custom header (see L1) |

- **Allowlist:** exactly the intended ten routes, with no stale entries.
- **Live route table:** 111 route keys in total: 10 unauthenticated, 7 authenticated self-service, and 94 `/admin` keys, all of which carry `can:console.access`.
- **OpenAPI:** the three readings (`required`, `optional`, `none`) cannot make a weaker declaration pass for an authenticated route, with one unreachable edge case (I2).

## D. Human-role Console admission invariant (WP2-02)

- **Discovery:** the test enumerates `Role::cases()`, so a new role is covered automatically.
- **Empty bundle:** allowed (my control C3 passes).
- **Service identities:** the scope comment leaves ADR 0033's future service identities explicitly out.
- **Mutations:**
  - C1: a new `auditor` role granting only `crm.people.view` is killed by name.
  - C2: `console-participant` granting `resources.view` without `console.access` is killed.
- **Value beyond the exact-list tests:** modest but real. The exact-list tests get edited alongside a new role; this test states the rule that MFA coverage depends on.

## E. Sourced-grant hardening (WP2-03)

**Invariants:** all ten hold. Specifically:
- An independent assignment keeps the full catalog.
- A sourced row resolves only through `ProvisionableRole`.
- A planted sourced `platform_administrator` row confers nothing. My probe shows the holder's `/me` capabilities are `[]`.
- Valid sourced `guardian-initiate` grants still give `console.access`.
- Combinations are deduplicated.
- An invalid sourced row never touches an independent role.
- Holder counting agrees with the Authorizer for every capability.
- Orphan behaviour is unchanged.

**Single authority:** `fromRoleKey` and `roleKeysGranting` are suitable as the one source of truth. The write path stores `$role->role()->value`, so comparing against `role()->value` is correct. On MariaDB, `whereIn` matches case-insensitively, but the repository's strict `in_array` filter stops that from widening anything; the miscased dataset proves it.

**Other readers of role keys:** every one checked.
- `ConsoleMultiFactorPolicy` goes through the Authorizer.
- `AdministratorOverview` and `AdministratorContinuity` read independent assignments only.
- The Relationships readers only compare keys or display them.
- `AccountViews:59` is display only. It shows a planted row as "Platform administrator" through a source, and the Console's revoke dialog then warns that access "may continue to apply". No decision consumes it. I classify it as **INFO** (I1): it over-reports, only on tampered data, and arguably helps an operator spot the row.

**Mutations (both engines):**

| Mutation | Result |
|---|---|
| S1: Authorizer reads sourced keys through `Role::tryFrom` again | 7 failures (reproduces the record) |
| S2: holder counting uses every role again | 6 failures (reproduces the record) |
| S3 (mine): `fromRoleKey` ignores case and whitespace | 4 failures |

The failures assert on the unauthorized capability lists themselves, not on incidental responses.

## F. Last administrator and diagnostics

- **Continuity:** `AdministratorContinuity` is unchanged and counts independent assignments only. My S4 mutation, which counts a sourced admin row as a second administrator, is killed.
- **Diagnostic:** `relationships:check` correctly reports a planted orphan's non-provisionable role.
  - It never names the role and repairs nothing.
  - My S6 mutation, which accuses every orphan, is killed; so is S5, which disables the check.

## G. Resources corruption tests (WP2-05)

- **Mutations:** Z1 (6 failures), Z2 (4) and Z3 (4) reproduce the record exactly on MariaDB and PostgreSQL. Each failure names the leaked marker and the route.
- **`hides` lists:** correct for every case.
- **No vacuous passes:**
  - The fixture test proves the markers are detectable in Pack responses.
  - A file-routes-only scratch copy killed Z2 through `/file` alone.
  - Its positive control confirms an eligible reader does receive the file-bytes marker.
  - The committed suite lacks that file-route control (I3).
- **Status codes:** no 500 is pinned.
- **Header comparison:** sound. It excludes only `Date` and cookie values, and compares cookie names.

## H. CRM authorization before lookup (WP2-06)

- **Mutations:**
  - K1: `RenameTag` looks the tag up first. It fails with `TagNotFound` rather than `AccessDenied`.
  - K2: `EditInteraction` checks the Person first (on PostgreSQL). It fails with `UnknownPerson`.
  - K3: a new unlisted use case fails the discovery test.
- **Exception hierarchy:** every not-found exception extends `RuntimeException`, not `AccessDenied`, so `toThrow(AccessDenied::class)` really does discriminate.
- **Carve-outs:**
  - `PersonRegistration` is appropriate: `CrmBoundariesTest:196` pins it to its two callers.
  - `ReadPersonRecord` takes no Actor, is internal, and both of its callers authorize first.

## I. Documentation and residual risks

- **Audit:** byte-identical to the original handback, which I extracted from the audit session transcript, from heading to verdict.
- **Triage:** identical to the owner's paste, apart from trailing whitespace.
- **Trust boundaries:** match the live route table.
- **Logout:** `[sessionCookie, {}]` is correct.
- **Relationships comment and ADR 0038:** both corrections are accurate.
- **Debt rows G6-D12 to D19:** accurate.
  - G6-D12 says `relationships:check` is not scheduled, and that is true.
  - G6-D03's own trigger ("any recurrence inside `./flow check all`") has fired, in a file it already names, but the row was not updated (I5).
- **Scope:** no step-up, security-event, orphan-cleanup, role-architecture or Resources-parsing changes.

## J. Test results

- **Focused WP2 suites** (route surface, catalog, sourced grants, CRM order, Resources corruption, Resources files, Guardian provisioning): **209 passed** on MariaDB and **209 passed** on PostgreSQL.
- **Not re-run:** Pint and Larastan, and the full backend suite.

## K. Vitest timeout

**Classification: (1), a pre-existing, load-sensitive test.** The evidence:
- The branch makes no Console changes.
- `./flow check` runs backend Pest to completion before Vitest starts, so WP2 cannot load the frontend run while it executes.
- The test takes **1.2–2.0 s alone**, against **5.2 s** inside the gate.
- It runs three axe passes over a rich editor.
- A neighbouring container, `portal_app`, was at about **360% CPU** while I measured. My notes record the same neighbour pushing Flow suites into timeouts before.
- There is no sign of an accessibility failure: it passes with zero violations.

It belongs to G6-D03's family, but the mechanism is the axe loop, not a first `findBy`.

The evidence supports one clean, unchanged full-gate run on a quiet host, sampling `/proc/loadavg` and `docker stats` throughout. If it recurs on a quiet host, that becomes its own package under D03's rules.

## L. Findings

**L1. LOW: the route guard ignores HEAD-only routes.**
- **Location:** [RouteSurface.php:81](apps/platform/tests/Support/RouteSurface.php#L81)
- **Invariant violated:** every unauthenticated route is reviewed.
- **Failure scenario:** a future module adds `Route::match(['HEAD'], …)` with no authentication. It is served (measured: 200, with headers controlled by the handler) and the guard stays green.
- **Smallest fix:** skip `HEAD` only when the route also answers `GET`.
- **Discriminating test:** a hand-built `surfaceRoute(['HEAD'], 'api/v1/probe')` must be reported as "HEAD api/v1/probe is not authenticated…".
- **Impact:** test-only, but it must be fixed before the PR.

**I1–I5. INFO: optional, documentation or test hardening.**
- **I1:** the `AccountViews` over-report described in E. Record it, or later mark such rows "not in effect"; do not hide them.
- **I2:** [RouteSurface.php:112](apps/platform/tests/Support/RouteSurface.php#L112) returns `required` on the first unknown scheme, even when a `{}` alternative is present. It is unreachable today, because the spec defines only `sessionCookie`.
- **I3:** the corruption test has no file-route positive control.
- **I4:** the CRM order test's dataset ([line 132](apps/platform/tests/Feature/Modules/Crm/CrmAuthorizationOrderTest.php#L132)) is a third literal list. It should use `->with(crmListedUseCases())`.
- **I5:** add this timeout occurrence to G6-D03.

## M. Scope and repository status

- **Changes:** no tracked file changed, and nothing was committed, pushed or deployed.
- **Mutations:** all applied in a detached scratch worktree, each reverted, and the worktree is now removed.
- **Shared checkout:** clean at `f10e948`.
- **Scratch evidence:** the extracted audit and triage copies, the PostgreSQL log and the helper scripts are in `/tmp/claude-1000/-home-tennyson-development-flow-life-commons/c8fef630-2e18-4c6f-95e0-48c5590dd395/scratchpad/`.

## N. Next steps

1. Sonnet fixes L1, and optionally I2–I5.
2. Re-verification can be limited to that diff and the V5 mutation.
3. Run one clean, unchanged `./flow check all --pgsql` on the final tree, with `portal_app` idle and load sampled throughout.
4. Prepare the PR only after both of those.

**Security verdict:** `G6 WP2 SECURITY: REMEDIATION REQUIRED` (L1 only)

**Acceptance-gate status:** `G6 WP2 GATES: FULL-GATE RUN REQUIRED`

WP2 is not ready for a PR.
