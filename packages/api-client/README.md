# @flowlife/api-client (placeholder)

Reserved location for the **generated TypeScript client** of the platform API.
Nothing is generated yet: the API has one health endpoint, which does not justify a
generation pipeline.

## Direction ([ADR 0007](../../docs/adr/0007-versioned-rest-api-openapi.md))

- The contract lives in [`apps/platform/openapi/openapi.yaml`](../../apps/platform/openapi/openapi.yaml)
  and is kept honest by a test that fails when routes and spec drift.
- When the Guardian Console needs more than a handful of endpoints, this package will
  generate a typed client from that file, and the console will consume it instead of
  accumulating hand-written `fetch` calls (today there is exactly one:
  `apps/guardian-console/src/api/health.ts`, marked temporary).
- Generator choice, package wiring (npm workspaces) and CI regeneration checks are
  decided then, in an ADR, once there is a real consumer.
