# G6-D03: Vitest worker count

The record of the investigation that resolved [G6-D03](debt-register.md) for the full-gate failures seen at WP2 `449eb42`. Product code and tests are unchanged; the only change is `test.maxWorkers` in `apps/guardian-console/vite.config.ts` (8 → 4).

## The evidence to explain

`./flow check all --pgsql` at `449eb42`, with neighbouring projects quiet (load about 1.6 beforehand): 2273 passed, 3 failed.

| Test | Failure |
| --- | --- |
| `cards.test.tsx` › adding a Card › has no accessibility violations, for each Type | 5154 ms against the 5000 ms test limit |
| `libraryMode.test.tsx` › opening the privileged viewer | `findByRole` could not find the "Uncategorized" heading (1 s default) |
| `cards.test.tsx` › a Card's page | `findByRole` could not find the "Opening hours" heading (1 s default) |

Vitest itself used 900-1060% CPU and the load reached about 17 on 16 logical CPUs (8 physical cores).

## Method

The whole frontend suite (89 files, 2276 tests), in a `node:24-bookworm-slim` container with the worktree mounted at `/app`, as `./flow test frontend` runs it, at `449eb42`, one run at a time, with the load average allowed to settle between runs. Only `--maxWorkers` changed. The JSON reporter gives every test's duration. Load and CPU were sampled every 3 s from `/proc`. `portal_app` (the neighbouring project that was busy during the WP2 review) was at 0% for every run.

## Results

| Workers | Run | Result | Wall | Tests over 1 s | Slowest test | Peak load |
| --- | --- | --- | --- | --- | --- | --- |
| 8 | host | pass | 147 s | n/a | n/a | 11.7 |
| 8 | container | pass | 136 s | n/a | n/a | 10.2 |
| 8 | container | **2 failed** (`credentials` forgot-password: 2.1 s `findBy` and 5.06 s test limit) | 134 s | 192 | 5057 ms | 11.1 |
| 8 | container | **1 failed** (`libraryMode` › opens the viewer, `findBy` for "Uncategorized", 1.5 s) | 135 s | 191 | 3791 ms | 11.5 |
| 6 | container | pass | 118 s | 59 | 2555 ms | 7.8 |
| 6 | container | pass | 130 s | 93 | 3598 ms | 10.4 |
| 6 | container | pass | 104 s | 37 | 2382 ms | 8.3 |
| 4 | container | pass | 140 s | 25 | 1627 ms | 8.3 |
| 4 | container | pass | 127 s | 16 | 1678 ms | 7.0 |
| 4 | container | pass | 133 s | 20 | 2408 ms | 6.8 |

With four synthetic busy-loop processes added on the host (about 400% CPU, roughly the neighbour seen in the WP2 review; no container was touched):

| Workers | Result | Wall | Tests over 1 s | Slowest test | Peak load |
| --- | --- | --- | --- | --- | --- |
| 4 | pass | 153 s | 37 | 2112 ms | 13.0 |
| 4 | pass | 142 s | 21 | 2105 ms | 9.9 |
| 6 | pass | 116 s | 52 | 2075 ms | 11.6 |
| 8 | pass | 113 s | 115 | 2498 ms | 17.1 |

## What is demonstrated, and what is not

**Demonstrated.**

- The failures are CPU oversubscription, not an application or test-logic defect. At 8 workers two of three container runs failed, each on a different test (`credentials` forgot-password, `libraryMode` first viewer test), the same family as the three original failures: whole-Console renders that wait with `findBy` or run axe, on tests that already ran 1.5-5 s. No failure occurred at 4 workers (5 complete runs, two of them with added load) or at 6 workers (4 complete runs, one with added load).
- The tail of test durations shrinks with the worker count: tests over 1 s were 191-192 at 8 workers, 37-93 at 6 and 16-25 at 4 (unloaded); the slowest test fell from 3.8-5.1 s to 2.4-3.6 s to 1.6-2.4 s.
- Wall-clock time does not improve with more workers on this host (8: 134-147 s, 6: 104-130 s, 4: 127-140 s). The earlier measurement that justified 8 was against the default of 16.
- The only lazy-loaded module in the Console is the rich-text editor (`LazyRichTextEditor`); every page is statically imported, so these failures are not caused by lazy route loading. The failing waits are for ordinary renders and fetches that were simply starved of CPU.

**Not demonstrated.**

- That 8 workers fail at a fixed rate: the sample is four runs (two failed). The decision rests on the tail-duration data, which is monotonic in the worker count, as well as the failures.
- That four busy-loop processes model a real neighbour: the 8-worker run with them passed even at load 17, so the synthetic load did not reproduce the failure. It is evidence that 4 workers tolerates added load, not a reproduction of the gate.
- The exact gate environment (Vitest after the two Pest runs, with the stack under test): not reproduced. The residual load before each run here was 2-7.

## Decision

`maxWorkers: 4`. It is the simplest correction, needs no change to any test, keeps every timeout as it was (no longer timeout, no disabled axe check, no retry), and costs no measurable wall-clock time on this host. Six workers were also clean (3 of 3) and slightly faster, but their slowest test reached 3.6 s against a 5 s limit; four keeps at least a 2x margin, and matches the four workers the Playwright run needs here.

The `libraryMode` and `cards` failures are not individually fixed because nothing in those tests is wrong: they wait on an observable state with the default `findBy` timeout, and that state arrives later only when the host is saturated. A deterministic ready signal was not needed.

## Revisit

Any recurrence of a Vitest timeout or `findBy` failure at 4 workers inside `./flow check all` on a quiet host. Sample `/proc/loadavg` and `docker stats` alongside the run, and check for a neighbour first.
