# Rotate the application key (`APP_KEY`)

**Purpose.** Replace the application encryption key without locking anyone out.

**When to use.** The key is suspected leaked; a person who had access to it has left; or a periodic rotation is due. **Not** routinely — this key has no expiry and rotating it has a cost.

**Owner.** Whoever holds production access. **Last tested:** the mechanism is covered by `tests/Feature/Modules/Identity/Mfa/KeyRotationTest.php` on both engines. **The *procedure* below has not been run against the production host.** Production is live (v0.1.1, since 2026-09-22) with `APP_KEY` set once at first deployment; a rotation has not yet been performed there.

---

## Read this first

`APP_KEY` is not a session secret. **Every enrolled authenticator depends on it.** TOTP secrets are stored encrypted under it ([ADR 0023](../adr/0023-multi-factor-authentication.md)), and the ciphertext is all there is — nobody, including an administrator, can recover a secret without the key that encrypted it.

Changing the key without carrying the old one forward therefore:

- makes **every stored TOTP secret unreadable**, so everyone with an authenticator fails their second factor with a server error (deliberately *not* "wrong code" — see below);
- **signs everyone out at once**, because the session cookie and the request-forgery token are encrypted under it too;
- ends every half-finished sign-in, which costs nothing.

What is **not** affected, because none of it is ciphertext: passwords (bcrypt hashes), recovery codes (SHA-256 digests bound to the Account), invitation tokens and password-reset tokens (hashes), and the whole audit trail. **A person locked out of their authenticator by a key change can still sign in with a recovery code**, and then enrol again. That is the escape hatch if this goes wrong.

Laravel consults `APP_PREVIOUS_KEYS` when decrypting and always encrypts with the current key. That is the whole mechanism, and the procedure below is built on it.

## Prerequisites

1. **A database backup taken now**, and confirmation that you know which `APP_KEY` it corresponds to. Restoring a database without its matching key is how encrypted factors become unrecoverable for good ([backup and restore](backup-and-restore.md)).
2. **The current `APP_KEY`, copied somewhere you can reach it** during the whole procedure. You are about to need it as a *previous* key.
3. A second administrator who can still sign in, or a shell on the server (`identity:reset-mfa`), so a mistake is recoverable.
4. A quiet window. Step 4 signs everyone out.

## Procedure

**1. Back up.** Database, and the current environment file. Note the date and the key they belong to.

**2. Generate the new key, without writing it anywhere yet.**

```
php artisan key:generate --show
```

`--show` prints it instead of writing it into the environment file. Writing it directly is the mistake this whole runbook exists to prevent: it would replace the current key with no previous key kept.

**3. Put the CURRENT key into the previous-key list**, and the new one in as current. Both in the same edit, so the file is never valid-looking but wrong:

```
APP_KEY=base64:<the NEW key from step 2>
APP_PREVIOUS_KEYS=base64:<the key that was there before>
```

`APP_PREVIOUS_KEYS` is a comma-separated list; an earlier rotation's key may already be in it. Keep the order newest-first.

**4. Deploy both together and clear the configuration cache.**

```
php artisan config:clear
```

Everyone is signed out at this point, including you. That is expected: the session cookies were encrypted under the old key and the session ids are gone with them. People sign in again normally.

**5. Verify that old ciphertext still decrypts.** Sign in as an Account whose authenticator was enrolled **before** the rotation, using a code from its app.

- It works → the previous key is being consulted. Continue.
- It fails with a **server error (500)**, not "the code is not valid" → `APP_PREVIOUS_KEYS` is wrong or was not deployed. **Go to Rollback.** The platform deliberately distinguishes these two: an unreadable secret is a deployment fault and is reported as one, so nobody spends the outage checking clocks and authenticator apps.

**6. Verify that new ciphertext uses the new key.** Have someone replace their authenticator (Account security → Replace authenticator), or enrol a new operator. Their factor is now written under the current key.

**7. Retire the old key — later, and only when nothing needs it.** Remove it from `APP_PREVIOUS_KEYS` only once **no ciphertext still depends on it alone**. In practice that means every enrolled authenticator has been re-enrolled since the rotation. There is no re-encryption command, so until then the old key is load-bearing, not an artefact.

If the leak was the reason for rotating, the old key is *compromised while it stays in the list*: an attacker with it and with database access could still read the factors written under it. In that case do not wait — reset everyone's second factor (`identity:reset-mfa`, or the Console's administrative recovery) so no ciphertext depends on it, then remove it.

## Verification

- An authenticator enrolled before the rotation still signs in (step 5).
- An authenticator enrolled after it signs in (step 6).
- `php artisan security:production-check` still passes.
- `security_events` shows ordinary `authentication.succeeded`, and **no** run of failures around the deployment.

## Rollback

Put the previous key back as `APP_KEY`, remove the new one, clear the configuration cache. Nothing needs undoing in the database: no data was rewritten. Everyone is signed out again.

If the old key has been **lost** and authenticators cannot be decrypted, the platform is still usable and no data is lost:

1. Affected people sign in with a **recovery code** — those do not depend on the key.
2. Anyone without a recovery code is recovered by another administrator (Console → Accounts → the person → reset two-step verification) or, for the last administrator, by `identity:reset-mfa` on the server ([MFA recovery](mfa-recovery.md)).
3. Each person enrols a new authenticator at their next sign-in.

## What else the key protects

Named here so a rotation is never planned around TOTP alone:

| Holder | Effect of rotation without the previous key |
| --- | --- |
| Stored TOTP secrets | **Unreadable.** The reason for this runbook. |
| Session cookie and session payload | Everyone signed out. No data lost. |
| The `XSRF-TOKEN` cookie | A fresh one is issued on the next request. |
| The credential marker binding a half-finished sign-in | That sign-in ends; the person signs in again. Lives minutes. |
| Signed/encrypted cookies generally | None others are used. |

A test asserts that nothing else in the schema is ciphertext, so this table stays complete.
