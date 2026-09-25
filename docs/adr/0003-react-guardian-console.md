# ADR 0003: React, TypeScript and Vite for the Guardian Console

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Refined by:** [ADR 0026](0026-production-browser-security-policy.md), which states the browser security policy the Console is served under; [ADR 0030](0030-guardian-console-visual-system.md), which decides how the Console is styled and themed

## Context

Guardian operations need a rich, separately hardened operational UI. It should be a client of the platform API like any other, deployable as static files (production has no Node), and maintainable by a small team.

## Decision

Build the Guardian Console as a **React 19 + TypeScript (strict) single-page app built with Vite**, in `apps/guardian-console`. Quality tooling: ESLint (`typescript-eslint` strict, type-checked), Prettier, Vitest with Testing Library, and a small Playwright smoke test. No UI framework yet; one will be chosen when real screens exist. Styling: see [ADR 0012](0012-tailwind-4-via-vite.md). The API is consumed through a generated TypeScript client once the API justifies it ([ADR 0007](0007-versioned-rest-api-openapi.md)); until then the console makes a single, marked-temporary hand-written health call.

## Consequences

- Ships as static assets, compatible with cPanel hosting.
- Strict TypeScript flags (`noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, `verbatimModuleSyntax`, ...) catch mistakes early at the cost of some verbosity.
- **TypeScript is pinned to 6.0.x.** TypeScript 7 (the native rewrite) is now `latest`, but `typescript-eslint` declares support only up to `<6.1.0`. Revisit when `typescript-eslint` supports 7; do not silence the peer warning.
- The console holds no secrets: everything in `VITE_*` is public. Authorization is always server-side; hiding UI is never security.

## Alternatives considered

- **Server-rendered (Blade/Livewire/Inertia):** fewer moving parts, but couples the hardened console to the same deployment as the API and does not give a clean API-client boundary.
- **Next.js/other SSR frameworks:** need a Node runtime in production, which we do not have.
- **Vue/Svelte:** capable, but React has the larger talent pool and ecosystem for our needs.
- **Create React App / webpack:** unmaintained or slower than Vite.
