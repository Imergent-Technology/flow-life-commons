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

## Now designed (not yet built)

The Identity and Access design gate resolved most of what this page previously deferred; see [identity-and-access.md](identity-and-access.md).

- **Console sessions.** The Console and API share one origin, and the session cookie is `__Host-` prefixed and host-only, so it is never transmitted to WordPress or any other Flow Life host, and cannot be shadowed from a parent domain. Measured in a browser, not assumed ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)).
- **Client vs person.** A request may prove *which application* is calling and *which person* it acts for; these are separate proofs. A client-asserted `person_id` is never accepted as identity ([ADR 0018](../adr/0018-client-and-delegated-authentication.md)).
- **Session bounds.** A session expires after 30 minutes of request inactivity and is capped at 12 hours from authentication regardless of activity, so a stolen cookie cannot be kept alive indefinitely ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)).
- **A second factor.** Console access requires TOTP (with recovery codes): a correct password starts a short-lived *pending sign-in* that is not authentication, and only the second factor establishes a session. What is required is tied to the Console surface, never to a role; recent verification for sensitive routes is a reusable middleware ([ADR 0023](../adr/0023-multi-factor-authentication.md)).
- **Rate limiting** applies to login, password reset and change, invitation acceptance, the second-factor challenge and security verification, keyed by IP and identifier.
- **Auditing** of authentication and authorization changes exists from the first epic ([ADR 0019](../adr/0019-security-event-auditing-seam.md)).

Still undesigned: administrative recovery of another person's second factor, external identity providers, and network-level Console hardening. Nothing here should be read as a design for those.
