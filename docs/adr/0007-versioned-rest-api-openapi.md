# ADR 0007: Versioned REST API with an OpenAPI contract

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

The platform is consumed by several clients that we do not fully control or release in lockstep (a WordPress plugin, the Guardian Console, later others). We need a stable, documented contract and a way to avoid every client hand-rolling API calls.

## Decision

- Expose a **REST/JSON application API**, versioned in the URL from **`/api/v1`**. Breaking changes ship as a new version, not silent edits.
- The contract is an **OpenAPI 3.1** document at `apps/platform/openapi/openapi.yaml`, hand-maintained for now. A test fails if the routes served under `/api/v1` and the paths in the spec differ, so the contract cannot silently drift.
- The Guardian Console should eventually consume a **generated TypeScript client** (`packages/api-client`) rather than accumulating undocumented hand-written calls. Generation is **not built yet**: one health endpoint does not justify it. The generator, workspace wiring and CI regeneration checks are chosen in a later ADR when there is a real consumer.
- Each module owns its routes (`app/Modules/<Module>/Http/routes.php`); `routes/api.php` loads them under the prefix.
- Response envelope, pagination and error format (for example RFC 9457 problem details) are **not decided here**; they will be decided with the first real resource endpoints.

## Consequences

- One documented contract for every client; WordPress and the console are interchangeable consumers.
- A small cost per endpoint: describe it in the spec (the drift test enforces this).
- The health endpoint is exempt from future envelope decisions because it is an infrastructure probe.
- Laravel's `/up` liveness route remains separate from `/api/v1/health`.

## Alternatives considered

- **GraphQL:** flexible for clients, but heavier to secure, cache and authorize per field, and unnecessary for our access patterns.
- **RPC/gRPC:** poor fit for browser and WordPress clients.
- **Unversioned or header-versioned API:** less visible; URL versioning is simplest for the mixed client set.
- **Code-first spec generation (annotations):** couples the contract to implementation details and hides review of the contract itself.
