# ADR 0030: The Guardian Console visual system

- **Status:** Accepted (architecture decided; no part of it is implemented yet)
- **Date:** 2026-09-25
- **Supersedes:** none
- **Superseded by:** none
- **Refines:** [ADR 0003](0003-react-guardian-console.md), [ADR 0012](0012-tailwind-4-via-vite.md)

## Context

The Guardian Console was built function first. Styling is Tailwind utilities written inline at each call site, with a small `classes.ts` of shared strings; `src/index.css` contains one line, the Tailwind import. That was the right cost while the question was whether the Console worked at all. It is now the wrong cost: the Accounts and Members tables are duplicated, there are five separate definitions of what a button looks like, and every new page re-decides its own spacing and colour. There is no dark theme and no way to add one, because the palette is spelled out literally at hundreds of call sites.

The Console is an operator tool. The people using it are administrators doing consequential work — revoking access, issuing invitations, resetting credentials — often for long stretches. That argues for information density and for consequence being legible, and against a marketing aesthetic. It also argues for a dark theme, which is the single most requested affordance of a tool people sit in front of.

Four design rounds produced an agreed visual direction. The question this ADR answers is not what the Console should look like — that will keep moving, and should. It is **what structure makes the look changeable without the change rippling through the application**. Those are different lifetimes, and the distinction is the whole point of this record.

[ADR 0012](0012-tailwind-4-via-vite.md) deferred the component-library question until "real screens define the need". The screens now exist, and they do.

Two constraints are inherited and non-negotiable. [ADR 0026](0026-production-browser-security-policy.md) serves `default-src 'none'` with no inline script or style of any kind, which rules out the usual theme-flash-prevention trick of an inline bootstrap script. And the Console's existing accessibility behaviour — heading focus on navigation, announced alerts, native `<dialog>` focus return, Cancel first in confirmations — is tested behaviour that a visual change must not disturb.

## Decision

Adopt a **semantic design-token system** for the Guardian Console, with seven durable commitments. The specification at [`docs/design/guardian-console-visual-system.md`](../design/guardian-console-visual-system.md) and its token companion carry the values; this ADR carries only the structure.

**1. Components style by semantic role, never by literal value.** A component asks for `surface`, `primary`, `danger`, `muted-foreground`. It never names a colour, and it never carries a `dark:` variant. The role vocabulary is the stable interface; what each role resolves to is a design decision that can change on any given afternoon. A guardrail test bans raw palette utilities and arbitrary colour values in `src/pages` and `src/ui`, so the boundary is enforced rather than merely encouraged.

**2. Theming is CSS custom properties mapped into Tailwind 4.** Each role is a CSS variable; Tailwind's `@theme inline` maps variables to utilities. A theme is therefore a block of variable assignments, and adding one requires no component change and no build change. This is the CSS-first customisation [ADR 0012](0012-tailwind-4-via-vite.md) chose, used for the purpose it was chosen for.

**3. Three theme modes: Light, Dark and System, with System the default.** The resolved theme — always the literal `light` or `dark` — is stamped on `<html data-theme>` before first render. A `prefers-color-scheme` block in CSS carries the correct theme before any script runs, which is what makes this work under a policy that forbids inline script. System mode follows OS changes live.

**4. One narrowly scoped local UI preference, in one audited module.** A single `localStorage` key, owned by a single module, holding a schema version, the theme mode, and the navigation drawer choice. **Nothing else may ever go in it** — no identity, no account or session information, no capabilities, no API or business data, no credentials, no secrets. The existing guardrail that bans browser storage outright is split rather than relaxed: `sessionStorage`, `indexedDB` and `openDatabase` stay banned everywhere, and `localStorage` becomes legal in exactly one file. A unit test proves the module writes only that key with only those fields, and the end-to-end assertions that today prove storage is empty become an assertion that storage holds that one key and nothing more — so the property they were protecting (no secret, code or password reaches the browser's disk) is still proved, not merely still claimed.

These preferences survive sign-out, because they describe the device and its interface rather than the account. Synchronising them to the account remains possible later; it is not part of this decision.

**5. An attached rail with a responsive secondary drawer.** Navigation is a permanent rail of sections with a secondary drawer for the items within a section, driven by one typed definition that feeds the rail, the drawer, the mobile sheet and the breadcrumbs, so they cannot drift apart. Capability filtering governs what appears, as it does today.

The drawer's default is **derived from the viewport, not fixed**, under the principle: **pin only when enough workspace remains**. Below the rail breakpoint the shell is a mobile top bar with a modal navigation sheet; above it the drawer defaults to overlay; above a wider breakpoint it defaults to pinned. An explicit choice by the operator overrides the default and persists; until they make one, the viewport decides. The two breakpoint values live in the design specification and are expected to be tuned by browser testing — the principle is what this ADR fixes.

**6. Fonts are self-hosted and same-origin.** The interface faces are bundled and served from the application's own origin. No CDN, no third-party font service, no `data:` URI. This costs a `font-src 'self'` directive, and that is the only policy change the visual system requires; it is made together with its tests, and [ADR 0026](0026-production-browser-security-policy.md) is amended in the same work package. Shipping a brand image likewise requires no new directive — `img-src 'self'` is already served — but does require the same amendment, because ADR 0026 asserts the absence of fonts and images as a checkable property of the build.

**7. The design specification under `docs/design/` is the source of truth for mutable visual values.** Exact colours, typography sizes, radii, shadows, spacing, breakpoint values and component inventory live there and may change without an ADR. This record deliberately contains none of them. A change to a value is a design change; a change to any of the seven commitments above is an architectural one and needs a new ADR.

### Scope

This ADR covers the Guardian Console's visual architecture. It decides nothing about the platform's HTTP surface, the API contract, authorization, or any server-rendered surface. The maintenance page stays outside it and remains font-free and image-free by design.

## Consequences

**Easier.** A theme becomes a block of variable assignments; a third one would need no component change. A colour or a type scale changes in one file. New pages inherit the system by construction rather than by copying a neighbouring page. The duplicated tables, the five button definitions and `classes.ts` all collapse into shared primitives with tests of their own.

**Harder, and deliberately so.** A developer can no longer reach for `bg-slate-100` when the role vocabulary has no word for what they want; they have to add a role, which is a design conversation. That friction is the mechanism, not a side effect. Every new primitive must pass axe in both themes, which is more work per component than styling inline.

**New obligations.**

- **Both themes are now a supported surface.** Every contrast requirement, every axe run and every visual check applies twice. An automated contrast check over the token pairs is added so that a palette change cannot quietly fall below threshold.
- **The one storage key is a standing invariant.** It is worth more than the convenience it buys, and its value is entirely in never being widened. The guardrail and the storage assertions exist to make widening it fail loudly.
- **The policy amendment must not drift ahead of the policy.** ADR 0026's clauses are amended in the same work package that changes the header and its tests, never before.
- **The breakpoint values need real browsers.** They are a starting position derived from arithmetic about column widths, and browser QA at several widths is what settles them.

**Accepted costs.** Three font packages and three small class-composition dependencies (`class-variance-authority`, `clsx`, `tailwind-merge`) enter the Console's dependency set. Any future component library addition must clear the Console's content security policy, because some libraries inject `<style>` tags that `style-src 'self'` blocks — a constraint that has already shaped this design and will shape additions to it.

**Bounded risk.** An operator who has explicitly chosen a theme different from their OS setting may see one frame of unstyled ground before the module runs. A System user never does. This is the residual cost of having no inline script, and it is the correct trade.

## Alternatives considered

- **Keep literal Tailwind utilities and add `dark:` variants.** No new concepts, and it is where the Console already is. Rejected: it doubles every colour decision at every call site, makes a third theme impossible, and leaves the duplication that motivated the work. The `dark:` variant is a way to have two themes, not a way to have theming.

- **Adopt a component library wholesale (MUI, Chakra, Mantine).** Fast to start. Rejected: the Console's density, its accessibility behaviour and its content security policy are all specific enough that a library would be fought rather than used, and several inject inline styles that `style-src 'self'` blocks outright. Following shadcn's *conventions* while owning the source in `src/ui` gives the ergonomics without the coupling — and keeps the CSP constraint enforceable.

- **A runtime theme system in JavaScript (context, CSS-in-JS, or a token package).** More expressive. Rejected: it cannot paint before first render under this policy, it adds a runtime to every component, and CSS custom properties already do the whole job natively.

- **Store preferences server-side against the account.** No local storage at all, so the browser-storage ban would stand untouched. Rejected for now: it needs an API, a migration and a round trip before the first paint, and it makes the theme unavailable on the sign-in page, which is exactly where a dark-mode user first meets the application. Left open as a later addition rather than a replacement.

- **Pin the drawer from 1024px, as the original design had it.** Simpler: one desktop breakpoint. Rejected: at 1024px a pinned 236px drawer plus a 76px rail leaves a wide page around 700px, so the chrome wins an argument it should lose. Deriving the default from available width is barely more code and is right at every size.

- **Freeze the palette and type scale in this ADR.** Superficially attractive — one record, everything in it. Rejected: it is the mistake this ADR exists to avoid. Colours and sizes will move during implementation and after, and a record that must be amended for a hex change is a record nobody keeps current. Immutability is worth having only for decisions that deserve it.
