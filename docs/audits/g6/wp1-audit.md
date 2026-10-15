# G6 WP1 — Identity, sessions, MFA and credentials: independent audit

## A. Executive verdict

The Identity security core is sound. I found **no BLOCKER and no HIGH**:

- Every Console capability requires a second factor.
- The session rotates at every privilege change.
- Revocation works through the security generation, which every authenticated request checks.
- Single-use guarantees hold under real two-process races on both engines.

There is **one MEDIUM** (WP1-01): changing your password needs only the current password, yet it restarts the 12-hour absolute lifetime of a second-factor session and ends the owner's other sessions. Someone holding a stolen session cookie and the password can therefore keep that session alive indefinitely. I proved this on both engines. It has been in production since before v0.3.0. The remaining items are LOW proof gaps, LOW documentation drift and INFORMATIONAL notes. Remediation is small and bounded; one item needs your ruling under DP2.

## B. Repository checkpoint

- **Audit pin:** `main` = `b5d37ad2e2823e184268d6cf43ea874784d88ff0` (PR #10, squash-merged 2026-10-15T08:25Z).
- **Working tree:** the local checkout is still on `docs/g6-wp0-entry-reconciliation` at `988b56d`, but its tree is byte-identical to `b5d37ad` (both are tree `44677f7`). I audited in place, so no worktree was needed.
- **Production:** v0.3.0 (`2f385b7`). WP1-01 is present there; I checked `v0.3.0:ConsoleSession.php:226-230` and `routes.php:35`.
- **Scope:** Identity, Access and Audit sources; the `Shared` Actor; the session middleware and its order in `bootstrap/app.php`; session, auth and identity config; the full route list from `artisan route:list`; ADRs 0016, 0020 and 0022–0025; identity-and-access, trust boundaries and the MFA-recovery runbook. Sourced-grant authorization is left to WP2.
- **No other agent session was working in this repository.**

## C. Findings (severity order)

### WP1-01 — MEDIUM — A password alone extends a second-factor session indefinitely and evicts the owner

**Location:**
- [ConsoleSession.php:226-230](apps/platform/app/Modules/Identity/Http/ConsoleSession.php#L226-L230): `reauthenticate()` sets `authenticated_at = now`.
- [ChangePasswordController.php](apps/platform/app/Modules/Identity/Http/ChangePasswordController.php) calls it.
- [routes.php:35](apps/platform/app/Modules/Identity/Http/routes.php#L35): `auth:web` only, with no second factor and no `security.verified`.
- [ChangePassword.php](apps/platform/app/Modules/Identity/Application/ChangePassword.php): the password policy accepts the same password text again.

**Invariants broken:**
- ADR 0016: the 12-hour cap is what makes a stolen cookie "bounded", not "indefinite".
- ADR 0023: self-service credential management needs the password **and** a second factor.
- ADR 0024: "a stolen session cookie must not be enough".
- The supporting rationale at [identity-and-access.md:351](docs/architecture/identity-and-access.md#L351) ("an attacker holding a session and the current password gains nothing… they already hold the credential") was written in Phase 5, before MFA. After Phase 7 the password alone is no longer the credential.

**Evidence:** a disposable probe, run and then deleted, passed on MariaDB and on PostgreSQL. It used a Guardian with TOTP, signed in with password and code:
1. After 11 hours of activity, `POST /password/change` with the **same** password, and no second factor, returned 204.
2. Twenty-two hours after the only factor proof, the session still passed `can:console.access`, still reported `via=session_second_factor`, and `/me` returned 200.
3. A second change returned 204 and ended the owner's freshly established session, while the attacker's session stayed live.
4. The step-up route still answered 403. Step-up-protected admin mutations stay out of reach.

The existing test `ChangePasswordTest.php:186` deliberately pins the restart, but only for a password-only Account.

**Failure scenario:**
- **Precondition:** an attacker obtains a Guardian's session cookie (malware, a shared or unattended device; it is HttpOnly and `__Host-`, so script injection cannot read it) and also knows the password.
- **Impact:**
  - Every 11 hours or so they "change" the password to itself, and keep indefinite access to People and CRM data, Discussions and Resources, including the step-up-exempt mutations.
  - Each change silently signs the owner out, and nothing looks wrong when the owner signs back in.
  - Each change records `password.changed`.
- **Stops at:** they never gain an admin mutation (step-up), and a password reset or an administrative MFA reset by the owner ends them.

**Smallest bounded remediation (choose):**
- **(a)** Do not restart `authenticated_at` on a password change. Keep the rotation, the rebind and the revocation of other sessions. This changes one documented behaviour.
- **(b)** Also require a second-factor proof in the body of `password/change` when the Account has an active factor, the same as recovery-code regeneration. This is a product change under DP2.

I recommend (a) now. (b) is your decision.

**Discriminating test:** with a second-factor session, change the password at 11 hours, advance 1 hour while active, and expect `/me` to return 401. It fails on today's code. For (b), additionally: a change with no factor proof is refused for an enrolled Account.

**Blocking:** under D4 this must be resolved, or accepted in writing, before G6 closes. It is not a regression in the release delta (v0.3.0 has it), but D10 ties the release to WP1's critical findings being resolved or accepted.

### WP1-02 — LOW — Throttles check, then count, in two separate steps

**Location:**
- [CacheAttemptThrottle.php:32-53](apps/platform/app/Modules/Identity/Infrastructure/Auth/CacheAttemptThrottle.php#L32-L53) and [CacheLoginThrottle.php:31-50](apps/platform/app/Modules/Identity/Infrastructure/Auth/CacheLoginThrottle.php#L31-L50) call `tooManyAttempts()` and then `hit()`, as separate cache operations (confirmed in Laravel 13.32's `RateLimiter`).
- The pending sign-in's 5-wrong-code counter ([ConsoleSession.php:190-202](apps/platform/app/Modules/Identity/Http/ConsoleSession.php#L190-L202)) lives in an unlocked session, so concurrent requests each read `failures = 0`.

**Invariant:** the per-Account code limit (10 per 15 minutes) bounds TOTP guessing once a password is known.

**Evidence:** code-evident only; I did not measure it. A burst of concurrent wrong codes can all pass the check before any hit lands, so each window allows roughly the server's worker concurrency in guesses, not 10. The same applies to login and step-up guessing.

**Impact:** a constant-factor weakening. It needs the password first, and passwords are at least 15 characters and breach-checked.

**Remediation:** count first and decide on the count that comes back (`hit()` returns the new total; refuse when it exceeds the limit). This also covers the pending counter, through the per-Account limit.

**Test:** a two-process race in the existing harness: with the limit at N, N+k simultaneous attempts are refused after N.

**Blocking:** not blocking.

### WP1-03 — LOW (documentation) — Stale identity documentation

- [identity-and-access.md:86](docs/architecture/identity-and-access.md#L86) and [:212](docs/architecture/identity-and-access.md#L212) describe an email change, with its session invalidation and `email changed` event. **Nothing changes an Account's login email:**
  - no use case does;
  - `accounts` is written only by Identity;
  - CRM contact methods are separate data.

  This is unbuilt design, not a runtime defect. If it is ever built, it must join ADR 0025's table.
- [:5](docs/architecture/identity-and-access.md#L5) says "Phases 1–7 done"; Phases 8 and 9 are done.
- [:351](docs/architecture/identity-and-access.md#L351) is stale; it goes with WP1-01.
- [mfa-recovery.md:60](docs/runbooks/mfa-recovery.md#L60) says "a very narrow window remains", but ADR 0025 closed that window.

**Remediation:** documentation edits only.

### WP1-04 — LOW (proof gaps) — Properties implemented but not directly tested

| Property | Evidence today |
| --- | --- |
| A pending sign-in is refused by `/security/verify`, `mfa/recovery-codes`, `mfa/authenticator` and `mfa/authenticator/confirm` | Only anonymous callers are tested. **My probe B: all 401** |
| The session id rotates at the 202 password step and at recovery-code completion | Only row counts are tested. **My probe C: rotated, old cookie dead** |
| The session id rotates at enrolment confirm | Same code path (`establish`), not asserted |
| Enrolment-confirm lockout after 5 wrong codes | Code calls `recordPendingFailure`; no test |
| The 60-second step-up skew edge | Only a one-day future case is tested |
| The breached-password timeout value | Only a connection failure is simulated |
| The generation advances through the admin HTTP MFA reset and through `identity:reset-mfa` | Only `fromServer` is asserted directly; both are covered by the shared class and the architecture pin |
| A revoked administrator's live session is refused by a real `/admin` mutation | Proved on a probe route (`zz/assign`) only |

**Remediation:** add only the tests that would discriminate a plausible regression. My picks are probe B, enrolment lockout, the skew edge, and the HTTP MFA reset advancing the generation.

### WP1-05 — INFORMATIONAL — `Actor::$authenticatedVia` is read by nothing (G7 entry condition)

This is **not a present defect** under today's catalog:

1. Every role grants `console.access` ([Role.php](apps/platform/app/Modules/Access/Application/Role.php)).
2. The MFA policy requires a second factor for any `console.access` holder, and fails closed if no policy is registered.
3. `EnforceSecondFactorWhereDue` ends a password-only session on its next request once it gains Console access, whether by direct role or by sourced grant (the Authorizer unions both).
4. All 96 `/admin` routes carry `can:console.access`. The only authenticated routes without it are `/me`, `/my/membership` (the caller's own record), `password/change` and the MFA and step-up endpoints.

This safety depends on invariant 1, which is WP2's planned test. Any future role or service identity without `console.access` would make `authenticatedVia` load-bearing. Record it under WP5 condition 3.

## D. Authentication and session lifecycle

**Verified:**
- The password step rotates the session (`holdPending` → `invalidate`, then a new token).
- Sign-in completion rotates the session id, destroys the old row and issues a new CSRF token (`loginUsingId` → `regenerate(true)` in Laravel 13.32).
- Step-up, password change and authenticator confirm all rotate with `regenerate(true)`.
- A pending sign-in is not authentication: `/me`, the gates, `password/change` and the MFA management routes all answer 401.
- Logout destroys the row; replaying the old cookie answers 401.
- The middleware order (session → CSRF → absolute lifetime → second factor due → generation → authentication) is pinned by a test.
- Cookie: `__Host-`, Secure, HttpOnly, SameSite=Lax, no Domain, fixed in config.
- CSRF refuses `same-site` and `cross-site` requests without a token.
- A disable racing a sign-in is serialised and refused, proved by race tests on both engines.

**Throttles:**
- Login: 30 attempts per IP and 5 failures per HMAC'd identifier, per 15 minutes.
- MFA: 10 per Account and 30 per IP, per 15 minutes.
- Step-up: 5 per Account and 20 per IP, per 15 minutes.

**Client IP:**
- With no trusted proxies, `$request->ip()` is `REMOTE_ADDR`. That is correct for the measured direct-to-origin topology, and no caller can spoof it.
- Putting a proxy or CDN in front is a deployment decision (G7 condition 5), not a defect.
- In development the gateway is the only source address; that is the safe direction.

**Gap:** WP1-01 (absolute lifetime). WP1-02 (throttle atomicity).

## E. MFA and step-up

**TOTP:**
- 160-bit secrets, encrypted under `APP_KEY`.
- A window of ±1 step; each step is accepted once (`last_used_step`, read under the Account lock), with a race test.
- Enrolment is proof-before-store: the factor is stored only after a code from it is proved.

**Recovery codes:**
- 80-bit codes, stored as a SHA-256 digest bound to the Account.
- Consumed by one conditional `UPDATE`; single use is race-tested.

**Step-up:**
- Fifteen minutes with a 60-second skew allowance, failing safe on a missing or future instant.
- Stored on this session only, and set only by password plus factor.
- Every Identity/Access admin mutation requires it, checked by a route sweep.

**Management:** recovery-code regeneration and authenticator replacement both need password plus factor in the body.

**No bypass found:**
- Wrong-step calls are refused (`WrongStep`).
- A credential changed mid-flight is caught by the HMAC credential marker, which holds even within the same second.
- An MFA reset defeats a pending challenge.
- A password-only session cannot become privileged.

**MFA reset vs password recovery:**
- MFA reset leaves the password unchanged. Password reset leaves MFA in place, so an emailed reset alone cannot take over an enrolled account.
- After an MFA reset, anyone holding the password can enrol first. That is inherent in the design, and the runbook's step 1 (verify the requester through a trusted channel) is the control.

## F. Security generation coverage

| Operation | Revokes sessions | Advances generation | Correct per ADR 0025 |
| --- | --- | --- | --- |
| Disable | all | yes | ✓ |
| Password reset | all | yes | ✓ |
| MFA reset (admin, server) | all | yes (shared class; WP1-04) | ✓ |
| Password change | all but the caller's | yes, and rebinds the caller | ✓ (but see WP1-01) |
| Authenticator replacement | all but the caller's | yes, and rebinds the caller | ✓ |
| Role revoke | none | no | ✓ authority is re-read live; proved on a probe route |
| Recovery-code regeneration | none | no | ✓ deliberate per the ADR |
| Enable, accept invitation, step-up | — | no | ✓ |
| Email change | — | — | not built (WP1-03) |

Enforcement:
- `establish` refuses a superseded generation, and `EnforceSecurityGeneration` refuses one before authentication, failing safe.
- An architecture test pins the revoke/advance pairing in both directions.
- Race tests cover TOTP, recovery-code, disable and reset overtaking a sign-in.

## G. Credentials and recovery

**Password policy:**
- One policy, with NFC normalisation, at least 15 code points and at most 72 bytes, refused rather than truncated.
- Verification refuses NUL bytes and anything past 72 bytes.

**Breach check:**
- It fails closed: every transport error becomes a 503, and nothing changes.
- A 3-second connect and request timeout.
- It always runs before the transaction (tested per endpoint).

**Invitations:**
- 256-bit token, only its SHA-256 stored, 7-day expiry.
- Claimed under a lock on the invitation and then the Account, so single use holds (race-tested).
- An Account must still be `invited`, so acceptance can never resurrect a disabled one.

**Reset tokens:**
- Laravel's hashed repository, keyed by canonical email, 60-minute expiry.
- Deleted inside the reset transaction, together with every session and the generation advance.
- Enumeration: one 202 answer, a 1.5-second floor, and the same hashing work on every path.
- Rate limits per IP and per identifier.

**Operator reset:**
- Needs the capability and step-up, plus a per-target limit.
- No secret reaches the operator, the audit trail or the logs.

**No secret leakage found:** the audit context refuses secret-shaped keys and values, and tokens and codes are `#[SensitiveParameter]` and redacted.

## H. Account lifecycle

- **Disable:**
  - The guard chain runs first and takes the administrator locks in a fixed order, then the Account is locked and re-read.
  - Sessions are deleted and the generation advanced, in one transaction.
- **Last administrator:** "active" means holding the role on an Account that can authenticate. The check refuses to run outside a transaction, and all races are tested on both engines: revoke vs disable, both orders, and three administrators.
- **Bootstrap:**
  - Refused once any administrator exists, except as recovery.
  - The token is printed only after the commit.
  - The race between two bootstraps at the same instant is a documented, accepted operator-trust residual.
- **Self MFA reset:** refused.
- **Stale Actor:** the Authorizer re-resolves the Actor on every call, so no snapshot of authority survives.

## I. Security events

**Required events are present and atomic:**
- Each identity mutation records its event in the same transaction, with rollback tests.
- The actor is null for unauthenticated and server operations; the subject is the affected Account.
- `session.superseded`, `session.second_factor_required` and `session.absolute_expired` are recorded.
- Rate-limit events are recorded once per minute per key.

**No missing contract-required event, except:**
- email change, which is not built;
- individually audited failed step-up or current-password attempts, which are documented as a residual and are not required.

## J. Current defects vs G7 triggers

**Present:** WP1-01 (MEDIUM), WP1-02 (LOW), WP1-03 (documentation), WP1-04 (proof gaps).

**G7 entry conditions, not defects:**
- `authenticatedVia` for non-Console callers (WP1-05).
- Trusted proxies, throttle keys and audit IP attribution.
- Any role without `console.access`.

## K. Test evidence

**Executed, focused on the security core, at `b5d37ad`:**

| Engine | Tests | Assertions | Exit | Duration |
| --- | --- | --- | --- | --- |
| MariaDB | 1303 passed | 6526 | 0 | 202 s |
| PostgreSQL | 1303 passed | 6526 | 0 | 222 s |

The set was:
- the Identity, Access and Audit feature tests;
- the Identity and Audit unit tests;
- six race suites: AdministratorRemoval, Credential, Mfa, MfaRecovery, OperatorAdministration and StaleAuthentication;
- seven architecture suites: Identity, Access, Mfa, SecurityGeneration, Audit, MembershipTrust and ModuleBoundaries.

**Disposable probes A, B and C:** 3 passed with 85 assertions on each engine; file deleted.

**Inspected:** the full test inventory mapping each property to its tests.

**Not run:**
- the full dual-engine gate and the browser suite;
- a measured burst for WP1-02;
- mutation experiments (deferred to remediation, per the brief).

## L. Remediation proposal (ordered)

1. **R1 (WP1-01):** stop restarting `authenticated_at` on password change. Flip `ChangePasswordTest:186` and add the second-factor-session regression test. Rule on option (b) first (M1).
2. **R2 (WP1-02):** atomic count-then-decide in both throttles, with a two-process race test.
3. **R3 (WP1-04):** the four discriminating tests named above.
4. **R4 (WP1-03):** the documentation corrections, together with R1's documentation change.

R1 alone gets the focused Opus re-verification the contract requires for a MEDIUM.

## M. Owner decisions

- **M1 (DP2):** should `password/change` on an enrolled Account also require a second-factor proof (option b), or only stop extending the session (option a)? If you choose neither, accept WP1-01 in writing (DP4).
- **M2:** confirm WP1-05 is recorded as a G7 entry condition, not a WP1 fix.

## N. Repository integrity

- No tracked file was changed.
- One untracked probe file was created and then deleted; `git status` is clean.
- No branch, commit, push, PR, migration change, deployment or production request was made.
- The tests touched only the `flowlife_test` databases.
- Outside the repository, I updated my G6 memory note: PR #10 is merged, and this audit is awaiting triage.

**G6 WP1 AUDIT: READY FOR OWNER TRIAGE**
