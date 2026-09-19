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

## Not designed yet

How the plugin authenticates to the API, how a WordPress-authenticated person is bound to a platform identity, what is cached in WordPress (if anything), and webhook/event notification back to WordPress. These are decided in the Identity/Access epic and the first real integration feature, informed by the [integration model](../architecture/integration-model.md).

## Local development

WordPress is **not** in the Compose stack yet. When the first feature needs it, it will be added as an optional `wordpress` profile (WordPress 6.x on PHP 8.3, its own database and user, the plugin folder bind-mounted, served at `wordpress.flowlife.localhost`). Until then `./flow check` syntax-checks the plugin's PHP. Standing up WordPress now would add setup and maintenance cost with nothing to exercise.
