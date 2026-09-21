# ADR 0025: The Account security generation

- **Status:** Accepted
- **Date:** 2026-09-23
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0016](0016-guardian-console-same-origin-session-authentication.md), [ADR 0022](0022-password-policy-and-credential-handling.md), [ADR 0023](0023-multi-factor-authentication.md), [ADR 0024](0024-privileged-operator-administration.md)

## Context

Every operation that invalidates an Account's authentication — an administrative MFA reset, a password reset, an authenticated password change, a replaced authenticator, a disable — has until now expressed "and none of its sessions may be used again" as **deleting rows from the `sessions` table**, inside the same transaction that takes the Account's row lock. That is the whole reason sessions live in the database ([ADR 0016](0016-guardian-console-same-origin-session-authentication.md)).

Deleting rows is not sufficient, because of the one row that is not there to delete at the moment of deletion.

**The window [ADR 0024](0024-privileged-operator-administration.md) recorded as a residual.** Authentication proof and durable session establishment are on opposite sides of a transaction boundary, by design:

```
  CompleteSecondFactor      transaction: lock account, verify code, record login   COMMIT
  ConsoleSession::establish                                                              loginUsingId, write session payload
  StartSession (terminate)                                                                                       INSERT INTO sessions
```

An administrative reset committing anywhere in the gap finds no session row belonging to that Account, deletes nothing, and the already-completed challenge then creates one. The person who just lost their authenticator ends up with a live session established on it. Phase 8 stated the window plainly and left it open; the stated reason — that closing it would mean holding the Account lock across session establishment — is correct about *that* approach and is what this ADR replaces.

The general shape is: **an authentication established from security state that has since been invalidated**. The fix has to make the *authority* of a session, not the *existence* of its row, the thing that is decided under the Account lock.

**One adjacent worry was checked and dismissed.** A request already in flight when its session row is deleted might be expected to write that row back, since the framework persists the session on every request. It does not: Laravel's `DatabaseSessionHandler::write` issues an `UPDATE` for a session it has already read, and an update matching no row changes nothing. Revocation does stick against a concurrent request. The whole revocation design leans on that, so it is now pinned by a test rather than assumed.

Constraints that ruled out several shapes before anything was written: it must hold under concurrent requests on **MariaDB and PostgreSQL**; it must not require Redis, a process lock, a sleep, or an assumption that one server handles both requests ([charter](../architecture/charter.md): cPanel shared hosting, no resident daemons); and it must not become a session capability snapshot — authorization stays live through Access ([ADR 0008](0008-platform-owned-authorization.md)).

## Decision

**An Account has a monotonic `security_generation`. A session carries the generation its authentication proof was checked against, and has authority only while that is still the Account's current generation.**

That is the whole invariant, and it is the one sentence the system now has for *"authentication established from stale security state"*.

### Where the generation lives

A single unsigned integer column on `accounts`, defaulting to 1. It is deliberately **not** part of the `Account` aggregate: nothing in `Identity\Domain` names it, and no `AccountRepository::save` writes it. An aggregate is saved whole, and a whole-row save of a copy read a moment earlier would undo a concurrent advance — which is the very class of bug being closed here (Phase 4 found the same shape when a sign-in could undo a disable). It is reached through one narrow Application port, `AccountSecurityGeneration`, with `current()` and `advance()`.

`advance()` is a single `UPDATE accounts SET security_generation = security_generation + 1`. Both engines apply that to the row's current value, so two advances cannot land on the same number even without a lock; in practice every caller already holds the Account's row lock.

### Who advances it

Exactly the operations that revoke sessions, in the same transaction, beside the revocation:

| Operation | Sessions | Generation |
| --- | --- | --- |
| `DisableAccount` | all | advance |
| `ResetPassword` | all | advance |
| `ResetMultiFactor` (administrator, and from the server) | all | advance |
| `ChangePassword` | all but the caller's | advance, and **re-bind the caller's session** |
| `ConfirmAuthenticatorReplacement` | all but the caller's | advance, and **re-bind the caller's session** |

The pairing is the rule: *revoking sessions and advancing the generation are one act*. An architecture test enforces it, in both directions, so a sixth such operation cannot be added with only half of it.

Operations that do **not** advance it, on purpose: `EnableAccount` (it grants nothing to an existing session — a disabled Account's sessions were already revoked and its generation already advanced), granting or revoking a role (authorization is live, never snapshotted), regenerating recovery codes (no authentication factor is replaced), accepting an invitation (there is no session yet), and recording recent verification (`security.verified` proves freshness, it does not invalidate anything).

### Who reads it, and when

Every use case that finishes a sign-in already locks and re-reads the Account. Each now also reads the generation **under that same lock** and returns it: `AuthenticateAccount`, `CompleteSecondFactor`, `ConfirmTotpEnrollment`. So the value a session is bound to is the generation the proof was actually checked against — not one read afterwards, which would silently swallow anything that committed in between.

The two operations that keep the acting session return the generation **their own transaction committed**, and the transport binds that. Binding a value read after the commit would let the session survive a reset that landed in the gap.

### Two enforcement points, one preventive and one decisive

**`ConsoleSession::establish` refuses to create the session at all** when the Account's current generation is no longer the one proved. This is prevention: in the Phase 8 ordering — reset commits, *then* establishment runs — no session row is ever written, no cleanup is needed, and the caller is told the sign-in expired.

**`EnforceSecurityGeneration` ends the session on any request whose generation does not match**, before the authentication middleware. This is what makes the invariant provable rather than merely likely. The transport persists the session after the response is prepared, so a reset committing in that last instant still leaves a row; that row is refused on its first use, and no authenticated request can ever succeed with it. It fails safe: a session with no generation, a non-integer one, or one whose Account has gone, is superseded. It would also cover the in-flight case above, were the framework's session write ever to become an upsert, because the resurrected row would carry a stale generation.

Ending a superseded session records `session.superseded`, with the held and current counters (neither is a secret, and neither says which operation advanced it — that operation recorded its own event).

### The boundary, stated rather than implied

A session row **can** be created for a proof that has just been superseded, in the microseconds between `establish` checking and the transport writing the row. It is **never usable**: the first request that presents it is refused and the session is destroyed. This is documented and tested explicitly rather than left as an implication, because "prevented" and "guaranteed unusable" are different claims and only the second is true of that sliver.

One window is **not** closed, and is not a defect: a request that has already passed the check when a reset commits completes with authority. That is unavoidable for any revocation scheme short of taking a lock for the whole request, it is identical in extent to what row deletion gave, and it is bounded by the duration of one request.

## Consequences

- The system has **one understandable invariant** for stale authentication, above the four mechanisms (row deletion, status re-checks, the credential marker, factor-state re-checks) that each covered part of the ground. Those four are **not removed**: they are what makes a *pending* sign-in fail, and they refuse earlier and with better-fitting reasons. The generation is the backstop that covers what none of them can — the interval after a proof has committed and before its session exists.
- Every authenticated request costs **one extra primary-key read**. Measured against the alternative of an unclosable window, that is the trade taken.
- **Deploying the migration invalidates every session that existed before it.** Those carry no generation and the check fails safe. This is recorded in the [production readiness runbook](../runbooks/production-readiness.md) as an expected, one-time sign-out.
- A pending (half-finished) sign-in is still not bound to a generation. It does not need to be: it is not authentication, it holds no authority, and completing it goes through the locked re-read that reads the generation. What defeats a pending sign-in is unchanged.
- Nothing is snapshotted into the session but a counter. Capabilities, roles, status and factor state are still read live on every request.

## Alternatives considered

**Hold the Account's row lock across session establishment.** The honest fix for the ordering, and the one Phase 8 named. Rejected: the framework writes the session in `StartSession`'s terminate stage, after the response is prepared and long after any use case has returned, so the lock would have to be held across the whole response. That serialises unrelated requests, holds a database transaction open across user-visible work, and still would not cover the in-flight-write case.

**Store the session id in the use case and let the reset delete it.** Requires the transport's session id inside Identity's Application layer at sign-in time, which is exactly backwards (the id does not exist until the session is established), and still leaves the write-back case.

**A `sessions_valid_from` timestamp on the Account, compared against the session's `authenticated_at`.** Simpler to write, but a second's resolution is defeated by two operations in the same second — a defect this codebase has already been bitten by once, which is why the credential marker is a digest rather than `password_updated_at` ([ADR 0023](0023-multi-factor-authentication.md)). A counter has no resolution to lose.

**Re-check the credential marker (the keyed password digest) on every request.** Covers a password change or reset, and nothing else: an MFA reset does not touch the password, and a disable is already covered elsewhere. It would also put a credential-derived value on the critical path of every request for no gain over a counter.

**Delete the row and accept the window, documenting it as residual risk.** What Phase 8 did. Rejected for the production gate: "an attacker must be finishing a sign-in at the instant an operator resets it" describes a *narrow* window, and narrow is not the same as closed. The reset exists precisely because the second factor is believed to be in the wrong hands.

**A generic session-invalidation or token-version framework covering every entity.** Rejected as speculative generality. The mechanism covers Accounts, because Accounts are what authenticate.
