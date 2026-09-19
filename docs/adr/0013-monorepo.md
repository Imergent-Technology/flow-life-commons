# ADR 0013: Monorepo

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none

## Context

The platform, Guardian Console, WordPress companion plugin and the generated API client evolve together against one API contract. A contract change should land with its consumers, in one reviewable change, and one CI run should prove they still agree.

## Decision

Keep everything in **one repository**:

```
apps/       platform, guardian-console, wordpress-companion
packages/   api-client (placeholder)
infrastructure/docker/
docs/  scripts/  .github/  flow
```

Each app has its own dependency manifest and lockfile (Composer for the platform, npm for the console). There is **no root npm workspace** yet; workspaces are introduced by ADR when `packages/api-client` has a real consumer. `compose.yaml` and `flow` sit at the root.

## Consequences

- Atomic cross-app changes and a single CI entry point (`./flow check`).
- One clone to learn; docs and ADRs live beside the code they govern.
- CI cost grows with the repo; path filters and layer caching keep it in check.
- Access control is repo-wide; if a component ever needs separate access (for example a public plugin), it can be split out then.

## Alternatives considered

- **Polyrepo (one repo per app):** clean ownership, but the API contract and its consumers would drift and every change becomes a coordinated multi-repo release.
- **Monorepo tooling (Nx, Turborepo, Bazel):** unjustified for two apps and a plugin.
