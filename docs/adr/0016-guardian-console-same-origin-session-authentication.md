# ADR 0016: Guardian Console authenticates via a same-origin, host-only session cookie

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

The Guardian Console is the most privileged surface on the platform, so the dominant browser threat is theft of whatever credential it holds. A credential readable by JavaScript is exfiltrable by any XSS, and in `localStorage` it persists.

WordPress is expected to remain on `flowlifeglobal.org` and is deliberately a lower-trust component: it has plugins, other administrators and an update surface outside our control ([ADR 0004](0004-wordpress-adapter-not-authority.md)). A session cookie scoped broadly enough to cover both hosts would be transmitted to it.

The required security property is explicit: **a compromised WordPress installation must not receive, read, or rely upon the Guardian Console's authentication cookie.**

Laravel's `api` middleware group is stateless, so cookie authentication on `/api/v1` is a deliberate act either way.

## Decision

Serve the Console and the API **from one origin** in production:

```
https://commons.flowlifeglobal.org/          → Guardian Console (static build)
https://commons.flowlifeglobal.org/api/v1/   → Platform API
```

- **Session cookie:** `__Host-` prefixed, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, **no `Domain` attribute** — host-only to `commons.flowlifeglobal.org`.
- **Sessions are database-backed**, so they are revocable and enumerable. Session state is authoritative and must never live in a cache ([ADR 0010](0010-database-queue-redis-ready.md)).
- **CSRF is validated** on stateful requests; the `XSRF-TOKEN` cookie is JS-readable by design and echoed in `X-XSRF-TOKEN`.
- **Session middleware is added explicitly** to the API group (`EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ValidateCsrfToken`).
- **No CORS for the Console** — same-origin requests are not subject to it. `supports_credentials` stays `false` and the production allow-list stays empty: no cross-origin credentialed access exists at all.
- **Sanctum is not used** for this. Its SPA feature exists to make stateful authentication work across origins, a problem we no longer have.
- Local development must be changed to the same single-origin shape so it exercises this model rather than a different one.

The property was measured in Chromium against sibling hosts rather than assumed:

| Check | Result |
|---|---|
| `__Host-` cookie set at `commons.*` | Sent back to `commons.*` |
| Same cookie at a sibling `wordpress.*` host | **Not sent** — empty cookie header |
| Sibling host tries to shadow it via `Domain=` | **Rejected by the browser** (the `__Host-` prefix forbids `Domain`) |
| A parent-domain-scoped cookie, for contrast | **Leaks** to the WordPress host on every request |

## Consequences

- WordPress cannot receive the cookie (host-only) and cannot shadow it (`__Host-` prefix), which is the stated property, achieved by browser enforcement rather than convention.
- No credential is ever readable by JavaScript, so XSS cannot exfiltrate a portable token.
- Logout and forced revocation are a database delete.
- **Deployment coupling:** one document root serves both, with rewrites ordering `/api/*` to Laravel, existing files as static assets, and everything else to `index.html`. The apps stay separately built but become one web-server deployment unit. This must be confirmed against the production host.
- The Console's API base URL becomes a relative path; cross-origin configuration disappears.
- Development must move to one origin (`commons.flowlife.localhost`), which changes the gateway routing and invalidates the cross-origin premise of existing CORS and e2e assertions.
- `__Host-` requires `Secure`, which browsers permit over plain HTTP on `*.localhost` (verified), so local HTTPS is **not** required for parity.
- Service-to-service API access by WordPress is unaffected: it is not a browser and not subject to CORS ([ADR 0018](0018-client-and-delegated-authentication.md)).

## Alternatives considered

- **Cookie scoped to `.flowlifeglobal.org` across sibling subdomains:** the conventional Sanctum SPA arrangement, and **rejected on measured evidence** — such a cookie is transmitted to WordPress on every request, which is precisely the property we must not have.
- **Bearer token held in the browser:** works across origins, but must live in `localStorage` (XSS-stealable, persistent) or memory (lost on refresh), and revocation is weaker. Unacceptable for the most privileged surface.
- **Sanctum SPA mode across subdomains:** solves a problem we chose not to have, and requires the broader cookie scope rejected above.
- **JWTs:** no practical server-side revocation; a stolen token stays valid to expiry.
