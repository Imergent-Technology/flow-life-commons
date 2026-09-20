# ADR 0016: Guardian Console authenticates via a same-origin, host-only session cookie

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Refined by:** [ADR 0023](0023-multi-factor-authentication.md), which adds the second factor and the step-up this ADR deferred to MFA
- **Clarified:** 2026-09-20. Two bullets under Decision named Laravel mechanics more specifically than the intent required; they are reworded below in light of the implementation. The decision itself is unchanged.

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

**Session cookie attributes**, exactly:

| Attribute | Value | Why |
| --- | --- | --- |
| Name prefix | `__Host-` | The browser then *refuses* the cookie if it carries `Domain`, or is set without `Secure` or with a `Path` other than `/`. This is what stops a sibling host shadowing it |
| `Secure` | yes | Required by the prefix; HTTPS-only in production |
| `HttpOnly` | yes | Unreadable by JavaScript, so XSS cannot exfiltrate it |
| `Path` | `/` | Required by the prefix |
| `Domain` | **absent** | Host-only. This is the attribute that keeps it off `flowlifeglobal.org` |
| `SameSite` | `Lax` | Correct for a same-origin SPA: it still arrives on the initial top-level navigation, so an operator following a link to the Console is not shown a spurious login screen, while cross-site POSTs carry nothing. CSRF tokens cover the remainder. `Strict` is a later tightening option, not a requirement |

- **Sessions are database-backed**, so they are revocable and enumerable. Session state is authoritative and must never live in a cache ([ADR 0010](0010-database-queue-redis-ready.md)).
- **Request forgery is prevented on stateful requests** by Laravel 13's `PreventRequestForgery` middleware (`ValidateCsrfToken` is a deprecated alias of it). Its semantics are origin-aware, and precisely these:
  - A browser request the browser itself marks `Sec-Fetch-Site: same-origin` may satisfy it **without a token**. Page scripts cannot set or forge that header.
  - When same-origin proof is absent, normal token verification applies: the `XSRF-TOKEN` cookie is JS-readable by design and echoed in `X-XSRF-TOKEN`.
  - **`same-site` is not accepted** (`allowSameSite` stays `false`). WordPress is a sibling, lower-trust site: its requests arrive as `same-site` at best, are never treated as equivalent to `same-origin`, and must carry a valid token.
  - The host-only `__Host-` cookie is an independent protection: it is not sent to or from sibling hosts, whatever the middleware decides.
  - No custom request-forgery middleware forces a token onto same-origin requests; it would need a concrete threat in this application to justify it.
- **Session middleware is applied explicitly to the browser/session-authenticated route surface**, meaning the Guardian Console's endpoints, as one named `stateful` stack (`EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, request-forgery protection, and the absolute-lifetime check). It is **not** applied indiscriminately to the whole API middleware group, and the API is not "stateful":
  - public endpoints stay stateless unless they genuinely need session state;
  - future service and client endpoints ([ADR 0018](0018-client-and-delegated-authentication.md)) authenticate by their own mechanism and acquire no browser session, cookie or request-forgery requirement;
  - applying the stack selectively weakens nothing: Guardian Console authentication itself remains session based.
- **No CORS for the Console** — same-origin requests are not subject to it. `supports_credentials` stays `false` and the production allow-list stays empty: no cross-origin credentialed access exists at all.
- **Sanctum is not used** for this first-party session flow, and is not installed. Its SPA feature exists to make stateful authentication work across origins, a problem we no longer have.
- Local development must be changed to the same single-origin shape so it exercises this model rather than a different one.

### Session lifetime

Two independent bounds, because they defend against different things:

| Bound | Value | Mechanism |
| --- | --- | --- |
| **Sliding inactivity** | 30 minutes | `SESSION_LIFETIME=30`. Laravel's session handler writes `last_activity` on each request and expires the session that long after the last one, so the framework's lifetime *is* a sliding timeout — no custom code |
| **Absolute** | 12 hours | Ours: record `authenticated_at` when authentication succeeds, and reject/invalidate the session once that age is exceeded, **regardless of subsequent activity**. Re-authentication establishes a new absolute lifetime |

**Why both.** A sliding timeout protects the operator who walks away; it gives an attacker nothing, because whoever holds a stolen cookie can keep it alive indefinitely by making one request every 29 minutes. The absolute cap converts "indefinite" into "bounded", which is the residual risk of cookie authentication once the cookie itself is host-only. Laravel has no absolute-lifetime concept, so this is roughly fifteen lines of middleware plus a test — small enough to build now and awkward to retrofit later, since existing sessions would have no `authenticated_at`.

**A distinction to state plainly:** `SESSION_LIFETIME=30` measures 30 minutes of **session request inactivity, not 30 minutes without human interaction**. Any authenticated request refreshes `last_activity`, so a future background poll from the Console could keep a session alive while nobody is at the keyboard. That is accepted for the initial architecture because the 12-hour absolute cap bounds the session independently of activity. Do **not** add browser activity tracking or other idle-detection machinery unless implementation reveals a concrete need; the 30-minute value must simply not be described as guaranteed human-idle detection.

`expire_on_close` stays off: browser session restore makes it unreliable, and the inactivity timeout already covers the case. Step-up re-authentication for sensitive actions is deliberately out of scope here and belongs with MFA.

The cookie property was measured in Chromium against sibling hosts rather than assumed:

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
- **Deployment coupling:** one document root serves both, with rewrites ordering `/api/*` to Laravel, existing files as static assets, and everything else to `index.html`. The apps stay separately built but become one web-server deployment unit. The mechanism has been exercised on Apache with PHP 8.3 in a representative container — Console at `/`, JSON from Laravel at `/api/v1/*`, SPA fallback for client-side routes, static assets served directly — but **that is not a verified fact about the production host.** The required hosting capabilities are recorded as assumptions in [deployment topology](../architecture/deployment-topology.md) and remain owner-verification items.
- **A session is bounded twice:** 30 minutes of request inactivity and 12 hours absolute. Operators re-authenticate at least daily, and a stolen cookie cannot be kept alive indefinitely.
- The Console's API base URL becomes a relative path; cross-origin configuration disappears.
- Development must move to one origin (`commons.flowlife.localhost`), which changes the gateway routing and invalidates the cross-origin premise of existing CORS and e2e assertions.
- `__Host-` requires `Secure`, which browsers permit over plain HTTP on `*.localhost` (verified), so local HTTPS is **not** required for parity.
- Service-to-service API access by WordPress is unaffected: it is not a browser and not subject to CORS ([ADR 0018](0018-client-and-delegated-authentication.md)).

## Alternatives considered

- **Cookie scoped to `.flowlifeglobal.org` across sibling subdomains:** the conventional Sanctum SPA arrangement, and **rejected on measured evidence** — such a cookie is transmitted to WordPress on every request, which is precisely the property we must not have.
- **Bearer token held in the browser:** works across origins, but must live in `localStorage` (XSS-stealable, persistent) or memory (lost on refresh), and revocation is weaker. Unacceptable for the most privileged surface.
- **Sanctum SPA mode across subdomains:** solves a problem we chose not to have, and requires the broader cookie scope rejected above.
- **JWTs:** no practical server-side revocation; a stolen token stays valid to expiry.
