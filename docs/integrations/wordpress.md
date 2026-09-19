# WordPress integration

Decision: [ADR 0004](../adr/0004-wordpress-adapter-not-authority.md). Code: [`apps/wordpress-companion`](../../apps/wordpress-companion/). **Status: skeleton only.**

## Role

Member and volunteer functionality is initially exposed substantially through WordPress. A thin **companion plugin** is the adapter between WordPress and the platform. WordPress is a presentation surface; the platform is the authority.

```
WordPress page ─► Companion plugin ─► Platform REST API (/api/v1) ─► Platform modules ─► DB
   (renders)        (thin adapter)      (authenticates + authorizes)   (business rules)
```

## Rules

1. **Never the source of truth.** No organizational data of record in WordPress tables, options or user meta. Anything mirrored is a rebuildable projection; the platform wins.
2. **WordPress roles/capabilities must not become the Flow Life authorization system.** A capability check may hide a button; it never grants access. The platform authorizes every request server-side, on the acting *person*, not just the calling site.
3. **Thin.** The plugin renders platform data and forwards actions. Business rules in the plugin are a defect.
4. **Only the public API.** Talk to `/api/v1` as documented in the OpenAPI contract; no database access, no private endpoints.
5. **Replaceable.** The platform must work without WordPress, and another front end (or several) must be able to sit beside or replace it without touching the core.
6. **Untrusted client.** Treat WordPress and its plugins as outside our security perimeter ([trust boundaries](../architecture/trust-boundaries.md)).
7. **External identity is a link.** A WordPress user is *linked to* a platform identity; the mapping is platform data (Identity epic).

## How authentication will work (designed, not built)

[ADR 0018](../adr/0018-client-and-delegated-authentication.md) settles the invariants, which exist to keep rule 2 above true in practice:

- WordPress authenticates **as an application**, with its own credential and a narrow capability list that **excludes person-scoped capabilities**. A machine acting alone can never reach an individual's data.
- Acting **on behalf of a person** requires a second, independent proof: a subject credential **the platform itself issued**. The platform is the identity provider; WordPress is not.
- **The platform never accepts a client-asserted identity.** A `person_id` or `user_id` in a request, or a WordPress-signed claim, is not proof — ever. This is the single rule that keeps WordPress non-authoritative.
- A WordPress user is *linked to* a platform identity; the mapping is platform data.
- Browser CORS policy is irrelevant to this: service-to-service calls are not browser requests.

A consequence worth stating plainly: member-facing WordPress functionality cannot be delivered by trusting WordPress sessions, and therefore cannot ship before the delegated flow is built. That cost is accepted deliberately.

Still undesigned: what (if anything) WordPress caches, webhook and event notification back to WordPress, and the account-claim flow for a person who has no platform Account yet. These follow the first real integration feature, informed by the [integration model](../architecture/integration-model.md).

## Local development

WordPress is **not** in the Compose stack yet. When the first feature needs it, it will be added as an optional `wordpress` profile (WordPress 6.x on PHP 8.3, its own database and user, the plugin folder bind-mounted, served at `wordpress.flowlife.localhost`). Until then `./flow check` syntax-checks the plugin's PHP. Standing up WordPress now would add setup and maintenance cost with nothing to exercise.
