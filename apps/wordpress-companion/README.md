# Flow Life Companion (WordPress plugin)

**Status:** skeleton only. It establishes the package location; it does no work yet.

## Role

A thin adapter that lets member and volunteer experiences appear inside WordPress
while the **platform** (`apps/platform`) stays the single source of truth.

## Rules (see [docs/integrations/wordpress.md](../../docs/integrations/wordpress.md), [ADR 0004](../../docs/adr/0004-wordpress-adapter-not-authority.md))

- WordPress is an adapter, never an authority. No organizational data of record
  lives in WordPress tables, options or user meta.
- WordPress roles and capabilities must **not** become the Flow Life authorization
  system. Any WordPress capability check is a presentation concern; the platform
  API enforces authorization on every request.
- Talk to the platform only through its versioned REST API (`/api/v1`).
- The platform must remain usable if WordPress is replaced or supplemented.

## Local development

WordPress is not part of the Docker Compose stack yet. When the first real feature
needs it, it will arrive as an optional `wordpress` Compose profile (see
[docs/development/docker.md](../../docs/development/docker.md)). Until then, `./flow check`
syntax-checks this plugin's PHP.
