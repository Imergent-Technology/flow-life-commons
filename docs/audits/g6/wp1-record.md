# G6 WP1 — remediation record

- **Package:** WP1 Identity, sessions, MFA and credentials. Remediation of the [independent audit](wp1-audit.md) as ruled in the [owner's triage](wp1-triage.md).
- **Base:** `main` at `b5d37ad2e2823e184268d6cf43ea874784d88ff0` (PR #10). **Branch:** `fix/g6-wp1-identity-session`. Local only: not pushed, no PR, nothing deployed. Production stays on v0.3.0, which has the flaw.
- **Status:** implemented and validated; **awaiting the independent Opus verification of WP1-01** that the triage requires, and then the owner's integration decision.
- **Audit file:** committed as `wp1-audit.md` from its heading (`# G6 WP1 — Identity…`) to its closing verdict line, unedited. The one-line "Written for:" hand-over note that preceded the report was addressed to the owner, not part of the report, and is not in the file.

## 1. WP1-01 (MEDIUM): what changed

| Ruling | Where it is enforced |
| --- | --- |
| 1. The session keeps its original `authenticated_at` and absolute expiry, for enrolled and password-only Accounts alike | `ConsoleSession::reauthenticate()` is replaced by `rotate()` (its only caller was the password-change controller): `regenerate(true)` and nothing else. Only `establish()` writes `authenticated_at` |
| 2, 3. An Account with an active authenticator presents the current password AND a fresh `code` or unused `recovery_code` | `ChangePassword` takes an optional `SecondFactorProof`; the factor is checked by `SecondFactorVerifier` through the new `SecurityProof::confirmSecondFactor()` (the step `SecurityProof::run()` always took, extracted so a caller that owns its transaction and rate limit can use it; `run()` behaves identically) |
| 4. No authenticator: the current password alone, including `/my/security` | The factor block is skipped; a code an unenrolled Account sends is not looked at |
| 5. Enrolment is read from the server, under the Account lock | Inside the change's transaction, after `findForUpdate()` and the password check, `TotpFactorRepository::findByAccount()->isActive()`. The request's shape never decides it (`code`/`recovery_code` are optional in `ChangePasswordRequest`) |
| 6. Existing effects kept | new password, `revokeAllExcept`, generation advance and rebind, rotation with a new CSRF token, `password.changed` in the same transaction |
| 7. A failed change changes nothing and spends nothing | One transaction. A spent TOTP step or recovery code (and its `mfa.recovery_code_used` event) is a write inside it and rolls back with any later failure. The password is verified **before** the factor, so the factor is never an oracle without the password |
| 8. Not a new sign-in; the verification window is not widened | `security_verified_at` is not touched either; the success message and panel description say the time limit is unchanged |

Other changes: `SecondFactorFailure::CodeRequired` and `MfaProblems::codeRequired()` (an enrolled Account that sent no code gets a 422 on `errors.code` in words); a refusal is audited after the rollback as `mfa.challenge_failed` with `during: password_change`; one rate-limit allowance (`password_change`) covers password and factor, each request counting once.

**Other callers of the changed session method:** `reauthenticate()` had exactly one (the password-change controller). `establish()` (sign-in, MFA completion, enrolment confirm) is unchanged and still starts a lifetime; step-up (`markSecurityVerified`) and authenticator confirmation already left `authenticated_at` alone.

## 2. Original failure, and the fix

The regressions were written first and run against the original `app/` tree (`git checkout b5d37ad -- apps/platform/app`, then restored). **17 of 60 tests failed, with the identical list on MariaDB and PostgreSQL** (`ChangePasswordSecondFactorTest`, the flipped `ChangePasswordTest` lifetime test, and `MfaRaceTest`):

- `it does NOT extend the 12-hour lifetime of a second-factor session, even with a fresh factor proof` — the discriminating test of the audit: sign in with a second factor, advance 11 h while active, change the password with a fresh code, expect the original `authenticated_at`/expiry and a 401 an hour later. Original code: `authenticated_at` had moved 11 h forward.
- `it does NOT restart the 12-hour authentication instant: a password change is not a sign-in` (password-only Account; the old test pinned the restart).
- the enrolled-Account tests: password alone refused; recovery code spent once; TOTP step spent; wrong/malformed/both factors refused; code asked of an Account enrolled after sign-in; no-proof at the use case; one count per attempt; allowance stops factor guessing; rollback of a spent recovery code.
- three of the races (one recovery code or one TOTP step across two changes; an enrolment committing while a password-only change waits).

After the fix all pass. Tests that pass on the original code (for example "does not widen the verification window", "spends neither a code…when the password is wrong") are guards on the new code, and were verified by mutation (§3).

## 3. Mutation results

Each violation was planted as an uncommitted edit, the named tests were run, and the file was restored (`git status` clean after each). The per-mutation log files were lost in a host reboot during the browser runs; the results below were read from the runs themselves.

| # | Planted violation | Engine | Result | Failing tests (first) |
| --- | --- | --- | --- | --- |
| W1 | `rotate()` also re-dates `authenticated_at` (the original flaw) | MariaDB | killed (2) | `ChangePasswordTest › does NOT restart…`; `ChangePasswordSecondFactorTest › does NOT extend…` |
| W2 | factor check disabled | MariaDB | killed (14) | `refuses an enrolled Account the password alone`, recovery code, TOTP replay, … |
| W3 | enrolment decided by the request (`$proof !== null &&`) | MariaDB | killed (4) | `refuses an enrolled Account the password alone`; `reads enrolment from the Account…`; the enrolment race |
| W4 | factor judged before the password | MariaDB | **survived the first run**, killed (1) after `judges the factor only after the password…` was added | the new test. Atomicity was never at risk (the transaction rolls the spent code back); the gap was the oracle property: a wrong code was reported, and audited, to a caller without the password |
| W5 | `findForUpdate` → `find` (no Account lock) | MariaDB and PostgreSQL | killed (8 on each) | both replay races, the enrolment race, the reset race, the disable race, the password-replaced race |
| W6 | refusal not audited | MariaDB | killed (2) | `refuses an enrolled Account the password alone` (the `mfa.challenge_failed` assertion); the recovery-code race |
| W7 | attempt counted twice | MariaDB | killed (4) | `counts each attempt once…`; `stops guessing at the factor…`; the existing per-account limit test |
| W8 | `regenerate(false)` (old session row survives) | MariaDB | killed (2) | `ends every OTHER session…`; `ROTATES it: a new id…` |
| W9 | factor checked and spent in a transaction of its own that commits first | MariaDB | killed (8) | `rolls a spent authenticator step back…`; `rolls a spent recovery code back…` (both faults); `spends neither a code nor a recovery code when the current password is wrong` |
| P1 | a pending sign-in is treated as logged in (`holdPending` calls `loginUsingId`) | MariaDB | killed (44) | `is refused by every credential-management and step-up endpoint…` (new) among the existing sign-in tests |
| P2 | enrolment-confirm failures not counted | MariaDB | killed (2) | `ends the pending enrolment after the configured number of wrong codes` (limit 3 and 5) |
| P3a | skew check `>` → `>=` | MariaDB | killed (1) | `trusts a verification instant up to 60 seconds ahead…` |
| P3b | skew allowance 0 | MariaDB | killed (1) | same |
| P3c | maximum age `>=` → `>` | MariaDB | killed (2) | `ends a verification at exactly its maximum age…` (new) and the existing 15-minute test |
| P3d | skew allowance 3600 | MariaDB | killed (1) | `trusts a verification instant up to 60 seconds ahead…` (the 61-second row) |
| P4 | `ResetMultiFactor` no longer advances the generation | MariaDB | killed (2) | `advances the target's security generation, so even a session row that outlives the reset is refused` (new); `ResetCommandTest` generation assertion (new) |

One note on P1/P4: the new endpoint test also relies on the controllers' own `NoLongerAuthenticated` guard, so removing `auth:web` from the route group alone is not visible to it (defence in depth: the behaviour is still a 401). The planted violation that makes a pending sign-in count as authentication is killed.

## 4. Concurrency (two processes, both engines)

New races in `tests/Concurrency/MfaRaceTest.php` (the harness pauses the first process inside its open transaction and runs a worker against the same database; every race has its "blocked" assertion and a control that proceeds):

1. two simultaneous password changes present one **recovery code**: exactly one succeeds, one `mfa.recovery_code_used`, 9 codes remain;
2. the same for one **authenticator time step**;
3. an **enrolment** commits while a password-only change waits on the Account: the change is refused (the Account is read as it is now) and the password is unchanged;
4. its control: an unrelated write to the Account row leaves the password-only change to succeed after waiting;
5. an **MFA reset** that commits first: the change needs no factor and succeeds;
6. a **disable** that commits first: refused with `NoLongerAuthenticated`, recovery code unspent;
7. a **password reset** that commits first: refused on the password, recovery code unspent.

Focused run on PostgreSQL (`Credentials`, `SecurityGenerationTest`, `Mfa`, `MfaRaceTest`, `CredentialRaceTest`): 375 passed. Both engines ran in the full gate below.

## 5. Console, Member and OpenAPI

- `ChangePasswordPanel` (shared by Account security and `/my/security`): the authentication-code field (with the existing "use a recovery code instead" switch) appears between the current and new passwords only when `/me` says `mfa.enrolled`, or after the server asked for a code. The code is sent only while shown, dropped after any failure, and every field is cleared after success; focus goes to the refused field; the button is disabled while pending; `one-time-code`/`current-password`/`new-password` autocomplete. The server stays the authority; `/me` only decides what is shown.
- New Vitest: wire payloads for TOTP and recovery code (and that the other key is absent), switching, field order, refused code and refused recovery code, focus, wrong password with a code, in-flight disabling, the server asking for a code `/me` did not mention, no logging or storage of the code, and the Member page for an enrolled and an unenrolled Account. The old "session re-dated" assertions were flipped.
- Playwright: `console.spec` `CHANGER` (the Account has an authenticator) now covers the missing code (stopped by the browser's required field), the wrong code (refused on its field, dropped), the right code, and the **unchanged start and end times**; `credentials.spec` asserts the instant and expiry are unchanged; `member.spec` asserts no code field for the unenrolled Member.
- OpenAPI `POST /password/change` and `ChangePasswordRequest` describe the conditional factor, server authority, the unchanged lifetime, atomicity and the new 422 on `errors.code`.

## 6. WP1-04 tests added (no production change)

| Property | Test | Planted violation that fails it |
| --- | --- | --- |
| Pending sign-in refused by `security/verify`, `mfa/recovery-codes`, `mfa/authenticator`, `mfa/authenticator/confirm`, with valid proof, and nothing spent | `ChallengeTest` (pending challenge), `EnrollmentTest` (pending enrolment) | P1 |
| Enrolment-confirm lockout at the configured limit (3 and 5), and one fewer still enrols | `EnrollmentTest` | P2 |
| Step-up skew (0/60/61 s) and maximum age (14:59/15:00/15:01) | `SecurityVerificationTest` | P3a–d |
| Admin HTTP MFA reset advances the generation and a surviving session row of a no-Console Member is refused (`session.superseded`); `identity:reset-mfa` advances it too | `MfaResetTest` (Access), `ResetCommandTest` | P4 |

The audit's other proof gaps (session-id rotation at the 202 step, breach-timeout value, a revoked administrator's live session on a real `/admin` route) were not in the triage's four and were not added.

## 7. Validation

| Gate | Result |
| --- | --- |
| `./flow check all --pgsql` (at `ea4dc40`) | **green**: Pint, Larastan level max (tests included), Pest on MariaDB **3331 passed, 21,760 assertions, 585 s**; Pest on PostgreSQL **3331 passed, 21,757 assertions, 679 s**; ESLint, Prettier, TypeScript strict, Vitest **2276 passed (89 files)**, production build, `verify:build`, repo checks |
| After the e2e/doc edits that followed | `./flow check repo` green; `./flow check frontend` green (Vitest 2276 passed) |
| `./flow test e2e --workers=4` (built-assets origin, development-gateway project, maintenance pass, leftovers pass, `relationships:check`) | **green on the fourth full run: main pass 448 passed (7.3 m), maintenance 10 passed, leftovers 1 passed, relationships consistent** |

**The earlier runs, not discarded:**

1. *Run 1 — 445 passed, 4 failed.* All four were journeys that changed the password of an Account that has an authenticator and so are the new requirement working, not flakes: `console.spec` "ended session" and "changing a password" (no code typed, and the form's native required field stopped the submit), `mfa.spec` "pending sign-in" (its API password change sent no factor), and `mfa.spec` "a later sign-in" (`getByText('Authenticator app')` now also matched the new field's hint; made exact). The journeys were updated; Vitest `session.test.tsx` had the same cause (fixed in the same way).
2. *Run 2 — killed.* The host rebooted mid-run (exit 137, the Docker stack went down); no result. It had already shown one failure of **my own** new `mfa.spec` journey: it reused `e2e.ui.change`, the Account the existing `console.spec` CHANGER journey owns, so the two collided. I removed it and folded its assertions into the CHANGER journey.
3. *Run 3 — 448 passed, maintenance 10 passed, leftovers failed.* The leftovers pass found 12 Packs and 8 Categories ("E2E Pack …", the long-title ones) left by the interrupted run 2. Classified as residue of the killed run, not a leak by a journey: removed through `DeletePack`/`DeleteCategory`, and run 4 was the complete rerun.

## 8. Deferred and accepted

- **WP1-02** recorded as [G6-D10](debt-register.md) (endpoints, cache behaviour, prerequisites, triggers). No limiter semantics were changed. Nothing found in this work raised its severity.
- **WP1-05** recorded as [G6-D11](debt-register.md), a G7 entry condition. `Actor` was not touched.

## 9. Documentation changed (WP1-03)

`identity-and-access.md` (status line; the email-change row and the audit-event text marked unbuilt and CRM contact emails distinguished; the absolute-lifetime row; Phase 5 text superseded by WP1-01 kept as history and marked; a new "G6 WP1 corrections" section), ADRs 0016, 0023, 0025 (dated amendments), `mfa-recovery.md` (the "narrow window" closed by ADR 0025), `testing.md`, the OpenAPI description, and the debt register.

## 10. For the independent Opus reviewer

Verify, from the code at the branch head, not from this record:

1. `ChangePassword::change()`: the order lock → can-authenticate → password → enrolment read → factor → save → revoke → advance → audit, all in one `transaction()`, and that nothing outside it spends a factor.
2. `SecurityProof::run()` is behaviourally unchanged for recovery-code regeneration, authenticator replacement and step-up.
3. `ConsoleSession::rotate()` cannot change `authenticated_at`, `second_factor_verified_at` or `security_verified_at`; `establish()` is the only writer of the first.
4. The refusal audit (`mfa.challenge_failed`, `password_change`) is written after the rollback, and no secret reaches any event.
5. The throttle counts once per request and still precedes the policy and breach check.
6. Whether `MfaProblems::codeRequired` (a different message from "invalid code") leaks anything beyond what `/me` already tells the signed-in person.
7. Residual: a request that has passed the generation check completes with its authority even if a concurrent reset commits mid-request (the ADR 0025 known limit); unchanged by this work.
