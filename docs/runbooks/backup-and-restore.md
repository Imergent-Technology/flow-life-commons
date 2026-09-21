# Backup and restore: what must be kept together

**Purpose.** Record the security-critical facts a backup process must respect. **This is not a backup procedure**, and no backup tooling exists ([runbooks](README.md)): building one is deferred until it is designed properly. What follows is the constraint list whoever designs it has to satisfy, written now because getting it wrong is unrecoverable and the reasons are fresh.

**Owner.** Whoever holds the hosting account.

---

## The one rule

> **A database backup is worthless without the `APP_KEY` that was current when it was taken.**

Enrolled authenticators are stored as ciphertext under the application key ([ADR 0023](../adr/0023-multi-factor-authentication.md)). Restoring `accounts` and `account_totp_factors` under a *different* key gives every enrolled person an unreadable secret: they cannot pass their second factor, and no administrator can decrypt it for them. The data is intact and permanently meaningless.

So a backup is a **pair**: the database dump, and the key (or key list) it corresponds to. Store them so that both are available together, and so that restoring the first without the second is not a step anyone can take by accident. Dating them identically is the minimum.

This is survivable, but only because of the escape hatch below. It is still a bad day.

## What must be restored consistently with each other

These are not independent tables. A restore that takes some at one point in time and some at another produces states the application's invariants say cannot exist.

| Records | Why they belong together |
| --- | --- |
| `people` and `accounts` | An Account cannot exist without its Person ([ADR 0015](../adr/0015-identity-owns-person.md)); the foreign key is `RESTRICT` and the application asserts it. |
| `role_assignments` | Authorization is derived live from these. Restoring accounts without them leaves nobody able to administer anything; restoring them without accounts leaves grants pointing at nothing. **Including the last-administrator invariant**: a restore that loses the administrator's assignment locks the platform out of its own administration, recoverable only by `identity:create-administrator` on the server. |
| `account_totp_factors` | Ciphertext, and only under the matching key (above). Restored *older* than `accounts`, a person who replaced their authenticator gets the old one back. |
| `account_recovery_codes` | Digests, key-independent. Restored older than the factors, **already-spent codes become usable again** — each is single-use by a `used_at` column, and an old backup un-spends them. |
| `account_invitations` | An invitation restored after it was accepted becomes usable again, which is a way to set someone's password. Accepted invitations are deleted, so a stale restore resurrects them. |
| `password_reset_tokens` | The same: a restored token is a live one. Short-lived, so the window is small, but it is a credential. |
| `security_events` | Append-only history ([ADR 0019](../adr/0019-security-event-auditing-seam.md)). Restoring it *older* than the rest silently erases the record of whatever happened in between — including whatever caused the restore. |
| `sessions` | The exception: do **not** restore these. They are transient, every one of them is stale by then, and the security generation ([ADR 0025](../adr/0025-account-security-generation.md)) refuses them anyway. Restore an empty table and let everyone sign in. |

**Outside the database:** `APP_KEY` and `APP_PREVIOUS_KEYS`, which live in the environment file and are not in any dump. Back them up deliberately and separately from the database, because a single compromised store holding both the ciphertext and the key protects nothing.

## If a database is restored under the wrong key

Not fatal, and worth knowing before it happens:

1. Everyone with an enrolled authenticator fails their second factor with a **server error**, not "wrong code" — the platform distinguishes these deliberately, so the cause is visible.
2. They sign in with a **recovery code** instead: those are digests and do not depend on the key.
3. Anyone with no recovery code left is recovered by another administrator, or by `identity:reset-mfa` on the server for the last administrator ([MFA recovery](mfa-recovery.md)).
4. Everyone enrols a new authenticator.

Passwords are unaffected throughout: they are bcrypt hashes.

## Audit growth

`security_events` is **append-only and never pruned** — the scheduled maintenance explicitly leaves it alone. It grows with every sign-in, failure, rate limit and administrative act. At the scale this platform is designed for (an invite-only operator directory) that is tens of rows a day, and a backup that grows slowly is the right trade against losing security history.

It is not unbounded attention-free forever. Recorded as a production follow-up, with no work in this phase:

- a retention policy, decided rather than assumed, with archival or export rather than deletion;
- an operator-facing audit viewer, which will need indexes this schema does not have (`occurred_at`, and probably `(subject_account_id, occurred_at)`);
- whatever legal retention obligations a charity's records carry, which is not an engineering decision.

**Do not solve this by deleting security history.**
