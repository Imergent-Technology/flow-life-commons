<?php

declare(strict_types=1);

/*
 * Identity: authentication policy for the Guardian Console.
 */

return [

    'session' => [
        /*
         * Absolute authenticated lifetime, in minutes (ADR 0016): 12 hours from the moment
         * of authentication, REGARDLESS of activity. It is independent of the sliding
         * inactivity timeout (config/session.php, 30 minutes of request inactivity, which
         * any authenticated request refreshes). Re-authenticating starts a new lifetime.
         *
         * Deliberately not read from the environment: it is a frozen security policy.
         */
        'absolute_lifetime_minutes' => 720,
    ],

    /*
     * Account invitations (docs/architecture/identity-and-access.md, "Credential lifecycles").
     * ttl_days: how long an invitation stays usable (7 by default). The token is single-use and
     * only its hash is stored.
     * console_path: where on the Console's origin (app.url) the acceptance page lives. The emailed link is
     * `<app.url><console_path>#token=...`: the secret is in the URL FRAGMENT, which browsers never send to a
     * server, so it stays out of access logs and Referer headers (ADR 0024).
     */
    'invitation' => [
        'ttl_days' => (int) env('IDENTITY_INVITATION_TTL_DAYS', 7),
        'console_path' => '/accept-invitation',
    ],

    /*
     * Login throttling, per source address AND per (normalised) login identifier.
     * Backed by Laravel's cache limiter (the database cache store; no Redis).
     *
     * - max_attempts_per_ip: every login attempt from one address, successful or not.
     * - max_failures_per_identifier: failed attempts against one identifier from anywhere
     *   (defeats a distributed guess); cleared by a successful login. This deliberately
     *   lets a caller lock an identifier out for one window: the accepted trade for a small
     *   invite-only user base.
     * - audit_interval_seconds: while a limit stays engaged, at most one
     *   authentication.rate_limited event per key per interval, so an attacker cannot
     *   grow the audit table by hammering a blocked endpoint.
     *
     * The identifier counter treats a known and an unknown identifier identically, so
     * throttling never reveals whether an account exists.
     */
    'login_throttle' => [
        'max_attempts_per_ip' => (int) env('IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP', 30),
        'max_failures_per_identifier' => (int) env('IDENTITY_LOGIN_MAX_FAILURES_PER_IDENTIFIER', 5),
        'decay_seconds' => (int) env('IDENTITY_LOGIN_THROTTLE_DECAY_SECONDS', 900),
        'audit_interval_seconds' => 60,
    ],

    /*
     * The password policy (docs/adr/0022). The rules themselves (15 code points minimum, 72 bytes
     * maximum, NFC) are code, in PlainPassword, and are deliberately not configurable: they are a
     * security policy, not a preference.
     *
     * compromised_check: how a candidate password is checked against public breaches.
     * - driver `pwned_passwords`: the Pwned Passwords range API, k-anonymity (only the first five
     *   characters of the password's SHA-1 leave this machine). If it cannot be reached or answers
     *   nonsense the password is neither accepted nor refused: the caller gets a retryable 503.
     * - driver `none`: no check, for development and tests that have no network. The container
     *   refuses to build it outside the `local` and `testing` environments, so it cannot be a
     *   production setting.
     */
    'password' => [
        'compromised_check' => [
            'driver' => env('IDENTITY_COMPROMISED_PASSWORD_CHECK', 'pwned_passwords'),
            'url' => 'https://api.pwnedpasswords.com',
            'timeout_seconds' => 3,
        ],
    ],

    /*
     * Rate limits for the credential endpoints, on Laravel's cache limiter (the database store; no
     * Redis). Every attempt counts, per source address and, where the endpoint has one, per
     * identifier, over `decay_seconds`. Each action has its own counters, so hammering one endpoint
     * for an identifier cannot lock that identifier out of another. While a limit stays engaged, at
     * most one authentication.rate_limited event per key per `audit_interval_seconds`.
     *
     * The invitation token carries 256 bits of entropy, so acceptance is limited by source address
     * only: there is nothing to brute-force, only volume to bound.
     */
    'credential_throttle' => [
        'decay_seconds' => (int) env('IDENTITY_CREDENTIAL_THROTTLE_DECAY_SECONDS', 900),
        'audit_interval_seconds' => 60,
        'invitation_acceptance' => [
            'per_ip' => (int) env('IDENTITY_ACCEPT_MAX_ATTEMPTS_PER_IP', 20),
        ],
        // "I forgot my password" requests. A low per-identifier limit stops anyone flooding one
        // person's inbox; it is a separate counter from completion, so an attacker requesting resets
        // for someone cannot stop them finishing one they already have.
        'password_reset_request' => [
            'per_ip' => (int) env('IDENTITY_RESET_REQUEST_MAX_PER_IP', 10),
            'per_identifier' => (int) env('IDENTITY_RESET_REQUEST_MAX_PER_IDENTIFIER', 3),
        ],
        // Changing a password while signed in. The current password is the re-authentication, so this is
        // the guess limit for it: a stolen session must not be able to try passwords at will. The
        // identifier is the Account, so it is per person, not per address they typed.
        'password_change' => [
            'per_ip' => (int) env('IDENTITY_CHANGE_MAX_PER_IP', 20),
            'per_identifier' => (int) env('IDENTITY_CHANGE_MAX_PER_ACCOUNT', 5),
        ],
        // A code presented to finish a sign-in or to confirm an authenticator (ADR 0023). Per Account (the
        // identifier is the Account the half-finished sign-in belongs to) and per source address. Every
        // attempt counts, so it is set to leave room for ordinary typos and clock drift: ten in fifteen
        // minutes, on top of the pending sign-in ending after `max_challenge_failures` wrong codes.
        'mfa_challenge' => [
            'per_ip' => (int) env('IDENTITY_MFA_MAX_PER_IP', 30),
            'per_identifier' => (int) env('IDENTITY_MFA_MAX_PER_ACCOUNT', 10),
        ],
        // Password plus second factor presented while signed in, for recent verification and for MFA
        // management. The password is being guessed at, so as strict as changing one.
        'security_verification' => [
            'per_ip' => (int) env('IDENTITY_SECURITY_VERIFICATION_MAX_PER_IP', 20),
            'per_identifier' => (int) env('IDENTITY_SECURITY_VERIFICATION_MAX_PER_ACCOUNT', 5),
        ],
        // Completing a reset. The token has 256 bits, so this bounds volume (hashing work and audit
        // growth), not guessing. As with login, a caller can exhaust an identifier's allowance for one
        // window: the accepted trade for a small invite-only user base.
        'password_reset_completion' => [
            'per_ip' => (int) env('IDENTITY_RESET_COMPLETION_MAX_PER_IP', 20),
            'per_identifier' => (int) env('IDENTITY_RESET_COMPLETION_MAX_PER_IDENTIFIER', 10),
        ],
    ],

    /*
     * Multi-factor authentication (docs/adr/0023). Security policy, so the numbers are code-owned and not
     * read from the environment, except the issuer name an authenticator app displays.
     *
     * - issuer: the name authenticator apps show next to the Account.
     * - challenge_lifetime_seconds / enrollment_lifetime_seconds: how long a half-finished sign-in (the
     *   password is proved, the second factor is not) stays usable, measured from the password and NOT
     *   extended by activity. It is kept in the browser session, is not authentication, and dies with the
     *   first success or after `max_challenge_failures` wrong codes.
     * - security_verification_max_age_minutes: how recent password-and-second-factor proof must be for a
     *   route that demands it (the `security.verified` middleware).
     */
    'mfa' => [
        'issuer' => env('IDENTITY_MFA_ISSUER', 'Flow Life Guardian Console'),
        'challenge_lifetime_seconds' => 300,
        'enrollment_lifetime_seconds' => 600,
        'max_challenge_failures' => 5,
        'security_verification_max_age_minutes' => 15,
    ],

    /*
     * Password recovery.
     *
     * - console_path: where on the Console's origin (app.url, the same origin as the API, ADR 0016) the
     *   reset page will live. The emailed link is `<app.url><console_path>#token=...&email=...`: the
     *   secrets are in the URL FRAGMENT, which browsers never send to a server, so they stay out of
     *   access logs and Referer headers. The page is the Guardian Console's (it reads the fragment into
     *   memory and scrubs it from the address bar); the contract is documented in
     *   docs/architecture/identity-and-access.md.
     * - response_floor_ms: the least time "I forgot my password" takes to answer. An address with an
     *   account does more work (a lock, a hash, a mail) than one without, and answering as fast as the
     *   work allows would let the difference say which is which; every request is padded to this floor.
     *   Set it above the slowest realistic mail send. Tests set it to zero.
     */
    'password_reset' => [
        'console_path' => '/reset-password',
        'response_floor_ms' => (int) env('IDENTITY_PASSWORD_RESET_RESPONSE_FLOOR_MS', 1500),
    ],
];
