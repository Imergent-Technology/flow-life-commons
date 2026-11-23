# G6 WP6A: test reliability triage

- **Package:** WP6A, the test-triage half of [WP6](README.md#wp6--test-triage-and-g6-closeout). Items [G6-D01 to G6-D06](debt-register.md). Closeout, the final register, the roadmap and charter updates, and the full exit gates belong to WP6B and are not done here.
- **Base:** `main` at `0b85ae2` (WP5, PR #34). **Branch:** `g6/wp6a-test-triage`.
- **Outcome:** one test defect corrected (G6-D01), with a latent product gap found behind it and **left for the owner**. G6-D02 was **not reproduced**. G6-D03 to G6-D06 are reconciled with no change. Nothing in `src/`, the platform, a timeout, a worker count or a test's expectations changed.
- **Method note.** Figures marked *measured* come from runs in this package, at four Playwright workers on the built-assets origin (`prod.flowlife.localhost`), through `./flow test e2e` or the same Playwright command in its container. *Inference* is marked. Raw logs were kept in the session scratchpad and are **not committed**; the one trace that survived is cited by what it shows.

## Summary

| ID | Classification | Change | Recommended disposition |
| --- | --- | --- | --- |
| G6-D01 | **Test defect, established.** A **latent product gap** behind it is established as a mechanism and is the owner's to classify | One synchronisation line in the test | Close as a test fix; register the product gap as a new LOW row (proposed G6-D25) |
| G6-D02 | **Not reproduced.** Unclassified intermittent, most plausibly load (inference) | None | Keep open at LOW, with a sharper trigger |
| G6-D03 | Resolution stands; no recurrence found | None | Keep as resolved |
| G6-D04 | Larger than registered; **no correctness or independence defect found** | None | Accept (development data), with a trigger |
| G6-D05 | Accepted mechanism unchanged | None | Keep as accepted |
| G6-D06 | Owner ruling D7 unchanged | None | Keep as accepted, G7 trigger |

## G6-D01: Discussions focus restoration

**Prior evidence.** One failure in the first local four-worker run of `cb34707`, and 1 in 10 isolated runs. The hypothesis was that `ThreadMessages` re-reads after a reply and the re-render replaces the button that the dialog returns focus to. No log or trace was committed.

**Investigation: two passes, as the contract allows.**

*Pass 1, reproduce and read the trace.* The journey ran 10 times in each of the three Playwright passes of `./flow test e2e` (30 runs, four workers, host load average about 10 to 12, which the run itself causes). **3 of 30 failed, always at `expect(remove).toBeFocused()`** (`Received: inactive`, 5 s, the locator resolving to a button every time). The trace of one failure survived and gives this timeline (milliseconds from the first action):
- 7185: the click on *Post reply* completes. 7196: the check `getByText(second)` passes within 16 ms.
- 7422 to 7495: the click on *Remove your message 2* is performed. 7540: the dialog is visible.
- **7529: a DOM snapshot shows the thread's "Loading messages…" skeleton and the "Reply posted." status**, so the list was replaced after the click, with the dialog open.
- 7635: Escape. 7824: the list is back, with new buttons for messages 2 and 3. Focus never returned to any of them.

*Pass 2, measure the mechanism, then correct and prove.* A scratch probe (not committed) logged focus events and DOM changes through the same steps.
- **Measured: the test's synchronisation point is vacuous.** `body.getByText(second)` matched the reply `<textarea>` before the server had answered, in **24 of 24** runs. React mirrors a controlled textarea's value into its text content, so Playwright's text match finds it. In the same 24 runs "Reply posted." was **not yet visible** when the test went on to click *Remove*. The first reply in the journey does wait for "Reply posted."; the second did not.
- **Measured: the failure follows the slowness of the server's answer.** With the original test and a `POST` delay injected on the reply route, the failures at `toBeFocused` were: 100 ms, 5 of 8; 200 ms, 3 of 8; 300 ms and 450 ms, 0 of 8 (the dialog sequence then ends before the re-read lands); 700 ms, 0 of 6. This is a model of a slow host, not the natural run, but it reproduces the same assertion and the same shape of failure.
- **Measured: why focus is lost.** With the answer delayed so that the re-read lands while the dialog is open, active element after Escape was `body` in **8 of 8** runs. The event order of one run: dialog opens at 1002 ms; the list is removed and re-added at 1583 to 1647 ms; the dialog closes at 2009 ms with no focus event on any *Remove* button. `useModalDialog` records the opener when the dialog opens and focuses it again on close only `if (opener?.isConnected === true)`. A re-read between those two moments detaches it.

**Classification.**
- **Test defect (established).** The test uses the list before the re-read that follows its own reply has finished, and its wait could not tell the difference.
- **Product gap (mechanism established, exposure not measured).** A re-read that replaces the list while a confirmation dialog is open leaves nothing for focus to return to, so a keyboard or screen-reader user is dropped to the start of the page. In ordinary use the window is short (a person would have to open *Remove* within the re-read after their own reply), and I did not measure how often it can happen. `useModalDialog` serves about 20 call sites; **I examined only Discussions** and did not audit the others. The gap is in what the hook can do when its opener goes, and each caller's exposure depends on whether its owner re-renders under the dialog.

**Correction.** `apps/guardian-console/e2e/discussions.spec.ts`: after the second reply, wait for `getByRole('article', { name: /Message 3/ })` (the new message, which is on the re-read list) before the *Remove* step. Four lines including the comment. Every existing assertion is kept, including the focus assertion.

| Check | Result |
| --- | --- |
| Original test, delay 100 ms and 200 ms | failed 5 of 8 and 3 of 8 at `toBeFocused` |
| Corrected test, same delays, and no delay | **8 of 8, 8 of 8, 8 of 8** |
| Natural comparison: 10 repeats in each of the three passes | original **3 of 30 failed**; corrected **30 of 30 passed** (a rebuilt, unmutated build) |
| **Mutation** (`useModalDialog`: the `opener.focus()` call removed, the Console rebuilt) | corrected test **failed at `toBeFocused` in all three passes**. The first mutant did not compile (an unused variable) and produced no evidence, so it was replaced by `void opener`. Reverted afterwards |

The mutation shows the corrected test still fails on the behaviour it exists to protect, so the fix did not weaken the focus coverage.

**Remaining uncertainty.** The natural failure rate fell from 3 of 30 to 0 of 30; that is a small sample and shows the cause is gone more than it shows a rate. The same vacuous pattern (`getByText` satisfied by a textarea's value) occurs at other steps of this journey, for example after *Save* on an edit. Those steps are followed by an assertion that does wait and have not failed, so they were left alone. The latent product gap's exposure and its fix are not examined.

**Recommended disposition.** G6-D01: **closed as a test defect, fixed in WP6A**. A new row for the product gap (below) so that it is not lost.

## G6-D02: the 1023 px responsive-shell step

**Prior evidence.** Failed once in the same first four-worker run on `cb34707` (the run that also failed G6-D01); 10 of 10 passed in isolation. No log or trace is committed.

**New evidence (measured).**
- The journey passed **30 of 30** on a quiet host (10 repeats in each of three passes, four workers), on a clean rebuild.
- It passed **24 of 24** under synthetic load: eight busy-loop processes beside four workers, load average 8 to 14 (the same device as [d03-vitest-workers.md](d03-vitest-workers.md), with eight loops where it used four).
- Reading the code: the shell derives its band from `matchMedia` through `useSyncExternalStore` (`shell/viewport.ts`), subscribed to both breakpoint queries, so a crossing is event-driven and cannot be missed by a render. The test sets the size, then polls three counts (`expect.poll`, 5 s). Nothing in the code or the test shows a race.

**Classification.** **Not reproduced: an unresolved intermittent.** The one natural failure happened at the heaviest-load moment, and the shell code is event-driven, so a load or browser-environment cause is the most plausible reading **(inference; no failing run was observed or traced)**. Nothing supports a layout defect, and the breakpoint expectations are untouched.

**Changes.** None.

**Remaining uncertainty.** No failure was seen, so the cause stays unproved. A failure traced under a full acceptance run is the only thing that would settle it.

**Recommended disposition.** Keep open at LOW as an **unresolved intermittent**; do not quarantine. Revisit trigger: any recurrence in an acceptance run, in which case keep that run's trace (`trace: 'retain-on-failure'` is already set; copy it before the next run overwrites `test-results/`).

## G6-D03: Vitest cold-render history

- **Confirmed unchanged:** `test.maxWorkers` is `4` in `apps/guardian-console/vite.config.ts`, as [d03-vitest-workers.md](d03-vitest-workers.md) decided.
- **Recurrence evidence:** Of the 100 most recent workflow runs (2026-09-29 to 2026-11-22), after the correction (2026-10-16) the only failed CI run was 2026-10-19 (`24f439a`): its failing step was a rehearsal guard (`compose_requires_generated_values`), and its Vitest step passed (89 files). Both WP4 full gates ([wp4-record.md §6](wp4-record.md)) passed Vitest (2276 tests) at four workers, the second on `38388d3`. No Vitest timeout or `findBy` failure is recorded since the correction.
- **Not examined:** a full Vitest run was not repeated in WP6A beyond the gate below.
- **Classification and disposition:** unchanged, **resolved**. No timeout or worker change is justified. Keep the existing revisit trigger.

## G6-D04: E2E-created People leftovers

**Prior evidence.** Two relationship journeys leave People behind, and three Account-less *E2E Lifecycle Kept* Persons from a fixed seeder defect remain.

**New evidence (measured, read-only; nothing was deleted).** The development database holds **1125 People: 1102 with an `E2E` name (175 of them with an Account) and 23 others**, accumulated since 2026-09-20. It holds 180 Accounts. The register describes much less than this: many journeys (the largest groups are *Audited*, *Axe*, *Keys*, *Twin*, *Member*, *Invited*, *Journey*) create People that stay. The three Account-less *Lifecycle Kept* Persons are still there. A fourth *Lifecycle Kept* row is the fixture persona the seeder recreates, and it has an Account.

**Effect on independence and correctness.** A search of the People, CRM, Relationships and G10 specs found no assertion on an absolute count of People, and the journeys use random tags in what they create, and the full suite has run green with this accumulation **(a limited search, not proof)**. One weakness was found: `people-closeout.spec.ts` checks that no Account's login appears in a response, against `GET /admin/accounts?per_page=100`, and the database now holds 180 Accounts, so that negative check reads only part of them. It is a limit on how much that test shows, not a failure.

**Classification.** Accumulation of development data, **larger than registered, with no demonstrated effect on correctness**; one assertion's coverage shrinks as it grows.

**Changes.** None: no correction is justified by a reproduced defect.

**Recommended disposition.** **Accept** (development data only), rewording the row to the measured scope. Revisit trigger: a journey whose result depends on the completeness of a list (the `per_page=100` check is the known one), a list or count assertion that starts to fail, or the development database's size becoming a cost. A cleanup or a paginated check would then be a fixture change for the owner to authorize.

## G6-D05: session keep-alive

**Unchanged.** `E2eSessionKeepAlive` and its time-controlled test (`tests/Feature/Modules/Identity/E2eSessionKeepAliveTest.php`) are in place, and the mechanism's evidence is the four caught mutants recorded earlier. No 30-minute exercise was forced. Every run in this package was shorter than 30 minutes.
**Recommended disposition:** keep as **accepted**, with its existing trigger (the first run longer than 30 minutes, or a change to the session lifetime or the keep-alive). WP6B's full run is the first likely to approach it.

## G6-D06 / owner ruling D7: browser CI

**Unchanged.** `.github/workflows/e2e.yml` is `workflow_dispatch` only, and `gh run list --workflow e2e.yml` returns no runs. Browser acceptance stays the local four-worker run. No workflow was added or changed, and [testing.md](../../development/testing.md) already records the position. The G7 decision stays with the G7 gate ([entry condition 9](g7-entry-conditions.md#9-browser-workflow-verification)).
**Recommended disposition:** keep as **accepted** under D7.

## Proposed register updates

These are proposals. The register file is **not** edited in WP6A, because finalizing it is WP6B's task and the dispositions are the owner's.

1. **G6-D01.** Disposition: *Fixed in WP6A (test defect).* Replace the unconfirmed hypothesis with the established cause: the test's wait was satisfied by the reply box's own text, so it used the list before the re-read had finished, and a re-read under an open dialog left no focus target. Cite this record. Keep the *Evidence limitation* sentence, adding that the figures were re-measured here and that the mechanism is now traced.
2. **New G6-D25 (proposed).** *Focus is not restored when the control that opened a confirmation dialog is replaced while it is open.* Evidence: this record, G6-D01. Severity LOW (accessibility, keyboard and screen-reader users). Disposition: **for the owner to classify** (see the decision below). Trigger: any decision to change `useModalDialog`'s handling of a detached opener, a reported loss of focus after a confirmation, or a change to how a list re-reads under a dialog.
3. **G6-D02.** Disposition: *Investigate: not reproduced.* Add the 30 quiet and 24 loaded passes and the reading of `shell/viewport.ts`. Trigger: any recurrence in an acceptance run, with its trace kept.
4. **G6-D04.** Replace the scope with the measured one (1102 E2E-named People, 175 with Accounts, 180 Accounts) and the weak assertion; disposition **Accept** (development data); trigger as above.
5. **G6-D03, G6-D05, G6-D06.** No change to disposition or trigger.

## Owner decisions needed

1. **The product gap behind G6-D01.** Choose one: (a) **accept** it as a latent LOW and register it as above; (b) make the dialog hand focus to a stable place when its opener has gone; (c) keep the list on screen while it is re-read so the opener is not replaced. (b) and (c) change what a user sees or where focus goes, so they need your ruling (DP2) and, for (c), a look at every re-read that sits behind a list. I recommend (a) for G6 and a decision at the next change to the shared dialog hook. I did not choose.
2. **Whether to adopt the proposed register wording** above, or word it yourself, before WP6B finalizes the register.

## Verification

- **Run in WP6A:** the focused journeys above (`discussions.spec.ts` and `shell.spec.ts`, repeated, on the built-assets origin); one planted mutant on `useModalDialog.ts`, reverted (`git status` shows only the test file changed); the frontend gate `./flow check frontend` (strict TypeScript, ESLint, Prettier, Vitest, the production build and its verification) and `./flow check repo` after this record was written. Results are in the pull request description.
- **Not run, by design:** the full `./flow check all --pgsql`, the full `./flow test e2e --workers=4`, the recovery rehearsal and its mutation matrix, and the backend suite. They are reserved for WP6B, and nothing here touched the backend.
- **Scratch material removed:** the probe spec and the temporary delay hook were deleted before the corrected test was committed; the committed diff is the four lines above.

## Readiness for WP6B

**Ready**, with these notes for WP6B: (1) the full four-worker run is the first acceptance run since the correction, and is also the run in which G6-D02 last failed, so keep any trace of a failure; (2) the development database will keep growing (G6-D04); (3) the two owner decisions above should be settled before the register is finalized.
