# ADR 0022: Password policy and credential handling

- **Status:** Accepted
- **Date:** 2026-09-20
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0015](0015-identity-owns-person.md), [ADR 0016](0016-guardian-console-same-origin-session-authentication.md)
- **Clarified:** 2026-09-20, at the Phase 5 closeout. What `email_verified_at` means was tightened (acceptance no longer sets it), the no-session-on-acceptance lifecycle was recorded with its reasons, the bcrypt tradeoff was made explicit, and the test gate was made self-contained. The password policy itself is unchanged.
- **Refined by:** [ADR 0024](0024-privileged-operator-administration.md): an invitation the platform *emails* to the address is the evidence of mailbox control this ADR said no invitation yet provided, so accepting one sets `email_verified_at`; an operator-delivered invitation still does not

## Context

The frozen Identity design says accounts are created by invitation, that passwords are hashed with bcrypt, and that acceptance, reset and change exist. It does not say what an acceptable password is, how one is normalised, what happens at bcrypt's 72-byte ceiling, or what to do when the breached-password service is down. Those are decisions that constrain every credential path, and they are cheap to make once and expensive to discover four times over in four subtly different implementations.

Four facts, each measured rather than assumed, shaped them:

- **bcrypt reads only the first 72 bytes.** Without a limit, a longer password is silently weakened to its first 72 bytes, and a *longer candidate can verify against a stored 72-byte password* it does not equal.
- **bcrypt also stops at a NUL byte, in one direction.** On PHP 8.3.33 `password_hash` refuses a NUL (a `ValueError`), but `password_verify("stored\0anything", hash("stored"))` returns **true**: verification ignores everything after it. Both are pinned by tests.
- **Laravel's `Password::uncompromised()` fails open.** Its verifier catches a connection error, treats any non-2xx answer as an empty body, and reports the password as safe. Verified in Laravel 13 (and pinned by a test): with the service returning `500`, the rule passes.
- **Laravel already leaves `password`, `password_confirmation` and `current_password` untrimmed**, so a password with leading or trailing spaces reaches the policy as typed.

## Decision

**One policy, owned by Identity, used by every path that sets a password** (invitation acceptance, reset, authenticated change):

- **At least 15 Unicode code points.** While a password is the only factor, this is the single-factor minimum. No composition rules: no required digits, cases or symbols; spaces and Unicode are welcome; paste is not blocked. No expiry schedule, no history, no hints, no security questions.
- **At most 72 UTF-8 bytes**, measured after normalisation. Over that it is **refused with an explanation, never truncated, and never pre-hashed around.** This limit is an acknowledged temporary consequence of hosting portability (bcrypt is what every target host has), not a design goal.
- **Unicode NFC** before hashing *and* before verifying, in one place (`PlainPassword`), so the bytes checked at sign-in are by construction the bytes that were hashed. Email addresses are a different thing with different rules and never go through it. Nothing else is done to the text: no trimming, no case folding.
- **Text that cannot be hashed safely is refused:** malformed UTF-8 (it cannot be normalised) and text containing a NUL byte (bcrypt would stop reading there).

**Three lines of defence for the 72-byte limit**, so it does not rest on controller validation alone: the policy (a useful message), `PasswordHasher` (refuses to hash, and refuses to verify, an unsafe value), and `config/hashing.php`'s `limit` (the framework hasher itself throws rather than truncate). A test asserts the last equals the first.

**The bcrypt tradeoff, stated plainly.** bcrypt is a **temporary** choice made only for hosting portability: it is the one algorithm every target host has. It takes at most **72 UTF-8 bytes** of input, and this policy **refuses** anything longer rather than silently truncating it (or pre-hashing around it). The consequence is that the system **cannot always permit 64 Unicode characters**, which is what guidance such as NIST SP 800-63B expects a verifier to accept. 72 bytes is 72 ASCII characters, but only 36 two-byte characters (Cyrillic, Greek, most accented Latin), 24 three-byte ones (CJK) or 18 four-byte ones (emoji), so a 64-character passphrase fits when it is ASCII or nearly so, and not in general. That is a real limitation, accepted for now and disclosed to the user by the refusal message. **Migrating to a password hashing algorithm without bcrypt's input limit (Argon2id, rehashed on sign-in, as the design already anticipates) removes the restriction, and should be done when hosting capabilities permit.** Doing so supersedes the byte limit in this ADR and nothing else. No algorithm changes are made here.

**Screening for breached and common passwords is mandatory**, through a port (`CompromisedPasswords`). No production configuration switches it off (see the last bullet below):

- The Application layer makes no network call. The adapter is Infrastructure, replaceable by a local blocklist without touching a use case.
- The adapter uses the Pwned Passwords range API with **k-anonymity**: only the first five hex characters of the password's SHA-1 leave the machine, with `Add-Padding`. It is **not** Laravel's rule, because that fails open.
- **It fails closed.** A connection error, a non-2xx status, an empty answer or a malformed one is `CompromisedPasswordCheckUnavailable`: the password is neither accepted nor refused, nothing is changed, and the endpoint answers **`503` with `Retry-After`**. "Could not check" is never "safe".
- **The check runs before the credential transaction opens**, and only for a password that already passed the offline rules, so a plainly unacceptable password is never sent anywhere, not even as a prefix.
- The one way to switch it off (`IDENTITY_COMPROMISED_PASSWORD_CHECK=none`, for development and tests with no network) is refused by the container outside the `local` and `testing` environments, and the code default is the real check, so a production host that configures nothing is still screened.
- **Automated validation is deterministic and self-contained.** Development and CI use the `none` driver, so `./flow test`, `./flow check`, CI and the browser e2e never need the public service (`./flow test e2e` refuses to run otherwise). The real adapter is tested against faked HTTP, both alone and behind each endpoint that sets a password: clean, breached, 4xx, 5xx, malformed, empty and connection failure (each failure a `503` that changes nothing), the call made outside the transaction, and only the five-character prefix sent. A separate **manual** smoke test (`tests/Live`, in no test suite) checks the real service, and skips rather than fails when offline.

**Invitation acceptance** (`POST /api/v1/invitations/accept`):

- The token travels in the request **body**, never a URL; it is hashed before lookup and never stored, logged or audited.
- **Acceptance creates no session; the invitee then signs in.** The lifecycle is: invitation accepted, password established, Account activated, **no authenticated session**, the person performs the ordinary login, a session is established. This replaces the design's earlier wording that acceptance creates a new session. Reasons: establishing a credential and authenticating are distinct operations; the ordinary login is the one canonical authentication path, so every session comes from the same code and its throttling, auditing and session-fixation handling; acceptance is a stateless endpoint that need not touch browser session state at all; and nothing requires the convenience, so a one-time token is never made into a login credential.
- **One answer for every unusable invitation** (unknown, malformed, expired, used, revoked, or its Account no longer able to accept), so the response cannot be used to probe. The password is judged first, so a rejected password reveals nothing about the token.
- In one transaction, after all network and hashing work: lock and re-read the invitation, lock and re-read the Account, require both still usable (the Account still `invited`, so acceptance can never resurrect a disabled one), then set the password, activate, mark the invitation used and record `invitation.accepted`. Any concurrent change fails it safely; two simultaneous acceptances of one token cannot both succeed.
- **`email_verified_at` means the platform has evidence that the Account holder demonstrated control of that email mailbox**, and nothing weaker. Choosing a password is not that evidence, and neither is holding an invitation token. The administrator bootstrap prints its token to a trusted server operator rather than delivering it to the address, so **accepting a bootstrap invitation activates the Account and establishes its password but does not set `email_verified_at`**. Accepting an invitation that was actually delivered to the address *may* set it, because presenting it then is evidence of control of the mailbox; nothing delivers invitations to an address yet, so nothing sets it yet. There is no verification-provenance subsystem and no separate verification flow. An unverified email does not stop an Account signing in: no frozen policy makes verification a condition of authentication. The acceptance event records who issued the invitation (`issued_by`).

## Consequences

- Passwords are normalised, judged and hashed identically wherever they are set, and login cannot drift from them.
- A passphrase of about 72 ordinary characters is the ceiling (fewer if it uses multi-byte characters), so 64 Unicode characters are not always permitted (see *The bcrypt tradeoff*). Long passphrases within it work naturally; longer ones are refused with a reason. Lifting the limit means moving off bcrypt (Argon2id, rehash on sign-in), which supersedes the byte limit in this ADR and nothing else.
- An Account activated by a bootstrap invitation has an unverified email until something establishes mailbox control. Nothing currently depends on `email_verified_at`, so nothing is blocked; a later feature that needs a verified address (a standalone verification flow, email change) will have to obtain it rather than assume it.
- A breach-service outage blocks setting a password until it recovers, rather than quietly weakening the policy. That is the intended trade; the response is retryable and changes nothing.
- The check needs outbound HTTPS from a **production** host. A deployment that cannot allow it needs the local-blocklist adapter first. Development, CI and the test suite do not.
- At sign-in, an over-long candidate can no longer match a stored password by its first 72 bytes, and a candidate carrying a NUL byte can no longer match by what precedes it.

## Alternatives considered

- **Composition rules (upper, lower, digit, symbol):** rejected. They push people toward predictable substitutions and away from passphrases; length and a breach check do the work.
- **Pre-hash the password (SHA-256, then bcrypt) to lift the limit:** rejected. It creates a second, unreviewed credential format, makes the stored hash depend on a wrapper, and the password-shucking risk of a fast inner hash is precisely the thing to avoid. Moving to Argon2id is the honest fix, and is deferred.
- **Switch to Argon2id now:** not guaranteed on the cPanel hosts we target ([ADR 0002](0002-laravel-php-platform.md)); deferred, and the design already says Laravel will rehash on sign-in when it changes.
- **Laravel's `Password::uncompromised()`:** rejected, because it fails open. Nothing else in it was worth keeping.
- **Treat an unreachable breach service as "accept":** rejected outright; an outage would disable the policy for exactly as long as an attacker could cause one.
- **A local common-password list only:** a good later adapter, but at a 15-character minimum the common short passwords are already refused, and the breached long ones are what the range service is for.
