# WordPress integration

Decision: [ADR 0004](../adr/0004-wordpress-adapter-not-authority.md), refined by [ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md). Code: [`apps/wordpress-companion`](../../apps/wordpress-companion/). **Status: skeleton only, and expected to stay that way through the current member-facing work** (see [member access](../architecture/member-access.md)).

## Role

**WordPress hosts the public Flow Life Global site and links into Commons; it does not render authenticated Member functionality.** This is a change from this integration's original framing (member and volunteer functionality "exposed substantially through WordPress"): the Member-Facing Access design gate found that Commons can serve an authenticated self-service surface on infrastructure that already exists — the same session model the Guardian Console uses — at far lower cost than building the delegated-access machinery WordPress-hosted member UI would require first ([ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md)).

In this phase, WordPress's whole role is an ordinary link (a menu item or button) to Commons's sign-in/member area. That needs no plugin code. If and when a real requirement appears for WordPress to render platform data — a public, non-person dataset, or, later, person-specific data that must render *inside* a WordPress page — the adapter shape below is what it would use, via a service client (and, for person-specific data, delegated human authority) rather than by trusting a WordPress session:

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
7. **External identity, if it is ever needed, is a link, not a merge.** A WordPress user would be *linked to* a platform identity as platform-owned data — never treated as, or merged with, the platform identity itself. Not currently needed: Commons hosts sign-in directly, and WordPress does not currently identify individual people at all ([ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md)).

## How authentication would work, if WordPress ever needs it (designed, not built, not currently scheduled)

[ADR 0018](../adr/0018-client-and-delegated-authentication.md), refined by [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md), settles the invariants, which exist to keep rule 2 above true in practice whenever this is eventually built:

- WordPress authenticates **as an application**, with its own credential and a narrow capability list that **excludes person-scoped capabilities**. A machine acting alone can never reach an individual's data.
- Acting **on behalf of a person** requires a second, independent proof: a subject credential **the platform itself issued**, following standards-based authorization-code semantics rather than an invented handoff. The platform is the identity provider; WordPress is not.
- **The platform never accepts a client-asserted identity.** A `person_id` or `user_id` in a request, or a WordPress-signed claim, is not proof — ever. This is the single rule that keeps WordPress non-authoritative.
- A WordPress user is *linked to* a platform identity; the mapping is platform data — **only if** WordPress ever needs to identify a specific person at all, which the current design (linking to Commons instead) avoids needing.
- Browser CORS policy is irrelevant to this: service-to-service calls are not browser requests.

**Neither mechanism is built, and neither is scheduled**, because member-facing functionality is served from Commons instead ([ADR 0032](../adr/0032-members-use-a-commons-hosted-surface.md)). The trigger for building delegation is stated precisely in [ADR 0033](../adr/0033-service-identity-and-delegated-human-authority-are-distinct.md): person-specific Commons data that must genuinely render *inside* a WordPress-served page. A consequence worth restating from that ADR: a WordPress compromise while a service-only integration exists is bounded to what the service client itself may do (never a person); a WordPress compromise while a *delegated* integration exists and is in use additionally exposes whatever delegated authority is currently live in it, bounded by that authority's scope and remaining lifetime.

Still undesigned, and only relevant once either mechanism is actually triggered: what (if anything) WordPress caches, webhook and event notification back to WordPress, and the account-claim flow for a person who has no platform Account yet. These follow the first real integration feature, informed by the [integration model](../architecture/integration-model.md).

## Local development

WordPress is **not** in the Compose stack yet. When the first feature needs it, it will be added as an optional `wordpress` profile (WordPress 6.x on PHP 8.3, its own database and user, the plugin folder bind-mounted, served at `wordpress.flowlife.localhost`). Until then `./flow check` syntax-checks the plugin's PHP. Standing up WordPress now would add setup and maintenance cost with nothing to exercise.
