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
     */
    'invitation' => [
        'ttl_days' => (int) env('IDENTITY_INVITATION_TTL_DAYS', 7),
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

];
