# ADR 0023: Multi-factor authentication for the Guardian Console

- **Status:** Accepted
- **Date:** 2026-09-21
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0016](0016-guardian-console-same-origin-session-authentication.md), [ADR 0022](0022-password-policy-and-credential-handling.md)
- **Refined by:** [ADR 0024](0024-privileged-operator-administration.md), which builds the administrative recovery of a lost second factor and the first production use of the step-up this ADR provided; [ADR 0025](0025-account-security-generation.md), which closes the window between a second factor committing and its session existing

## Context

The Guardian Console is the most privileged surface on the platform, and its only credential so far is a password. The identity design named this as the early security follow-up, to be done *before* privileged access expands: [ADR 0016](0016-guardian-console-same-origin-session-authentication.md) put step-up re-authentication "with MFA", and the design left the choice open between adopting Fortify and adding a TOTP library to our own flow.

The next phases add account, invitation and role administration, which is exactly what a stolen password must not be enough to reach. Several decisions therefore have to be made once, before that work, and made so they survive it:

- **Who must have a second factor**, without Identity learning what "privileged" means and without naming a role.
- **What a correct password is, in between**: the half-finished sign-in, and how it is kept from being mistaken for authentication.
- **A longer window.** Phase 4 and Phase 5 each found a race between "first thing verified" and "state changed": a sign-in that verified a password could undo a disable, or outlive a reset. A second factor makes that gap minutes long, so it is the same bug with a much bigger window.
- **Secrets that cannot be hashed.** A TOTP secret must be usable by the server, so it cannot be one-way hashed the way a password or a recovery code can.
- **A reusable "verified recently"**, so that Phase 8's sensitive routes have one thing to ask for.

Four facts were measured or read rather than assumed:

- **`password_updated_at` cannot bind a pending sign-in to a password.** Its resolution is one second, so a reset that commits in the same second as the password check leaves it unchanged. The frozen-clock tests reproduce this exactly.
- **The TOTP library's default secret is 512 bits** (64 random bytes, 103 base32 characters), which RFC 4226 does not ask for and which makes an unwieldy manual key and a dense QR code. The library also ships a helper that builds a URL for an external QR-code service, which would send the secret to a third party.
- **Recovery codes with 80 bits of entropy cannot be searched**, so a fast digest is enough to resist a stolen table, and a fast digest is what allows consuming a code as one indexed conditional `UPDATE`.
- **A session that never proved a second factor can become privileged without signing in again**, by being granted a role that reaches the Console. The existing "a granted role takes effect on the next request" behaviour, correct for authorization, is wrong for authentication strength.

## Decision

**Console access requires a second factor: TOTP, plus recovery codes.** Other factors (SMS, email codes, push, WebAuthn) are out of scope and unbuilt.

### Who must have one: tied to the surface, not to a role

- Identity defines a port, `MultiFactorPolicy::requiredFor(Actor)`. **Access implements it** (`ConsoleMultiFactorPolicy`) as *"does this person currently hold `console.access`?"*, answered fresh through the Authorizer. It is the same inversion as `EffectiveCapabilities` and the last-administrator guard ([ADR 0020](0020-administrator-bootstrap-and-last-administrator-invariant.md)), for the same reason: Access depends on Identity, so Identity may not depend on Access.
- **No role name appears in Identity**, there is no `if administrator` or `if guardian`, and there is **no `mfa` role and no `mfa.*` capability**. The requirement is authentication strength, not authorization. After the second factor completes, capabilities decide what the person may do, exactly as before. Architecture tests pin all three.
- **The default is fail-closed.** If nothing registers a policy, Identity's default requires a second factor of *everyone*. A lost registration must never mean that nobody is asked.
- An Account **that has an authenticator is always challenged**, whatever its access: having one means using it.
- **A password-only session must not become privileged unproven.** A session established without a second factor records that it has none. On each request such a session makes, Identity asks whether its Account *now* needs one (a Console role was granted, or an authenticator was enrolled elsewhere); if so the session is ended, an event is recorded, and the next sign-in goes through enrolment or the challenge. A session that did prove a second factor is never asked and costs no query.

### The sign-in is two steps, and the middle is not authentication

- `POST /login` with a correct password answers **`202`, not `200`,** when a second factor is due, and **establishes no session**: no guard login, no `last_login_at`, no success event. It starts a **pending sign-in**. A wrong password answers exactly as before, so nothing about whether an Account has a second factor is revealed to anyone who does not already know the password.
- The pending sign-in lives **in the browser session** (a database row, like every session) as a small record: the Account, what is due (`challenge` or `enrollment`), when it began, how many codes were wrong, and a **credential digest** (below). It holds no password, hash, secret, code or capability. It is **not authentication**: `/me` answers `401`, no Gate or Authorizer is satisfied, and it is never counted as a session. Starting one first ends whatever the browser session held (a fresh id, no earlier state).
- It lives **5 minutes to challenge and 10 to enrol, measured from the password and never extended by activity**, and ends on the first success, after **5 wrong codes**, or when anything it depends on changes. The routes that finish it are on the session surface (cookie and CSRF apply) and are deliberately **not** behind `auth:web`: they need the pending sign-in, not an identity. They are not stateless bearer endpoints.
- Only success establishes the authenticated session: a new session id and CSRF token, `authenticated_at`, and the same 30-minute inactivity and 12-hour absolute limits. The session also records that a second factor was proved and when, and the Actor's provenance says so (`SessionWithSecondFactor`) without carrying any factor material.
- Everything that finishes a sign-in **re-reads the Account under a row lock and decides from current state** (the Phase 4 and 5 rule): the Account must still be able to sign in, and the pending sign-in's credential digest must still match. So an Account **disabled** after its password was accepted, or a password **replaced** by a reset or a change, ends the pending sign-in instead of letting it finish.
- **The credential digest is an HMAC of the stored password hash under a key derived from the application key.** It is not the hash, cannot check or recover a password, and is compared only for equality. Every stored hash carries its own random salt, so even the same password text set again is a different digest. This binds the pending sign-in to *the credential that was proved*, which a timestamp cannot (see Context). It is the smallest mechanism that holds; there is no credential-version framework.

### The second factor

- **TOTP (RFC 6238): 6 digits, 30-second step, HMAC-SHA1**, the parameters every authenticator app supports; fixed in code because they are a compatibility contract, not a preference. The platform **writes no TOTP cryptography**: it uses [`spomky-labs/otphp`](https://github.com/Spomky-Labs/otphp) (11.5, maintained, strictly typed, PSR-clock aware), whose only new dependency is `paragonie/constant_time_encoding`, itself declared directly because the adapter uses it to encode a 160-bit secret. It is used from **one Infrastructure class** behind an Identity port; nothing else names it (an architecture test says so) and the library's helper that builds an external QR-code URL is never called (a scan says so).
- **Only the current step and one either side are accepted**, and **each step once**: the last accepted step is recorded per Account and a code must be for a strictly later one. This is the only persistent state replay prevention needs.
- **The secret is 160 bits, shown once, and stored encrypted.** It is encrypted by the framework's authenticated encryption under `APP_KEY`, in Infrastructure, behind a port (Identity's Domain and Application never see a cipher or a key), and stored as opaque text: the database does no cryptography, so the schema is identical on MariaDB and PostgreSQL. It is never logged, audited, returned after enrolment, or held in browser storage. **Operational dependency, stated plainly:** rotating `APP_KEY` without keeping the old value in `APP_PREVIOUS_KEYS` makes every enrolled secret unreadable and locks out everyone with an authenticator. That is a loud failure (a decryption error, deliberately not "wrong code"), not a silent one. Recovery-code digests do not depend on the key.
- **The QR code is drawn in the browser** from the provisioning URI (`qr`, zero dependencies), never generated by a web service; the manual key is offered beside it, and can be copied with one click (the clipboard write is the whole action: no request, no storage). The setup response carries the server's own `expires_at` (a pending secret can wait 15 minutes), which the Console shows and never works out; a replacement whose pending secret the server no longer has is taken off the page and started again.

### Enrolment is proof, not generation

- An Account that needs a second factor and has none gets an **enrolment** state after its password. Generating a secret stores it **encrypted and pending**; it changes nothing about how anyone signs in and cannot be used to. Only a **valid current code from that secret** makes it real, in one transaction with the recovery codes, the `mfa.enabled` event and the sign-in itself. A wrong code changes nothing. Asking again replaces the pending secret, so an abandoned attempt strands no one.

### Recovery codes

- Ten codes of 16 characters from a 32-character alphabet (80 bits from the system's CSPRNG), returned **once** at enrolment and on regeneration. Stored **only as a one-way digest**: SHA-256 over a versioned label, the Account id and the code. **Not encrypted so they could be shown again, and not keyed by the application key** (so a key rotation cannot silently kill them). With 80 bits a slow hash would buy nothing, and a fast one is what makes consumption **one atomic conditional `UPDATE`** ("this code, still unused"), which behaves identically on both engines with no JSON and no reliance on which lock is held. Consumption is therefore safe on its own; the Account row lock is a second, independent layer.
- A recovery code can stand in for an authenticator code **wherever a second factor is asked for**, including proving password-and-factor to replace a lost authenticator, so losing the phone does not make the codes useless. A person who loses both has a problem this phase deliberately does not solve: **administrative recovery of another person's second factor is a later, explicit operation**, and there is no self-service way to switch MFA off.

### Managing it needs fresh proof

- **Regenerating recovery codes and replacing the authenticator** each take the **current password and a second factor in the request body** and are rate limited per Account and per address, so a stolen session can neither guess the password nor use a session alone. Replacement is **proof-before-switch**: the new secret is pending, the old authenticator keeps working until a valid code from the new one is presented, and only then does it switch, ending the Account's *other* sessions (a credential was replaced) and rotating this one. Recovery codes are untouched by a replacement.

### Recent verification (step-up)

- A session records `security_verified_at` when the person proves password **and** a second factor: at sign-in, and on `POST /security/verify`, and as a side effect of each management operation. The `security.verified` middleware alias demands it within **15 minutes**, on **this session**, and answers `403` with `verification_required: true` otherwise. It fails safe: a missing, malformed or future-dated instant is "not verified". It sits after authentication (an anonymous caller gets `401`, not `403`) and changes nothing else, including `authenticated_at`; the session id is rotated on proof.
- **Nothing in production uses it yet**; it exists for Phase 8's sensitive routes, which apply it as `->middleware('security.verified')` (the alias, never the class, so another module does not import Identity's transport). A test-only route proves the enforcement edge. There is no risk engine.

### Auditing

`mfa.enabled`, `mfa.challenge_failed`, `mfa.recovery_code_used`, `mfa.recovery_codes_regenerated`, `mfa.replaced`, `security.reverified` and `session.second_factor_required`, plus a `second_factor` field on `authentication.succeeded` and an `mfa_challenge` action on the existing rate-limit event. Contexts hold reason classes, method names and counts. The audit classes cannot even *name* a secret, a code, a digest or a proof type (architecture tests), and Audit's backstop refuses secret-shaped values. A refused code is one event, bounded by the rate limits; there is no event for starting an enrolment.

## Consequences

- A stolen password is no longer enough to reach the Console, the property the next phases need.
- **Console users must enrol on their next sign-in.** The administrator bootstrap now ends in enrolment: the first sign-in accepts the invitation's password and then walks the person through setting up an authenticator and saving recovery codes ([runbook](../runbooks/administrator-bootstrap.md)).
- **Loss of the authenticator and the recovery codes locks a person out** until an administrative recovery exists. This is accepted and stated, not hidden.
- `APP_KEY` becomes a much heavier operational asset (above).
- A pending sign-in and an anonymous session are both session rows; the Console's startup `GET /me` already creates one per visit. **Session volume and cleanup are a production-readiness follow-up, not solved here.** MFA adds no more rows than one per sign-in attempt.
- A **visually stale page is still possible** (a Console page left open shows until its next request); no heartbeat is added, and the server stays authoritative.
- The Phase 5 caveat that "a sign-in can still outlive a reset by a hair" is *narrower* for accounts with a second factor (the pending sign-in is re-checked under the lock at the end) but the password-only path is unchanged.
- The e2e suite's sign-in count grows; the development stack raises the per-address login limit (never production's default) and the suite refuses to run without that.

## Alternatives considered

- **Fortify for two-factor.** Rejected, as [the design](../architecture/identity-and-access.md) anticipated: its two-factor support is wired into its own login pipeline, which we do not use, and adopting it for MFA alone would mean moving login onto it. We add a small library to our own flow instead.
- **Requiring MFA by role** (`if administrator`). Rejected: it is the rot the design forbids, it breaks when roles change or another role carries the capability, and it would put role names in Identity.
- **Asking Identity to check `console.access` directly.** Rejected: Identity would have to name Access's capability catalog, and the dependency edge runs the other way.
- **`password_updated_at` (or a version counter) as the binding.** Rejected: one-second resolution, and a counter is a framework the design says not to build. A digest of the credential is smaller and cannot be bypassed by a write that forgets to bump it.
- **Storing the password hash in the pending sign-in.** Rejected outright; a keyed digest of it holds the property without storing the hash.
- **Establishing the session first and downgrading it until the second factor completes.** Rejected: a session that exists is authentication, and every gate would have to remember to check a flag. A pending state that *is not* a session cannot satisfy anything by accident.
- **Hashing recovery codes with bcrypt/Argon2.** Rejected: no benefit at 80 bits, and it forces loading every code and checking each one, losing the atomic single-statement consumption.
- **Encrypting recovery codes so they can be shown again.** Rejected: it turns a one-way secret into a recoverable one.
- **Using the database's encryption functions for the TOTP secret.** Rejected: they differ between MariaDB and PostgreSQL and the portability guardrail bans them; application-level authenticated encryption is identical on both.
- **Generating the QR code server-side or with a web service.** A service would receive the secret. Server-side generation would only move the code; the manual key must reach the browser anyway.
- **Self-service MFA disablement.** Rejected: it would make MFA optional to anyone holding a session, and a stolen session would switch it off. Replacement (while the current factor still works) and administrative recovery cover the real needs.
- **A "remember this device" bypass.** Out of scope, and it would create the exemption the policy exists to avoid.
- **A generic authentication-factor framework.** Not built: there is one factor and one requirement. When a second factor type is real it introduces its own abstraction.
