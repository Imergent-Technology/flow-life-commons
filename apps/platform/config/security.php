<?php

declare(strict_types=1);

/*
 * The browser security policy for the production origin (ADR 0026).
 *
 * ONE source of truth. The production origin serves the Guardian Console's static build AND the
 * Laravel API (ADR 0016), and in production those are served by two different things: Apache serves
 * `index.html` and `assets/` directly, Laravel serves `/api/*` and `/up`. Two places to state a policy
 * is two places for it to drift, so both are generated from here: Laravel applies it in middleware,
 * and `php artisan security:headers --format=apache|caddy` emits the block the web server needs. A
 * test fails if the committed `.htaccess` or `Caddyfile` stops matching what this file says.
 *
 * Nothing here is read from the environment. These are security invariants, not deployment settings:
 * a host that could weaken the policy with a variable is a host that eventually does.
 */

return [

    /*
     * Content-Security-Policy, derived from what the PRODUCTION build actually loads, which was
     * measured rather than guessed (`./flow build`, then read dist/):
     *
     *   index.html   one external <script type="module">, one external <link rel="stylesheet">
     *                and NO inline script or style of any kind
     *   assets/      one .js, one .css, no fonts, no images, no data: URIs, no source maps
     *   favicon.ico  a real file on the origin
     *   at runtime   fetch() to relative /api/v1/... only; the QR code is inline SVG drawn in the
     *                browser from the module matrix (never an <img>, never a QR service)
     *
     * So the narrowest policy that works is 'self' for the three things that are loaded and 'none'
     * for everything else. In particular:
     *
     * - NO 'unsafe-inline' and NO 'unsafe-eval'. The production build needs neither. (Vite's dev
     *   server does; development gets its own, weaker policy and production is not bent to match it.)
     * - NO `data:` in img-src. Nothing in the build uses a data: URI, and allowing them would widen
     *   the surface for an injected image-shaped payload for no benefit.
     * - NO object-src line: `default-src 'none'` already covers it, and a redundant directive is one
     *   more thing to keep true. `frame-ancestors`, `base-uri` and `form-action` DO need stating,
     *   because default-src does not cover them.
     * - `frame-ancestors 'none'`: the Console is never framed, by anyone, including itself.
     */
    'csp' => [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self'",
        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
    ],

    /*
     * The rest of the browser policy. Each is here because something about this application makes it
     * worth sending; a header with no reason is a header nobody maintains.
     */
    'headers' => [
        // The API answers JSON and the assets are hashed and typed. Nothing benefits from the browser
        // second-guessing a Content-Type, and a sniffed type is how a text response becomes a script.
        'X-Content-Type-Options' => 'nosniff',

        // Nothing outside this origin ever needs to know where a Console request came from. The
        // secret-bearing links put their token in the URL FRAGMENT, which is never sent in a Referer
        // anyway, so this is not what protects them: it is simply the least the Console can send.
        'Referrer-Policy' => 'same-origin',

        // Defence in depth beside `frame-ancestors 'none'`, for anything that does not honour CSP 2.
        'X-Frame-Options' => 'DENY',

        // The Console uses none of these. Denying them outright means a future dependency cannot
        // quietly start using one. `publickey-credentials-get` is denied deliberately: passkeys are
        // explicitly not part of this epic, so if they ever arrive this line must be revisited first.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), '
            .'display-capture=(), publickey-credentials-get=(), screen-wake-lock=()',

        // The Console opens no cross-origin windows and is opened by none, so it can have its own
        // browsing-context group.
        'Cross-Origin-Opener-Policy' => 'same-origin',

        // No other site has any business loading this origin's assets or API responses as a subresource.
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],

    /*
     * HSTS, sent ONLY over HTTPS (a browser ignores it otherwise, and sending it on the plain-HTTP
     * development origin would be a lie about the deployment).
     *
     * Deliberately narrow: one year, and NO `includeSubDomains` and NO `preload`. Flow Life runs other
     * hosts under flowlifeglobal.org, WordPress among them (ADR 0004), and this application controls
     * neither their certificates nor their HTTPS posture. Asserting `includeSubDomains` from here
     * would hard-fail a sibling host that is not ready, from a component that has no authority to
     * make that promise, and `preload` would make it irreversible. If the whole domain is ever to be
     * covered, that is a decision for whoever owns the apex, declared there.
     */
    'hsts' => 'max-age=31536000',

    /*
     * Deliberately NOT sent, so nobody adds them back as "best practice":
     *
     * - `X-XSS-Protection`: the auditor's favourite. The filter it enables was removed from every
     *   current browser, and in its day it introduced vulnerabilities of its own. CSP replaces it.
     * - `Expect-CT`: obsolete; Certificate Transparency is enforced by browsers regardless.
     * - `Feature-Policy`: superseded by Permissions-Policy, above.
     * - `Cross-Origin-Embedder-Policy`: it buys cross-origin isolation, which this application has no
     *   use for (no SharedArrayBuffer, no precise timers), and costs a constraint on every future
     *   subresource.
     */

];
