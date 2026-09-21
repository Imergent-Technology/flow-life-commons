# ADR 0026: Production browser security policy

- **Status:** Accepted
- **Date:** 2026-09-23
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0016](0016-guardian-console-same-origin-session-authentication.md), [ADR 0003](0003-react-guardian-console.md)
- **Clarified:** 2026-09-21, after the production host was probed. The policy is unchanged. `mod_headers` is confirmed present and the generated block effective; the `expose_php = Off` wording is corrected to match what was measured (see *HSTS, deliberately narrow* and the note on `X-Powered-By`).

## Context

The Guardian Console is a privileged administrative surface: from it an operator can disable an Account, hand out roles and reset someone's second factor. Until now it shipped with no browser security policy at all — no CSP, no framing protection, no referrer policy — because there was no production origin to serve one on and no build whose real needs could be measured.

Three facts about this application decide almost everything below, and each was **measured from the actual production build** rather than assumed:

- **The build loads three things and nothing else.** `./flow build`, then reading `dist/`: `index.html` with one external `<script type="module">` and one external `<link rel="stylesheet">`, **no inline script or style of any kind**, one `.js`, one `.css`, no fonts, no images, no `data:` URIs, no source maps. The QR code is drawn in the browser as inline SVG from the module matrix ([ADR 0023](0023-multi-factor-authentication.md)), so even that needs no external origin.
- **The Console talks to one origin, its own.** The HTTP client is relative-path-only by type and by runtime check, `credentials: 'same-origin'`, no CORS, no configurable base ([ADR 0016](0016-guardian-console-same-origin-session-authentication.md)).
- **The production origin is served by two different things.** Apache serves `index.html` and `assets/` straight from the document root; Laravel serves `/api/*` and `/up`. A policy expressed only in Laravel middleware would leave the Console's own HTML — the document the policy is actually about — with no policy at all.

The fourth fact is about development, not production: **Vite's dev server needs `'unsafe-inline'` and `'unsafe-eval'`**, because that is how a dev server works. That is a fact about the development gateway and must not become a fact about production.

## Decision

### One policy, in configuration, generated into both deployment adapters

`config/security.php` is the single source of truth, read from no environment variable: these are security invariants, and a host that *can* weaken the policy with a variable eventually does. From it:

- **Laravel middleware** puts the headers on every response it produces — the API, `/up`, and the framework's own error pages. It is a global middleware rather than a route group, so there is no route that can be added without it.
- **`php artisan security:headers --format=apache|caddy`** emits the block the web server needs. The production `public/.htaccess` and the development gateway's production-equivalent site both contain that generated block, and **tests fail if either drifts** from what the command now emits.

Stating the policy twice is how the two halves of one origin come to disagree, so it is stated once.

### The content security policy

```
default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self';
connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'
```

Derived from the three things the build loads, and no wider:

- **No `'unsafe-inline'`, no `'unsafe-eval'`.** The production build needs neither. This is the whole value of the policy: with them, a CSP on an administrative console is decoration.
- **No `data:` in `img-src`.** Nothing uses a data: URI. Allowing them would widen the surface for an injected image-shaped payload in exchange for nothing.
- **No `font-src`, no `object-src` line.** No fonts are loaded, and `default-src 'none'` already covers both. A redundant directive is one more thing that has to stay true. `frame-ancestors`, `base-uri` and `form-action` *are* stated, because `default-src` does not cover them.
- **`frame-ancestors 'none'`**: the Console is never framed, by anyone, including itself.

Alongside it: `X-Content-Type-Options: nosniff`; `Referrer-Policy: same-origin`; `X-Frame-Options: DENY` (defence in depth for anything that does not honour CSP 2); a `Permissions-Policy` denying the device features the Console does not use; `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Resource-Policy: same-origin`; and `Cache-Control: no-store` on API responses, which carry the signed-in person's name, address, capabilities and factor state.

`X-Powered-By` must not be sent: it names a PHP patch level to look up and tells a user nothing. (Measured on the production host 2026-09-21: `expose_php` reads **On** in the ini, but **no `X-Powered-By` header reaches clients**, so nothing is being advertised today. Setting `expose_php = Off` remains worthwhile belt-and-braces, not an open finding.)

Deliberately **not** sent: `X-XSS-Protection` (the filter it enables is gone from every current browser and introduced holes of its own), `Expect-CT` (obsolete), `Feature-Policy` (superseded), `Cross-Origin-Embedder-Policy` (buys cross-origin isolation this application has no use for, at the cost of a constraint on every future subresource).

### HSTS, deliberately narrow

`max-age=31536000`, sent **only over HTTPS**, with **no `includeSubDomains` and no `preload`**.

Flow Life runs other hosts under `flowlifeglobal.org`, WordPress among them ([ADR 0004](0004-wordpress-adapter-not-authority.md)), whose certificates and HTTPS posture this application does not control. `includeSubDomains` asserted from here would hard-fail a sibling host that is not ready, from a component with no authority to make that promise; `preload` would make it irreversible. Covering the whole domain is a decision for whoever owns the apex, declared there.

### Development gets a different, weaker policy — and production is not bent to match

The development gateway serves the Console from Vite under a policy that allows `'unsafe-inline'`, `'unsafe-eval'` and `ws:`. What it does **not** give up is `frame-ancestors 'none'` and `base-uri 'none'`, which cost a dev server nothing. A test asserts that the development policy really is the weaker one and the production policy really is the stricter one, so nobody resolves a broken HMR session by loosening production.

### The policy is proved in a browser, against the real build

A second gateway site, `prod.flowlife.localhost`, serves the **actual Vite build** as static files beside the API under the **exact production headers** — the same arrangement Apache will serve. `e2e/security.spec.ts` drives real Chromium against it and shows both halves:

- the Console **starts, styles, signs in, challenges a second factor, renders the QR code, administers accounts, calls the API, copies and downloads recovery codes and scrubs a secret-bearing fragment**, with **zero** `securitypolicyviolation` events; and
- the policy **actually blocks**: a script from another origin, an inline script, `eval`, an image from another origin, a `fetch` to another origin, and being framed.

The second half is the point. A header that is present but not enforced is indistinguishable from an enforced one in a snapshot test.

## Consequences

- The Console's own HTML carries the policy in production, which a Laravel-only middleware could never have achieved.
- **The policy depends on `mod_headers` on the production host.** Without it, the static half of the origin ships with no security headers while the API keeps them. That is an owner verification item rather than something this repository can prove — and it was **verified on the real host on 2026-09-21**: a `Header always set` inside `<IfModule mod_headers.c>` reached the client, so the generated block is effective. Recorded in the [production readiness runbook](../runbooks/production-readiness.md).
- Any future dependency that needs an inline script, `eval`, a web font, a CDN or a third-party image **will break loudly** in the browser journeys before it reaches production. That is the intended cost.
- Passkeys would require revisiting `publickey-credentials-get=()` in the Permissions-Policy. They are explicitly out of scope, and the line is a deliberate tripwire.
- The e2e suite now builds the Console before it runs, so the production-equivalent site tests the current build rather than whatever was in `dist/` from another day.

## Alternatives considered

**Middleware only, in Laravel.** Simplest, and wrong for this topology: it would cover the API and leave `index.html` — the document an XSS would execute in — with no policy.

**`.htaccess` only.** Covers everything Apache serves, but development uses Caddy and the test suite uses neither, so the policy would be unverifiable in CI and unrepresented in the code that generates responses.

**Hashes or a nonce for inline scripts.** The machinery exists for builds that need inline script. This one does not, measured; adding nonce plumbing for a hypothetical would be complexity bought with no risk removed.

**`'unsafe-inline'` in `style-src`, "because React might".** It does not: there is no `style` attribute anywhere in the Console's source, and the built CSS is one external file. Allowing it "just in case" would weaken the one directive most likely to matter for an injected payload. If a future dependency needs it, the browser journey fails and the decision gets made deliberately.

**A report-only rollout first.** Sound for an application with existing users and unknown content. Here the content is one build that can be measured exactly and driven in a browser in CI, so enforcement can start enforced.

**Adding `Cross-Origin-Embedder-Policy: require-corp`.** Nothing cross-origin is loaded, so it would pass today — and would silently constrain every future asset in exchange for an isolation guarantee nothing here uses.
