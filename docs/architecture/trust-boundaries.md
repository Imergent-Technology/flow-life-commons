# Trust boundaries

Where trust changes, and what must be true on each side. **Authorization is always enforced server-side in the platform; UI visibility is never security.**

| Boundary | Trust stance |
| --- | --- |
| Browser → Platform API | The browser and everything in it (WordPress pages, the Guardian Console bundle, `VITE_*` values, local storage) is untrusted. Every request is authenticated and authorized by the platform. CORS is a browser convenience, not a control; the allowed origins are an explicit env allow-list, never `*`. |
| WordPress → Platform API | WordPress is an untrusted *client*, however much we control it: plugins, other admins and its own role system are outside our authority model. The companion authenticates as a client, and the platform still authorizes the *acting person*. WordPress roles/capabilities never grant platform access ([WordPress integration](../integrations/wordpress.md)). Client authentication design is deferred to the Identity/Access epic. |
| Guardian Console → Platform API | Hardened and separately deployed, but not privileged by virtue of being the console. Guardian capability comes from platform authorization like any other. |
| Platform → Database | The platform is the only database client in production. Credentials are host-provided secrets. |
| Platform → External services | Outbound calls go through `Infrastructure` boundaries, outside database transactions, with timeouts and retry safety ([integration model](integration-model.md)). Inbound callbacks/webhooks must verify signatures. |
| Development → Production | Nothing in the dev stack (Mailpit, Docker, dev credentials, `APP_DEBUG=true`) may be assumed in production. Debug configuration is confined to `.env.example` development placeholders. |

## Public, unauthenticated surface today

Only `GET /api/v1/health` (coarse pass/fail, no versions or hostnames) and Laravel's `GET /up`. Everything added later must be authenticated and authorized by default; public endpoints are the exception and need a stated reason.

## Not designed yet

Client and user authentication, session/token strategy, rate limiting, Guardian Console hardening (network restrictions, step-up authentication) and audit logging all belong to the Identity/Access epic. Nothing here should be read as a design for them.
