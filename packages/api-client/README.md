# @flowlife/api-client (placeholder)

Reserved location for the **generated TypeScript client** of the platform API.
Nothing is generated yet.

**Update (2026-09-28):** the "one health endpoint" and "exactly one hand-written fetch
call" facts below are stale and describe an early snapshot, not current reality. The
API now has around 30 routes, and the Guardian Console has several hand-written
`fetch` modules under `apps/guardian-console/src/api/` (`admin.ts`, `auth.ts`,
`csrf.ts`, `health.ts`, `http.ts`, `membership.ts`). That growth is itself the signal
this package's original trigger ("more than a handful of endpoints") anticipated;
generating a typed client from here has not yet been picked up as its own piece of
work, not because the trigger hasn't fired.

## Direction ([ADR 0007](../../docs/adr/0007-versioned-rest-api-openapi.md))

- The contract lives in [`apps/platform/openapi/openapi.yaml`](../../apps/platform/openapi/openapi.yaml)
  and is kept honest by a test that fails when routes and spec drift.
- This package will generate a typed client from that file, and the console (and any
  future Commons-hosted frontend, such as the member surface — see
  [member access](../../docs/architecture/member-access.md)) will consume it instead of
  accumulating hand-written `fetch` calls.
- Generator choice, package wiring (npm workspaces) and CI regeneration checks are
  decided then, in an ADR, once this is picked up as real work.
