# ADR 0012: Tailwind CSS 4 via the Vite plugin

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Refined by:** [ADR 0030](0030-guardian-console-visual-system.md), which puts the CSS-first `@theme` customisation this ADR chose to work as a semantic token system, and answers the component-library question deferred below

## Context

The Guardian Console needs a utility-first styling approach with low ceremony. Tailwind 4 changed its integration: configuration is CSS-first, and a first-party Vite plugin replaces the PostCSS pipeline.

## Decision

Use **Tailwind CSS 4 with `@tailwindcss/vite`**. Styles start from a single `@import 'tailwindcss';` in `src/index.css`; customisation, when needed, is done in CSS (`@theme`). We do **not** create a legacy `tailwind.config.js`, PostCSS config or `autoprefixer` unless something actually requires them. `prettier-plugin-tailwindcss` sorts classes, pointed at the same stylesheet.

## Consequences

- Fewer config files and a faster build.
- A misconfigured plugin can still "build" while shipping unprocessed CSS, so `npm run verify:build` (run by `./flow build` and `./flow check`) asserts that Tailwind utilities are present in the built CSS and no raw `@import "tailwindcss"` remains. The Playwright smoke test also asserts a computed style.
- Some older Tailwind 3 tutorials and plugins do not apply; consult the v4 docs.

## Alternatives considered

- **Tailwind 3 with PostCSS:** more configuration, superseded.
- **CSS Modules / vanilla-extract / styled-components:** capable, but more code for the same result and no design system to justify it yet.
- **A component library (MUI, Chakra, shadcn/ui):** deferred until real screens define the need; the choice would be its own ADR.
