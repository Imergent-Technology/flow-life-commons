# G6 WP2: final focused recheck

## A. Repository state

- **Branch and commits:** `fix/g6-wp2-authorization-disclosure`, HEAD `09e250fcce58`. The merge base with `18d059ec5d98` is correct.
- **Ancestry:** linear. `f10e948` → `449eb42` → `6318e73` → `09e250f`, each commit the parent of the next.
- **State:** clean tree, branch not on origin.
- **Other worktree:** `flow-life-commons-d03`, at `07a677f`. I left it untouched.

## B. L1 implementation

[RouteSurface.php](apps/platform/tests/Support/RouteSurface.php) `keys()` now drops `HEAD` only when the same route also has `GET`.
- **Why it is right:** Laravel itself adds an implicit HEAD only to GET routes, so this rule matches the framework exactly.
- **Explicit HEAD-only routes:** now keyed and judged.
- **Mixed-method routes** such as GET+HEAD+POST: keyed as GET and POST, with nothing omitted and nothing duplicated.
- **Allowlist:** unchanged, still exactly ten entries.

## C. Independent mutation (scratch worktree at `09e250f`)

| Planted route | Runtime, no session | Guard |
|---|---|---|
| V5: `Route::match(['HEAD'], 'probe', …)` | **200**, planted `X-Leak` header returned | **fails**: `HEAD api/v1/probe is not authenticated and is not in the public list` |
| V5b: `Route::match(['HEAD','POST'], 'mixed', …)` | POST **200** | **fails** on both `HEAD api/v1/mixed` and `POST api/v1/mixed` |
| Restored | 404 | 21 of 21 passed (the route surface tests plus my runtime probe) |

So the guard now catches a route that really is reachable without authentication. Each mutation was reverted with `git checkout`, and nothing was committed.

## D. Regression tests

The three new tests make meaningful assertions:
- A hand-built HEAD-only route is reported, both inside and outside `/api/v1`.
- An implicit HEAD gives exactly one violation, and the mixed-method keys are exact.
- The real route table has no `HEAD` keys and still contains `GET up` and `GET api/v1/health`.

The focused WP2 suites pass: **212 on MariaDB and 212 on PostgreSQL** (the 209 from before plus these 3). There are no material gaps.

## E. D03 review

- **Cherry-pick:** `6318e73` is patch-identical to `07a677f`, which I confirmed with `git patch-id`.
- **Code change:** the only line that runs is `maxWorkers: 8` → `4`, plus an explanatory comment. There are no test, assertion, timeout, retry, quarantine or `src/` changes.
- **Evidence record:** internally consistent.
  - 4 workers: 5 clean runs (3 unloaded, 2 with synthetic load).
  - 6 workers: 4 clean runs.
  - 8 workers: 2 of 4 runs failed.
  - Wall-clock time is comparable at every worker count.
  - Its "not demonstrated" section is appropriately bounded.
- **Correction to my earlier review:** the record also found 3 failures at `449eb42` on a quiet host (load about 1.6). So Vitest was saturating the host itself. My earlier pointer to the neighbouring container was incomplete, though the classification as a load-sensitive test still holds.

## F. Acceptance gate

I checked the record against the raw log (`gate2.log` in the remediation session's scratchpad).
- **Timing:** the run started at 10:43:46, after `6318e73` was committed at 10:43. Its epoch markers differ by exactly **1,792 s**.
- **Results:**
  - MariaDB: 3,424 passed, 26,820 assertions.
  - PostgreSQL: 3,424 passed, 26,817 assertions.
  - Vitest: 2,276 passed, 89 of 89 files.
  - Pint, Larastan and `verify:build` green; "All checks passed"; `GATE_EXIT=0`.
- **No retries:** the log's "retry" matches are test names and one header.
- **Tree identity:** `09e250f` touches only `wp2-record.md`, so the gate ran on the final executable tree.

## G. Security boundaries

Since `f10e948`, nothing has changed under `app/`, `routes/`, `bootstrap/`, `config/`, `database/`, `openapi/`, the Console's `src/` or `e2e/`. That leaves `ProvisionableRole`, `Authorizer`, `PeopleHoldingCapability`, `CheckRelationships`, CRM, Resources, roles and step-up policy exactly as I verified them.

## H. Remaining findings

There are no new material findings. I1–I4 remain non-blocking under the owner's ruling, and I5 is now recorded in G6-D03.

## I. Repository integrity

- **Changes:** none to tracked files. No commits, pushes, PRs, merges or deployments.
- **Containers:** none of the other projects' containers were touched.
- **Scratch:** the worktree is removed and pruned, and the shared checkout is clean at `09e250f`.

## J. Recommendation

WP2 can proceed to PR preparation without another implementation round.

**G6 WP2 FINAL VERIFICATION: SAFE TO PREPARE PR**
