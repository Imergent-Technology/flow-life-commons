# G6 WP1 — Independent remediation verification

## A. Executive security verdict

The remediation meets both protections the owner approved, and I found no new BLOCKER, HIGH, MEDIUM or LOW findings.

- **Absolute lifetime:** a password change no longer restarts `authenticated_at`. This holds for MFA-enrolled and password-only Accounts.
- **Fresh second factor:** an Account with an active authenticator must present the current password **and** a fresh code or unused recovery code. The server decides enrolment under the Account's row lock.
- **Atomicity:** the factor is spent inside the same transaction as the password change, so any later failure rolls it back.

I checked all of this from the code and with my own runs, not from Sonnet's record. I reproduced the original failure on both engines and killed five mutants I wrote myself. Only informational notes remain (section K).

## B. Repository baseline and reviewed commit

- **Base:** `origin/main` = `b5d37ad2e2823e184268d6cf43ea874784d88ff0`, confirmed as the merge base. Your local `main` ref is stale at `9a79b38` (one behind origin); that is harmless.
- **Reviewed:** `fix/g6-wp1-identity-session` at `dc3c6492fe0927a7505fee10e149749fd4e0bc50`, six commits from `d539ebe` to `dc3c649`. I reviewed the full `b5d37ad..dc3c649` diff: 39 files, +1882/−137.
- **State:** the working tree was clean, nothing is pushed (the branch is not on the remote), and there is no PR. No other agent session was working in this repository.
- **Production:** I made no production request. It remains v0.3.0 as recorded.

## C. WP1-01 verification

### Session lifetime

- **`ConsoleSession::rotate()`** is now only `regenerate(true)`. In Laravel that means a new session id, a new CSRF token and the old row destroyed. Session attributes migrate unchanged, so `authenticated_at`, `second_factor_verified_at` and `security_verified_at` all carry over.
- **Only one writer of `authenticated_at`:** `establish()` (`ConsoleSession.php:88`). `reauthenticate()` had one caller, the password-change controller, and that caller now uses `rotate()`.
- **Other session callers are unaffected:**
  - Step-up and recovery-code regeneration call `markSecurityVerified`.
  - Authenticator confirm calls `rebind` and then `markSecurityVerified`.
  - Neither path touches `authenticated_at`.
- **Generation rebind** is unchanged. It binds the generation that this transaction committed, so a reset committing afterwards still ends the session.
- **Near expiry:** the absolute-lifetime middleware runs before the controller. A change at 11:59 completes and the session still dies at 12:00. Both tests that prove this (one MFA, one password-only) fail if the re-date is reintroduced (M1).

### Conditional MFA

- Enrolment is read in `ChangePassword.php:129` after `findForUpdate` and after the password check. `code` and `recovery_code` are only optional *shape* in the request, so the client never decides whether a factor is needed.
- A missing proof throws `CodeRequired`.
- An unenrolled Account's proof is ignored, so ordinary Members keep the password-only flow. No `console.access` requirement was added; the route is still `auth:web` only.
- Tests cover:
  - the password alone being refused even on a fresh second-factor, step-up-verified session (so an earlier session factor or `security_verified_at` cannot stand in for the proof);
  - TOTP and recovery codes both working;
  - wrong, malformed, both-at-once and replayed codes being refused.

### Atomicity and order

`change()` runs entirely in one transaction, in this order:

1. lock the Account
2. check it can still sign in
3. verify the password
4. read enrolment
5. confirm the factor
6. save, revoke other sessions, advance the generation, write `password.changed`

The TOTP step and the recovery-code consumption are writes inside that transaction. The breach check and hashing run before the transaction, so no database lock is held during network or slow work.

## D. Existing `SecurityProof` consumers

`confirmSecondFactor()` is an exact extraction. It runs the same `verify()` call, throws the same `InvalidCode` with `$proof->method`, and records the same `recoveryCodeUsed` event, with `$during` now passed in (`run()` passes `'security_verification'`).

`run()` keeps everything else as it was:
- its own throttle (`SecurityVerification`);
- the lock-and-password order;
- the `challengeFailed` audit after rollback.

Recovery-code regeneration, authenticator replacement and step-up behave as before. All Identity/MFA suites pass on both engines.

## E. Concurrency and replay safety

- **Lock order is consistent:** every writer of the authenticator or recovery codes locks the Account first. That covers enrolment confirm, replacement, sign-in completion, MFA reset, disable, password reset and password change, so nothing can deadlock against this path.
- **MariaDB snapshot timing:** the transaction's first statement is the locking read. The later plain read of the factor therefore takes its snapshot *after* the lock wait, so an enrolment that commits while the change waits is visible to it.
- **Seven new two-process races** pass on both engines in my run. M4 and M5 (section G) show they discriminate.
- **Residual (ADR 0025, unchanged):** a request that has already passed the generation check finishes with its authority even if a reset commits mid-request.

## F. Disclosure, audit and throttle assessment

- **`code_required` disclosure:** it is returned only after the correct current password. It says the Account has an authenticator, and `/me` already reports `mfa.enrolled` to every signed-in Account (`CurrentAccountPresenter.php:48`), so it reveals nothing new.
- **No secrets in audit events:**
  - Event context holds only `reason`, `during` and `remaining`.
  - Codes are `#[SensitiveParameter]`.
  - `SecurityEventContext` checks keys for secret fragments; the value `password_change` is allowed because only keys are checked for those words.
- **Audit outcomes are correct:**
  - A refusal is recorded as `mfa.challenge_failed` with `during: password_change`, written after the rollback.
  - `password.changed` is written only on success.
  - A spent recovery code is recorded as `mfa.recovery_code_used`, and that event rolls back along with a failed change.
- **Throttle:** each request counts once against `password_change`, still before the policy and breach check. Nothing is counted twice, and the `security_verification` allowance is not touched.

## G. Backend test and mutation evidence

I ran all of this in a detached scratch worktree at `dc3c649`, inside throwaway containers against the `flowlife_test` databases.

**Focused security set** (Identity feature/unit tests, Access `MfaResetTest`, 4 race suites, 4 architecture suites):

| Engine | Result |
| --- | --- |
| MariaDB | **956 passed**, 4,661 assertions |
| PostgreSQL | **956 passed**, 4,661 assertions |

**Original application tree** (`b5d37ad` app code with the new tests): **17 failed, 44 passed, with the identical list on both engines.** That matches the record's 17; there are now 61 tests because the later ordering test passes on the old code. The failures are for security reasons, not response shape:
- `204` returned where `422` was required (the password changed with no factor);
- `authenticated_at` differs after the change;
- a recovery code left unspent (`10 ≠ 9`).

**My mutants:**

| Mutant | Engines | Killed by |
| --- | --- | --- |
| M1: `rotate()` re-dates `authenticated_at` | MariaDB | 2 tests: the password-only and MFA lifetime tests |
| M2: password and factor checked, and the factor spent, in a separate committed transaction | both | 3 tests: the TOTP step advanced (`…640 → …641`) and a recovery code dropped (`10 → 9`) although the change failed |
| M3: factor judged before the password | MariaDB | 1 test: a wrong password got back "The code is not valid", which makes the factor an oracle |
| M4: `findForUpdate` → `find` | both | 6 races; the 7th is a control that is meant to pass. A password-only change got past an enrolment, a disable and a password reset (`exit 0` where `2` was required) |
| M5: enrolment read before the lock | both | 2 races: the enrolment race (change succeeded without a factor) and the reset race |

Every restore was verified clean with `git status`.

## H. Shared UI, OpenAPI and browser review

- **`ChangePasswordPanel`:**
  - shows the factor when `/me` says enrolled, or after the server asks for one;
  - sends the code only while its field is shown;
  - clears the typed value when switching between code and recovery code, so stale proof is never sent;
  - drops the code after any failure and clears every field after success;
  - focuses the refused field; the factor field uses `required` and is not tied to `/me`.
- **Wording:** the "time limit unchanged" text is accurate.
- **Vitest:** `credentials`, `SecurityPage` and `session` — **82 passed**.
- **OpenAPI:** the conditional factor, server authority, the unchanged lifetime, atomicity and the `422` on `errors.code` / `errors.recovery_code` all match the code.
- **Playwright:**
  - Test counts per spec are unchanged against base.
  - The CHANGER journey now covers a missing code, a wrong code (dropped and focused), and unchanged start and end times.
  - The `mfa.spec` pending journey uses a recovery code from its own fixture (`P`), not one shared with another journey.
- **Browser history:** I reviewed all four runs as recorded. Run 1's four failures came from the new requirement itself, and run 3's leftovers came from the killed run 2. Nothing gave me reason to distrust the final green run, so I did not repeat it.

## I. WP1-03 and WP1-04

- **WP1-03 (documentation):** these are now accurate:
  - ADR 0016 (amended), ADR 0023 (amendment), ADR 0025 (table and email-change note)
  - `identity-and-access.md` (status, email row, lifetime row, Phase 5 text marked superseded, new G6 section)
  - `mfa-recovery.md`, `testing.md`, OpenAPI
- **WP1-04 (proof-gap tests):** all four groups show the intended properties:
  - **Pending sign-in:** all four privileged endpoints answer `401` with valid proof, nothing is spent, and the sign-in can still be finished afterwards.
  - **Enrolment-confirm lockout:** tested at limits 3 and 5, plus the "one fewer still enrols" boundary.
  - **Step-up timing:** skew at 0, 60 and 61 seconds; maximum age at 14:59, 15:00 and 15:01.
  - **Admin MFA reset:** an HTTP reset ends a surviving no-Console Member session through `session.superseded`, and the `identity:reset-mfa` command also advances the generation.

  The record's mutants for these (P1–P4) are consistent with what I read.

## J. Deferred findings and debt tracking

- **WP1-02** is recorded as G6-D10 (defer), with endpoints, cache behaviour, prerequisites and triggers. No limiter semantics changed.
- **WP1-05** is recorded as G6-D11, accepted as a G7 entry condition. `Actor` was not touched.

Both match the triage.

## K. New findings

There are no new BLOCKER, HIGH, MEDIUM or LOW findings. These four informational notes need no action before the PR:

- **INFO-1 (code comment):** the comment at `routes.php:33-34` still says "authentication is the whole requirement, plus the current password in the body". It now leaves out the conditional factor. Fix it whenever that file is next touched.
- **INFO-2 (throttle budget):** `password_change` is a separate allowance from `security_verification`. Someone holding both a stolen session and the password therefore gets about 5 extra TOTP guesses per 15 minutes per Account, each with roughly a 3×10⁻⁶ chance. This is by design under "one allowance" and negligible.
- **INFO-3 (pre-existing password oracle):** a stolen-session holder can tell a correct password guess (`code_required`) from a wrong one. This is weaker than before (a correct guess used to change the password outright), matches `security/verify`, and is still limited to 5 guesses per 15 minutes.
- **INFO-4 (UI edge):** a whitespace-only recovery code is answered on `errors.code` while the recovery field is the one shown. The message still appears in the alert, and focus goes to the field.

## L. Scope and repository integrity

- I modified no tracked files and made no commit, push, PR, merge or deploy, and did not start WP2 or WP3.
- I ran mutations only in a scratch worktree, which I removed and pruned.
- The branch is still at `dc3c649` with six commits and a clean tree.
- I stopped the PostgreSQL test container I had started; it was not running before.
- Outside the repository, I updated my G6 memory note.

## M. Recommendation for PR preparation

Proceed to the owner's integration decision and PR preparation as the branch stands. No remediation is needed for Sonnet. INFO-1 can be folded into the PR or left for later. Release remains not authorized.

**G6 WP1: SAFE TO PREPARE PR**
