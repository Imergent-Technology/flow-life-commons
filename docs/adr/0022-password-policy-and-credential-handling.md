# ADR 0022: Password policy and credential handling

- **Status:** Accepted
- **Date:** 2026-09-20
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0015](0015-identity-owns-person.md), [ADR 0016](0016-guardian-console-same-origin-session-authentication.md)

## Context

The frozen Identity design says accounts are created by invitation, that passwords are hashed with bcrypt, and that acceptance, reset and change exist. It does not say what an acceptable password is, how one is normalised, what happens at bcrypt's 72-byte ceiling, or what to do when the breached-password service is down. Those are decisions that constrain every credential path, and they are cheap to make once and expensive to discover four times over in four subtly different implementations.

Three facts, each measured rather than assumed, shaped them:

- **bcrypt reads only the first 72 bytes.** Without a limit, a longer password is silently weakened to its first 72 bytes, and a *longer candidate can verify against a stored 72-byte password* it does not equal.
- **bcrypt also stops at a NUL byte, in one direction.** On PHP 8.3.33 `password_hash` refuses a NUL (a `ValueError`), but `password_verify("stored\0anything", hash("stored"))` returns **true**: verification ignores everything after it. Both are pinned by tests.
- **Laravel's `Password::uncompromised()` fails open.** Its verifier catches a connection error, treats any non-2xx answer as an empty body, and reports the password as safe. Verified in Laravel 13 (and pinned by a test): with the service returning `500`, the rule passes.
- **Laravel already leaves `password`, `password_confirmation` and `current_password` untrimmed**, so a password with leading or trailing spaces reaches the policy as typed.

## Decision

**One policy, owned by Identity, used by every path that sets a password** (invitation acceptance, reset, authenticated change):

- **At least 15 Unicode code points.** No composition rules: no required digits, cases or symbols; spaces and Unicode are welcome; paste is not blocked. No expiry schedule, no history, no hints, no security questions.
- **At most 72 UTF-8 bytes**, measured after normalisation. Over that it is **refused with an explanation, never truncated, and never pre-hashed around.** This limit is an acknowledged temporary consequence of hosting portability (bcrypt is what every target host has), not a design goal.
- **Unicode NFC** before hashing *and* before verifying, in one place (`PlainPassword`), so the bytes checked at sign-in are by construction the bytes that were hashed. Email addresses are a different thing with different rules and never go through it. Nothing else is done to the text: no trimming, no case folding.
- **Text that cannot be hashed safely is refused:** malformed UTF-8 (it cannot be normalised) and text containing a NUL byte (bcrypt would stop reading there).

**Three lines of defence for the 72-byte limit**, so it does not rest on controller validation alone: the policy (a useful message), `PasswordHasher` (refuses to hash, and refuses to verify, an unsafe value), and `config/hashing.php`'s `limit` (the framework hasher itself throws rather than truncate). A test asserts the last equals the first.

**Not known from public breaches**, through a port (`CompromisedPasswords`):

- The Application layer makes no network call. The adapter is Infrastructure, replaceable by a local blocklist without touching a use case.
- The adapter uses the Pwned Passwords range API with **k-anonymity**: only the first five hex characters of the password's SHA-1 leave the machine, with `Add-Padding`. It is **not** Laravel's rule, because that fails open.
- **It fails closed.** A connection error, a non-2xx status, an empty answer or a malformed one is `CompromisedPasswordCheckUnavailable`: the password is neither accepted nor refused, nothing is changed, and the endpoint answers **`503` with `Retry-After`**. "Could not check" is never "safe".
- **The check runs before the credential transaction opens**, and only for a password that already passed the offline rules, so a plainly unacceptable password is never sent anywhere, not even as a prefix.
- The one way to switch it off (`IDENTITY_COMPROMISED_PASSWORD_CHECK=none`, for development and tests with no network) is refused by the container outside the `local` and `testing` environments.

**Invitation acceptance** (`POST /api/v1/invitations/accept`):

- The token travels in the request **body**, never a URL; it is hashed before lookup and never stored, logged or audited.
- It **does not sign the caller in**; they use the ordinary login.
- **One answer for every unusable invitation** (unknown, malformed, expired, used, revoked, or its Account no longer able to accept), so the response cannot be used to probe. The password is judged first, so a rejected password reveals nothing about the token.
- In one transaction, after all network and hashing work: lock and re-read the invitation, lock and re-read the Account, require both still usable (the Account still `invited`, so acceptance can never resurrect a disabled one), then set the password, activate, mark the invitation used and record `invitation.accepted`. Any concurrent change fails it safely; two simultaneous acceptances of one token cannot both succeed.
- **`email_verified_at`** records that the platform accepted the address as the Account's login identifier through an invitation issued for it, and nothing more. An invitation an Account issued is expected to have been delivered to the address, so accepting it shows control of it. An invitation the *platform* issued (the administrator bootstrap, whose token an operator hands over) shows only that a trusted operator vouched for the address. The acceptance event records which (`issued_by`), so the difference is recoverable without a verification-provenance subsystem.

## Consequences

- Passwords are normalised, judged and hashed identically wherever they are set, and login cannot drift from them.
- A passphrase of about 72 ordinary characters is the ceiling (fewer if it uses multi-byte characters). Long passphrases within it work naturally; longer ones are refused with a reason. Lifting the limit means moving off bcrypt (Argon2id, rehash on sign-in), which supersedes the byte limit in this ADR and nothing else.
- A breach-service outage blocks setting a password until it recovers, rather than quietly weakening the policy. That is the intended trade; the response is retryable and changes nothing.
- The check needs outbound HTTPS from the host. A deployment that cannot allow it needs the local-blocklist adapter first.
- At sign-in, an over-long candidate can no longer match a stored password by its first 72 bytes, and a candidate carrying a NUL byte can no longer match by what precedes it.

## Alternatives considered

- **Composition rules (upper, lower, digit, symbol):** rejected. They push people toward predictable substitutions and away from passphrases; length and a breach check do the work.
- **Pre-hash the password (SHA-256, then bcrypt) to lift the limit:** rejected. It creates a second, unreviewed credential format, makes the stored hash depend on a wrapper, and the password-shucking risk of a fast inner hash is precisely the thing to avoid. Moving to Argon2id is the honest fix, and is deferred.
- **Switch to Argon2id now:** not guaranteed on the cPanel hosts we target ([ADR 0002](0002-laravel-php-platform.md)); deferred, and the design already says Laravel will rehash on sign-in when it changes.
- **Laravel's `Password::uncompromised()`:** rejected, because it fails open. Nothing else in it was worth keeping.
- **Treat an unreachable breach service as "accept":** rejected outright; an outage would disable the policy for exactly as long as an attacker could cause one.
- **A local common-password list only:** a good later adapter, but at a 15-character minimum the common short passwords are already refused, and the breached long ones are what the range service is for.
