# Guardian Console visual system: specification and implementation plan

**Product:** Flow Life Commons · Guardian Console (`apps/guardian-console`, React 19 + Tailwind CSS 4)
**Status:** Accepted, 25 September 2026. This is the source of truth for the Console's mutable visual values.
**Decision record:** [ADR 0030](../adr/0030-guardian-console-visual-system.md) holds the durable architecture. This file holds everything the ADR deliberately leaves out.
**Companion file:** [`guardian-console-tokens.css`](guardian-console-tokens.css) is the token reference for the implementation work. Its target is `apps/guardian-console/src/theme/tokens.css`, copied there in WP1 — it is not application code while it lives here.

This specification is the agreed outcome of four design rounds, written so that it can be built from. Colours, type sizes, radii, shadows and spacing are expected to move during implementation and browser QA; when they do, they move **here**, not in the ADR. A value changing in this file is a normal design change, not an architectural one.

---

## 1. Decisions

| Topic | Decision |
|---|---|
| Direction | "C1 Grounded": attached structure, atmospheric wash at the top of the window, serif page titles. |
| Light theme | **Garden**: sage-tinted neutrals and a eucalyptus wash, with the **Meadow** top line (sage, gold, apricot, rose). |
| Dark theme | **Deep Tide**: teal-ink neutrals and a tide wash, with the **Violet** top line. |
| Theme modes | System (default), Light, Dark, chosen from the account menu. |
| Top line | Along the window's top edge, full width, over the rail too. |
| Naming | The wordmark reads **Flow Life Commons**, with **Guardian Console** as a subordinate descriptor beneath it. Page names stay functional: Overview, Accounts, Members, Account security. |
| Navigation | An attached rail with a secondary drawer from the outset. The drawer can be pinned, and its **default follows the viewport** (section 6). |
| Account security | In the account menu only, not in the rail. |
| Logo | The Flow Life Sanctuary badge, to be added as a same-origin image file (already allowed by `img-src 'self'`). Sizes: 50px in the rail, 38px beside the wordmark in the navigation sheet, 40px in the mobile top bar, 156px on the sign-in and invitation pages. The asset does not exist in the repository yet — see section 13. |
| Fonts | Self-hosted: Newsreader (titles), Hanken Grotesk (interface), JetBrains Mono (identifiers). This needs `font-src 'self'`. |
| Preferences | One narrowly scoped `localStorage` key, owned by a single UI-preferences module. It holds a schema version, the theme mode and the drawer choice, and nothing else. Syncing to the account can come later. |
| Violet | Confirmed. Hue 253°, in the middle of the logo's own range (about 235° to 283°). |

## 2. Principles

1. **Semantic before literal.** Components use role tokens (`surface`, `primary`, `danger`), never palette names, and never need a `dark:` variant. A guardrail enforces this.
2. **Width follows the task.** Pages declare a width (prose, form, detail, wide). The shell never caps everything.
   Its corollary for navigation: **pin only when enough workspace remains.** Chrome that permanently occupies a column has to earn it from the content beside it, so the drawer's default is a function of the viewport, never a fixed habit.
3. **Consequence is visible.** Destructive actions sit in a danger zone, are outlined in context, and are solid only inside the confirming dialog.
4. **Violet means "here" or "act".** It is used for the current place, the primary action, focus and selection. It is never used on surfaces.
5. **Atmosphere stays at the edges.** The wash and the top line live at the top of the frame. Content surfaces stay calm and solid.
6. **Never colour alone.** Every status has a word and a shape. Focus is a 2px ring with an offset in both themes.
7. **Density is a desktop affordance, not a default.** The compact 14px operational interface holds on desktop. Where the pointer is coarse or the viewport is narrow, the interface steps up rather than asking the operator to pinch — and for form controls that step-up is a correctness requirement, not a comfort one (section 4).
8. **Keep today's accessibility behaviour.** Heading focus on navigation, announced alerts, native `<dialog>` focus return, and Cancel first in confirmations are all preserved exactly.

## 3. Colour tokens

Names follow shadcn's conventions where one exists (`surface` is shadcn's `card`, `surface-raised` is `popover`), so its primitives drop in without renaming. All values are in `guardian-console-tokens.css`.

| Token | Garden (light) | Deep Tide (dark) | Use |
|---|---|---|---|
| `background` | `#F3F5F3` | `#0E1417` | Page ground |
| `surface` | `#FFFFFF` | `#151C20` | Panels, tables |
| `surface-raised` | `#FFFFFF` | `#1C252A` | Menus, dialogs, overlay drawer |
| `muted` | `#EAEEEB` | `#192126` | Wells, hover fills, table header band |
| `foreground` | `#1D1A26` | `#EEEBF4` | Body text |
| `muted-foreground` | `#5A6460` | `#9EAFB4` | Descriptions, metadata, secondary cells |
| `subtle-foreground` | `#848E89` | `#71838A` | Placeholders and separators only. Never required information. |
| `border` | `#DDE3DF` | `#233036` | Panel and row dividers |
| `border-strong` | `#CAD2CD` | `#303E45` | Secondary button outline, emphasis |
| `input` | `#8C968F` | `#6C7F87` | Form control boundary (3:1 or better against the field) |
| `field` | `#FFFFFF` | `#121A1D` | Input background |
| `ring` | `#7456E3` | `#B09CFF` | Focus ring |
| `primary` | `#6140D6` | `#A790FF` | Primary action, selection |
| `primary-hover` | `#5232C0` | `#B8A6FF` | |
| `primary-foreground` | `#FFFFFF` | `#170F30` | Text on primary |
| `accent` / `accent-foreground` | `#EDE7FD` / `#4529B0` | `#2A2342` / `#D3C8FF` | Soft selection, avatar, role chip, info alert |
| `success` / `success-soft` | `#1B714A` / `#E2F2EA` | `#68C996` / `#14261D` | Active, done, healthy |
| `warning` / `warning-soft` | `#8E4F00` / `#FBEEDB` | `#E9B55E` / `#2A2215` | Invited, waiting, running low |
| `danger` / `danger-soft` / `danger-foreground` | `#B0261C` / `#FCEAE8` / `#FFFFFF` | `#F47E73` / `#2E1717` / `#2A0C09` | Errors, destructive actions, revoked |
| `neutral-soft` / `neutral-foreground` | `#E8ECE9` / `#46504B` | `#1C262B` / `#B4C3C8` | Disabled, inactive |
| `scrim` | `rgba(29,26,38,.40)` | `rgba(4,3,10,.62)` | Dialog `::backdrop`, mobile navigation sheet |

### Navigation and atmosphere

| Token | Garden | Deep Tide | Use |
|---|---|---|---|
| `nav` | `rgba(255,255,255,.50)` | `rgba(3,10,12,.38)` | Rail and pinned drawer, translucent over the wash (no blur) |
| `nav-foreground` | `#38433E` | `#C2D0D4` | Items |
| `nav-strong` | `#1D1A26` | `#F2EFF7` | Drawer title, current rail label |
| `nav-muted` | `#7E8984` | `#6F848B` | Group labels |
| `nav-active` | `#E6DFFC` | `rgba(167,144,255,.16)` | Current item and rail pill |
| `nav-active-foreground` | `#3B22A0` | `#FFFFFF` | |
| `nav-active-icon` | `#6140D6` | `#B8A6FF` | |
| `nav-border` | `rgba(202,210,205,.85)` | `rgba(48,62,69,.75)` | Rail and drawer edges |
| `wash` | `#DAE9E1` → `#E5EEE9` (30%) → transparent, 340px tall | `#11313A` → `#16243A` (34%) → transparent, 340px tall | Top of the window only, behind rail and content |
| `horizon` | Meadow, 3px: `#8FC3A6` → `#D9C27E` (45%) → `#EDA283` (75%) → `#D98AA4` | Violet, 2px: transparent → `#A790FF` (28%–72%) → transparent | Window top edge. Decorative and `aria-hidden`. |

### How it sits in Tailwind v4

- `src/index.css` imports `tailwindcss`, then `./theme/fonts.css` (the `@font-face` rules) and `./theme/tokens.css` (the companion file).
- **Garden** is defined in full on `:root`. **Deep Tide** is defined in full on `[data-theme=dark]`. A `prefers-color-scheme: dark` block (guarded by `:root:not([data-theme=light])`) repeats Deep Tide, so the right theme paints before any script runs.
- An `@theme inline` block maps each variable to a Tailwind colour, giving utilities such as `bg-surface`, `text-muted-foreground` and `border-input`.
- `@custom-variant dark` is keyed to `[data-theme=dark]`, but components should never need it.
- The default Tailwind palette stays available. A guardrail rule stops raw palette utilities appearing in `src/pages` or `src/ui` (WP6).

## 4. Typography

| Role | Face | Size / line height | Weight, tracking | Where |
|---|---|---|---|---|
| Page title | Newsreader | 31 / 1.12 (27 below 768px) | 500, −0.012em | One `h1` per page (`PageHeading`) |
| Dialog and drawer title | Newsreader | 21 / 1.15 | 500, −0.01em | Modal heading, drawer header |
| Section title | Hanken Grotesk | 15.5 / 1.35 | 620, −0.005em | Panel `h2` |
| Body | Hanken Grotesk | 14 / 1.45 | 400 | Text and table cells |
| Form control | Hanken Grotesk | 14 / 1.45 on desktop, **16 minimum** on narrow or coarse-pointer layouts | 400 | Every text-accepting control (section 4.1) |
| Label | Hanken Grotesk | 13 / 1.4 | 560 | Form labels. Buttons are 13.5. |
| Meta | Hanken Grotesk | 12.5 / 1.4 | 400, muted | Hints, timestamps. Table headers use 550. |
| Identifier | JetBrains Mono | 12.5 / 1.4 | 400 | ULIDs, recovery codes, setup keys |

- **Packages:** `@fontsource-variable/newsreader`, `@fontsource-variable/hanken-grotesk` and `@fontsource-variable/jetbrains-mono`, bundled by Vite into hashed `woff2` files served from `/assets`. Confirm the exact package names and axes (Newsreader's optical size) when installing.
- **Subsets:** latin plus latin-ext only. latin-ext is required for names with diacritics, such as the macron in Hēnare.
- **Loading:** Hanken Grotesk uses `font-display: optional` with a size-adjusted local fallback, so tables never reflow. Newsreader and JetBrains Mono use `swap`.
- **Numbers:** `tabular-nums` on tables, property lists, dates and counts.
- **Serif discipline:** Newsreader appears only in page, dialog and drawer titles and in the wordmark. Never in controls, labels or tables.

### 4.1 The form-control floor

Mobile browsers — Safari on iOS most visibly — zoom the viewport when a control smaller than 16px takes focus, and they do not zoom back out. The operator is then stranded in a magnified layout mid-form. The fix is a **system rule, not a per-field exception**:

> Every text-accepting control (`input`, `select`, `textarea`, and any control the primitives build on them) renders at **no less than 16px** wherever the pointer is coarse or the viewport is narrow. Everywhere else it renders at the 14px body size.

- It is carried by **one token**, `--text-control`, which resolves to 14px by default and to 16px under `(max-width: 767.98px), (pointer: coarse)`. The token is defined once in `tokens.css`; `Input`, `Select`, `Textarea` and `TotpCodeField` consume it and never set a size of their own.
- Because it is a token and not a utility, a new control inherits the floor by construction. A control that hard-codes `text-body` or `text-[14px]` is the bug, and the WP6 guardrail against literal values in `src/ui` is what catches it.
- The desktop interface is unaffected: 14px controls beside 13px labels remain the operational density.
- The same breakpoints already step control **heights** up one size on coarse pointers (section 5), so the floor and the target sizes move together rather than fighting each other.

**Acceptance:** a unit test asserts the computed `font-size` of each control primitive is at least 16px under a coarse-pointer/narrow media context, and 14px otherwise; the 375px pass of the WP6 visual QA confirms no zoom-on-focus in a real mobile browser.

## 5. Shape, space, elevation, motion

- **Radius:**
  - `radius-sm` 7px for controls and nav items.
  - `radius-md` 9px for menus, wells and alerts.
  - `radius-lg` 14px for panels, dialogs and the drawer.
  - `radius-pill` for badges, the rail pill and the avatar.
- **Control heights:** `control-sm` 28, `control-md` 34 (default), `control-lg` 40 (sign-in pages). Coarse pointers step up one size, and everything in a row shares a height.
- **Density:** table rows 42px, header 36px, cell padding 14px across. Panel padding is 16 by 18. The page gutter clamps from 16 to 32px.
- **Elevation:**
  - `shadow-panel` is a 1px whisper in light and none in dark.
  - `shadow-pop` is for menus, the overlay drawer and dialogs. In dark it adds a 1px outline, because shadows don't read on dark grounds.
- **Motion:**
  - `fast` 120ms for hover and press.
  - `base` 160ms for menus.
  - `slow` 180 to 200ms for the drawer and dialogs.
  - All use `cubic-bezier(.2,.8,.2,1)`. The theme switches instantly, and everything drops to 0 under `prefers-reduced-motion`.
- **Page widths:**
  - `prose` 42rem, `form` 36rem and `wide` 92rem.
  - `detail` 74rem, a two-column grid with a 20rem aside from about 1200px of content.
  - Content is left-aligned to the navigation, not centred.

## 6. Shell and navigation

### Anatomy (1200px and wider, drawer pinned)

```
┌ horizon line (full width, window top) ───────────────────────────────────────────┐
│ rail │ drawer (pinned)  │ top bar: breadcrumbs ……………………………… account trigger │
│ 76px │ 236px            │───────────────────────────────────────────────────────── │
│ logo │ Administration   │ page (declares its width; left-aligned)                  │
│  ⌂   │  Accounts        │                                                          │
│ Over │   All accounts ● │                                                          │
│  ☺   │   Invite …       │                                                          │
│ Admin│  Members         │                                                          │
│      │   All members    │                                                          │
│      │   Add a member   │                                                          │
└──────┴──────────────────┴──────────────────────────────────────────────────────────┘
```

### Navigation model

One typed definition drives the rail, the drawer, the mobile sheet and the breadcrumbs, so they cannot drift apart:

```ts
// src/shell/navigation.ts
type NavItem    = { label: string; to: string; capability?: Capability; end?: boolean }
type NavGroup   = { label: string; items: NavItem[] }
type NavSection = { id: string; label: string; icon: IconName; to?: string; groups?: NavGroup[] }

export const sections: NavSection[] = [
  { id: 'overview', label: 'Overview', icon: 'home', to: '/' },
  { id: 'admin', label: 'Admin', icon: 'people', groups: [
    { label: 'Accounts', items: [
      { label: 'All accounts',       to: '/admin/accounts',        capability: ACCOUNTS_VIEW },
      { label: 'Invite an operator', to: '/admin/accounts/invite', capability: INVITATIONS_ISSUE, end: true } ] },
    { label: 'Members', items: [
      { label: 'All members',  to: '/admin/members',     capability: MEMBERSHIP_VIEW },
      { label: 'Add a member', to: '/admin/members/new', capability: MEMBERSHIP_MANAGE, end: true } ] } ] },
]
```

- **Capabilities:** items are filtered by `hasCapability` as today. A group with no visible items is dropped, and so is a section with no visible groups. The drawer title reads "Administration" and the rail label "Admin".
- **Current state:**
  - The item for the current route gets `aria-current="page"`.
  - Detail routes such as `/admin/accounts/:id` mark "All accounts" as current, while `/invite` and `/new` are exact matches.
  - The section's rail button gets `aria-current="true"`.
- **Sections without children:** Overview navigates directly. When the current section has no groups, the pinned drawer column is omitted and the content takes the width. The pin preference is kept.
- **Account security:** in the account menu only, so no rail section is current on that page.
- **Rail:** 76px wide, with the logo at 50px at the top. Section buttons are 60 by 48, with a 44 by 30 pill behind the icon and a text label underneath. Buttons are never icon-only.

### Responsive model

The governing principle is **pin only when enough workspace remains**. A pinned drawer costs 236px permanently; at 1024px that leaves a `wide` page barely 700px of content, which is worse than the drawer being one click away. So the *default* is derived from the viewport, and the operator's explicit choice overrides it.

| Viewport | Shell | Secondary navigation defaults to |
|---|---|---|
| Below 1024px | Mobile top bar, no rail | **Modal navigation sheet** (rail and drawer merged; see below) |
| About 1024–1199px | Desktop rail, attached | **Overlay** — the drawer floats over the content, summoned from the rail |
| About 1200px and wider | Desktop rail, attached | **Pinned** — the drawer holds its own grid column |

- **The wide breakpoint is tunable.** 1200px is the starting value, expressed once as `--bp-pin` in `tokens.css`. Implementation and browser QA (WP4, WP6) may move it; moving it is a change to this specification and to that one token, and to nothing else. What must not change is the principle above.
- **The preference overrides the default, and only when set.** `nav` is **absent** from stored preferences until the operator pins or unpins the drawer themselves. While absent, the breakpoint decides. Once set, the choice is honoured at every desktop width — an operator who unpins at 1440px stays unpinned.
- **Below 1024px the preference is not consulted at all**, and is not cleared either: it is a desktop concept, and the operator gets it back when they return to a desktop width.
- **Crossing a breakpoint never silently rewrites the preference.** The layout re-derives; storage is written only by an explicit pin or unpin.

### Drawer behaviour

| Mode | Layout | Opens and closes | Focus |
|---|---|---|---|
| Pinned (default at ≥1200px) | A 236px grid column between the rail and the content, translucent over the wash. It pushes the content. | Always open for sections with groups. The header button unpins it (label "Unpin navigation panel"). | A normal part of the tab order: rail, then drawer, then top bar, then page. |
| Overlay (default at 1024–1199px) | 256px on `surface-raised` with `shadow-pop`, over the content, non-modal and with no scrim. | Toggled by the section's rail button (`aria-expanded`, `aria-controls`). Closes on choosing a page, Escape, or a click outside. The header offers a pin button and a close button. | Opening moves focus to the current item, or the first. Escape returns focus to the rail button. Choosing a page hands focus to the page heading (current behaviour). |

The drawer is a plain element, not a `<dialog>` or popover, because it is non-modal and part of the page structure in pinned mode. Its slide (180ms, from 10px to the left) is removed under reduced motion.

### Below 1024px

- **Top bar:** 56px tall, holding a menu button, the logo (40px), the wordmark, and the avatar-only account trigger. The horizon line stays at the window top.
- **Navigation sheet:** the menu button opens a **modal navigation sheet**, a native `<dialog>` 300px wide from the left, with the scrim.
  - It merges rail and drawer: sections appear as headings, with their groups and items listed in full.
  - It closes on choosing a page or on Escape, and focus returns to the menu button.
- **Tables and drawer:** tables become stacked rows below 768px. The drawer preference does not apply.

### Naming and the wordmark

The product is **Flow Life Commons**. **Guardian Console** is the descriptor for this particular interface onto it, not a product in its own right. They are set as two lines, the first dominant:

```
Flow Life Commons      ← wordmark, Newsreader, primary
Guardian Console       ← descriptor, Hanken Grotesk, meta size, muted-foreground
```

- The pair appears in the navigation sheet header, on the sign-in and invitation pages, and in the `<title>` ("Flow Life Commons · Guardian Console"). The rail is too narrow for it and shows the badge alone.
- **The software is never called "Flow Life Sanctuary."** The current badge asset carries Sanctuary lettering, and that is a fact about the artwork, not about the product. Sanctuary is a programme name that appears in membership vocabulary (see [ADR 0029](../adr/0029-commerce-providers-own-payment-facts.md)); it is not the name of this application.
- Page names stay functional and are never prefixed with the product: **Overview**, **Accounts**, **Members**, **Account security**. Navigation keeps **Overview** and **Admin** in the rail, with **Administration** as the drawer title. No further naming exploration is needed.
- A simplified mark may later replace the badge in the rail and favicon. Because the badge is referenced as one asset at four declared sizes, that swap is an asset change and touches no architecture.

### Top bar and page header

- **Top bar:** 56px tall and transparent over the wash.
  - Breadcrumbs sit on the left for detail and form pages ("Accounts / Tomás Okafor"), replacing today's "← Accounts" links.
  - The account trigger sits on the right.
- **Page header:** an `h1` in the display face, with an optional status badge, a one-line description, and the page's single primary action on the right.
- **Sign-in and invitation pages:** a centred 400px column over the wash with the horizon line. The logo sits above an `h1` "Sign in" (Newsreader, 32px), followed by a panel with the form and a full-width 40px primary button.

## 7. Account menu

- **Trigger:**
  - A 36px pill with an initials avatar, the display name and a chevron. Mobile shows the avatar only, with `aria-label="Account menu"`.
  - It carries `aria-haspopup="menu"`, `aria-expanded` and `aria-controls`.
- **Contents, in order:**
  1. A header with name, email, and the first assignment name from `/me` as a chip.
  2. Account security.
  3. Theme: a Light, Dark, System segmented group of `menuitemradio`.
  4. Sign out.
- **Keys:**
  - Enter, Space or ↓ opens the menu on the first item, and ↑ opens it on the last.
  - ↑↓ move, Home and End jump, and ←→ move within Theme.
  - Escape closes and refocuses the trigger. Tab closes and moves on. A click outside closes without taking focus.
- **Theme choice:** applies at once, saves the preference, and keeps the menu open.
- **Sign out:** absorbs `SignOutButton`. While pending it reads "Signing out…" and is disabled. On failure the menu closes and today's error alert appears at the top of `main`, so the "does not pretend to be signed out" behaviour is kept.
- **Build:** the native `popover` attribute (top layer, light dismiss, no inline style) plus a small roving-focus hook, positioned with CSS under the trigger. It must pass the existing production-CSP browser journey.

## 8. Components

The primitives use `cva` variants and a `cn()` helper (`clsx` plus `tailwind-merge`). They follow shadcn conventions, with the source owned in `src/ui`.

| Primitive | Variants and rules | Replaces |
|---|---|---|
| `Button` | `primary · secondary · ghost · danger (outline) · danger-solid` × `sm · md · lg`. Pending state keeps its width and swaps the label. One primary per view. `danger-solid` is used only in confirmations. | `classes.ts`, `SubmitButton`, inline button strings (5 definitions) |
| `Field`, `Input`, `Select`, `Checkbox` | Label, then hint, then control, then error. Errors are tied by `aria-describedby` with an icon plus text. `aria-invalid` gives a danger border and a 1px ring. Keep all of `TextField`'s credential attributes. | `TextField` styling, raw inputs and selects in pages |
| `Badge` | `success · warning · neutral · danger · accent`. Shape carries meaning without colour: a filled dot means live, a diamond means waiting, a hollow ring means off. | `StatusBadge`, `MembershipStateBadge` |
| `Panel` | Header (title, description, actions) and body. A `danger` tone tints the border, and the danger-zone panel always comes last. | Ad hoc bordered divs and bare sections |
| `Alert` | `error · success · warning · info`, icon plus text. Keep the `alert`/`status` roles and `focusOnMount` exactly. | Restyle of `Alert` |
| `Modal`, `ConfirmDialog` | Keep the native `<dialog>` and its focus logic. Add a tone icon, a display-face title and a `scrim` backdrop, with Cancel first and focused. | Restyle only |
| `DataTable`, `Pagination` | Tinted sticky header, 42px single-line rows (ellipsis plus `title`), name cell as the link, footer with a range and Previous/Next, and stacked rows below 768px. | Duplicated Accounts and Members tables and navs |
| `PropertyList` | A 130px term column and tabular values. Values wrap anywhere, so there's no `break-all`. | Inline `dl` grids |
| `EmptyState`, `Skeleton` | The empty state names what is empty and offers one next action if permitted. Skeleton rows match the table's shape, next to the kept `role="status"` text. | Bare "Loading…" and "No … match" sentences |
| `Page`, `PageHeader` | `width` prop (prose, form, detail, wide) and the header layout. `PageHeading`'s focus and title behaviour is kept. | Per-page `max-w-*` |
| `QrCode` | Always dark on a white plate, including in Deep Tide, as its source comment requires. | |

## 9. Theme and preferences

### The preferences module

- **One file owns browser storage:** `src/ui/preferences.ts`. It uses one `localStorage` key, `flowlife.console.ui`:

  ```ts
  { v: 1, theme: 'system' | 'light' | 'dark', nav?: 'pinned' | 'overlay' }
  ```

- **Three fields, and no fourth is representable.** A schema version, the theme mode, and the drawer choice. That is the whole permitted surface.
- **`nav` is optional by design.** It is absent until the operator explicitly pins or unpins. While absent, the viewport decides (section 6). This is what lets the responsive default work without the module ever guessing on the operator's behalf.
- **Strict parsing:** anything unrecognised, malformed or from another schema version falls back to the defaults (`theme: 'system'`, `nav` unset). Unknown fields are dropped on the next write. Every access is wrapped in `try/catch`, so private mode or blocked storage simply means defaults.
- **Forbidden contents:** no identity, no account or session information, no capabilities, no API or business data, no credentials, no secrets — ever. The module's type makes anything else unrepresentable, and the guardrail below makes the module the only place that could try.
- **Sign-out:** preferences survive sign-out, because they describe the device and its interface, not the account. Account-level synchronisation remains possible later and would not change callers; it is not part of this design.

### Theme provider

- **Before first render:** `main.tsx` reads the preference synchronously and stamps `<html data-theme>` (the resolved `light` or `dark`) and `color-scheme` before `createRoot`. No inline script is needed, which the CSP forbids.
- **Before the module runs:** the CSS `prefers-color-scheme` block paints the right ground, so a System user never sees a flash. An explicit choice that differs from the OS setting can flash only the empty body for one frame.
- **System mode:** a `matchMedia` listener follows OS changes live.
- **Hooks:** `useTheme()` exposes the preference, the resolved theme and a setter. `useNavPreference()` does the same for the drawer, resolving the stored choice against the `--bp-pin` breakpoint and reporting both the resolved mode and whether it came from the operator or the viewport.

### Guardrail change (narrow, not general)

Split the existing browser-storage rule in `src/guardrails.test.ts`:

```ts
{ name: 'browser storage',               // sessionStorage, indexedDB, openDatabase: still banned everywhere
  pattern: /\b(?:sessionStorage|indexedDB|openDatabase)\b/, … },
{ name: 'localStorage outside the UI-preferences module',
  because: 'only non-sensitive display preferences may persist, through one audited module',
  pattern: /\blocalStorage\b/,
  allowedIn: /(^|\/)ui\/preferences\.ts$/, … },
```

- A unit test proves the module writes only the one key with only the allowed fields, even when given extra input.
- The eight e2e `storageSizes` assertions (in the `console`, `mfa` and `administration` specs) move to a helper, `expectOnlyUiPreferences(page)`.
  - It passes only if session storage is empty and local storage holds at most the one key, whose parsed value has exactly the allowed fields.
  - This proves no secret, code or password reached storage, just as today.

## 10. Security policy changes (fonts)

Adding `font-src 'self'` is the only policy change. Every place that defines or asserts the policy has to change together:

| File | Change |
|---|---|
| `apps/platform/config/security.php` | Add `"font-src 'self'"` to `csp`. Update the comment that says "no fonts". |
| `apps/platform/public/.htaccess` | The production header gets the same directive. Confirm `woff2` is served as `font/woff2` with `nosniff` and immutable caching like the other hashed assets. |
| `infrastructure/docker/caddy/Caddyfile` | Update the production-shaped policy. The development policy already allows fonts through `default-src 'self'`. |
| `apps/platform/tests/Feature/Modules/Security/BrowserSecurityPolicyTest.php`, `ProductionSurfaceTest.php` | Update the expected policy strings. |
| `apps/guardian-console/e2e/production-surface.spec.ts` | Update `POLICY_HEADERS`. Add assertions that a same-origin font loads with no `securitypolicyviolation`, and that a cross-origin font is blocked. |
| `scripts/tests/*`, `scripts/release/artifact.php` | Check for policy literals and adjust if any are present. |
| [`docs/adr/0026-…`](../adr/0026-production-browser-security-policy.md) | Amend the "no fonts, no `font-src`" clause **and** the build-shape clause that asserts "no images", with the reason: self-hosted interface type and one same-origin brand image, no third-party origin either way. |

The badge needs no CSP *directive*, because `img-src 'self'` is already served. What it does need is the ADR amendment above: ADR 0026 states the absence of images as a property of the build that someone could check, and that statement stops being true the moment the badge ships. The maintenance page (`maintenance.php`) stays font-free and image-free, because its rewrite intercepts assets. It should use the system fallback stack.

**None of this happens in WP0.** The policy is unchanged until WP1 changes it and its tests together.

## 11. Accessibility acceptance

- **Contrast:**
  - Body and metadata text at 4.5:1 or better.
  - Large titles and non-text UI (input borders, focus ring, rail pill, badge shapes) at 3:1 or better.
  - Both themes must pass. Add an automated check that computes contrast for the token pairs in `tokens.css`, next to the existing axe runs.
- **axe:** the existing `src/test/a11y.ts` and `e2e/accessibility.spec.ts` runs cover every page in **both** themes, with the drawer pinned and in overlay.
- **Keyboard:** e2e journeys for the account menu, the overlay drawer (open, move, choose, Escape and focus return) and the mobile sheet.
- **Targets:** at least 24px on desktop and 40px or more on coarse pointers. Rail buttons are 60 by 48.
- **Form controls on small screens:** at least 16px of text wherever the pointer is coarse or the viewport is narrow, so focusing a field never zooms the viewport and strands the operator (section 4.1). Proved by unit test on the primitives, and confirmed in a real mobile browser during the WP6 pass.
- **Reduced motion:** the drawer, menu and dialog have no transforms, and skeletons don't shimmer.

## 12. Implementation plan

**Seven reviewable work packages.** Each one is independently green — `./flow check` passes at the end of every package — and independently reviewable. How each is carried (branch, commit, or pull request) follows the repository's normal [Git workflow](../development/git-workflow.md); the package boundary is a review boundary, not a branching rule.

From WP3 on, the existing behaviour tests should pass **unchanged**. That is the proof that the visual work changed no behaviour.

### WP0 — Record the design architecture
- [ADR 0030](../adr/0030-guardian-console-visual-system.md), the durable architecture of the visual system.
- This specification and its token companion, finalized under `docs/design/`.
- The ADR index updated.
- **Docs only.** No application code, no dependencies, no policy change.
- **Done when:** the ADR and this spec agree, links resolve, and `./flow check repo` passes.

### WP1 — Tokens, fonts, global canvas, CSP/font foundation
- Copy the companion file to `src/theme/tokens.css` and add `fonts.css` (the `@font-face` rules); import both from `index.css`, which today imports Tailwind alone.
- Install the three `@fontsource-variable` packages. Confirm the exact package names and axes at this point.
- Make the `font-src 'self'` changes from section 10, and amend [ADR 0026](../adr/0026-production-browser-security-policy.md) in the same package, so the decision and the policy move together rather than the record drifting ahead of the header. The same amendment covers the badge, because ADR 0026's build-shape clause currently asserts "no fonts, no images" (section 13).
- `scripts/verify-build.mjs`: it asserts CSS content today; extend it to assert that token utilities (for example `.bg-surface`) and at least one hashed `.woff2` are emitted.
- **Done when:** the new global canvas is in place — the background, the atmospheric wash, the horizon line and the base typography now paint, because `tokens.css` defines them in `@layer base` — while existing page and component structures are otherwise intact and unmodified. Fonts load under the production policy, and every policy test passes.
- **Not claimed:** that the application looks unchanged. It will not. This package deliberately changes the ground and the type; what it does not change is any page's structure, markup or behaviour.

### WP2 — Theme and UI preferences
- `src/ui/preferences.ts`, `ThemeProvider`, `useTheme`, `useNavPreference`, and the pre-render stamp in `main.tsx`.
- Split the single `browser storage` guardrail rule (currently one pattern covering `localStorage`, `sessionStorage`, `indexedDB` and `openDatabase`) into the two rules in section 9. Add the unit tests and `expectOnlyUiPreferences`, and update the eight e2e `storageSizes` assertions.
- **Done when:** the theme can be forced by preference in tests, and storage holds only the one key with only the allowed fields.

### WP3 — Shared UI primitives
- Add `class-variance-authority`, `clsx` and `tailwind-merge`, with `cn()`.
- Build Button, Field, Input, Select, Checkbox, Badge, Panel, Alert, DataTable, Pagination, PropertyList, EmptyState, Skeleton, Page and PageHeader. Restyle Modal and ConfirmDialog. The control primitives consume `--text-control` (section 4.1).
- Optionally add a `components.json` so the shadcn CLI can add further primitives into `src/ui` as owned source. Each Radix-based addition must pass the CSP browser journey, because some inject `<style>` tags that `style-src 'self'` blocks.
- **Done when:** each primitive has unit and axe tests in both themes, and the form-control floor is proved by test. No page uses them yet.

### WP4 — Shell, navigation and account menu
- `src/shell/navigation.ts`, the rail, the drawer (pinned and overlay, with the responsive default from section 6), the top bar with breadcrumbs, the logo, the horizon line and wash, and the mobile navigation sheet.
- Add the badge asset and the `Flow Life Commons · Guardian Console` wordmark. Correct the `<title>` in `index.html`, which reads "Flow Life Guardian Console" today.
- `AccountMenu` (popover, roving focus, theme radios, absorbing `SignOutButton`). Account security leaves the primary navigation.
- Replace `ConsoleLayout`. `StepUpProvider` and `Outlet` placement are unchanged.
- **Done when:** the keyboard e2e journeys for the menu, drawer and sheet pass; the drawer default is proved at all three viewport bands; capability filtering is proved by tests; and existing page tests pass.

### WP5 — Page migration and refinement
- Accounts and Members lists (wide), account and member detail (detail grid, danger zone last), invite and add-member forms (form width), Account security, and Home renamed to Overview (session and API health only: no new figures or APIs).
- The sign-in, MFA, reset and invitation pages move to a restyled `AuthLayout`: a centred panel over the wash, with the logo and horizon line. `StatusScreen` and `ServiceUnavailable` are restyled too.
- **Done when:** all behaviour tests pass unchanged, and axe passes on every page in both themes.

### WP6 — Guardrails, accessibility, cleanup and visual QA
- Delete `classes.ts` and the duplicate badges and tables.
- Add a guardrail rule that bans raw palette utilities (`slate-`, `red-`, `emerald-`, `amber-` and so on) and arbitrary colour values in `src/pages` and `src/ui`, with the usual positive control.
- Add the token-contrast check. Do a visual QA pass at 375, 1024, 1199, 1280 and 1680px in both themes — the 1199 and 1280 pair is what confirms the pin breakpoint, and 375 is where the form-control floor is confirmed in a real mobile browser.
- Tune `--bp-pin` if QA shows it in the wrong place, and record the change here.
- **Done when:** no literal colours remain outside `src/theme`.

## 13. Reconciliation with the repository

Checked against the repository at the time of writing. Each item below is a place where the design meets something that does not yet exist, or that currently says otherwise.

| Item | State of the repository | Resolution |
|---|---|---|
| **Badge asset** | No image asset exists anywhere in `apps/guardian-console`, and there is no `public/` directory. The spec's Logo row describes the badge as though it were already shipped. | The badge is **added in WP4**, not assumed. `img-src 'self'` already permits it, so no CSP directive is needed — but see the next row. |
| **ADR 0026 build shape** | ADR 0026 asserts the build loads "no fonts, **no images**, no `data:` URIs" as a deliberate, checkable property, and separately explains why there is no `font-src` line. | Both clauses are broken by this design, not just the font one. The **WP1 amendment to ADR 0026 must cover the badge as well as the fonts**, with the reason: same-origin interface type and one same-origin brand image, no third-party origin either way. |
| **Product name in `index.html`** | `<title>` reads "Flow Life Guardian Console" — a third name, matching neither the product nor the descriptor. | Corrected in WP4 to "Flow Life Commons · Guardian Console". |
| **`index.css`** | Contains exactly one line, `@import 'tailwindcss'`. | WP1 adds the `fonts.css` and `tokens.css` imports and the `@theme inline` block in the documented order. |
| **Guardrail rule** | `src/guardrails.test.ts` has a single `browser storage` rule whose one pattern covers `localStorage`, `sessionStorage`, `indexedDB` and `openDatabase` together. | WP2 splits it in two, as section 9 sets out. The three non-`localStorage` APIs stay banned everywhere. |
| **`storageSizes` assertions** | Eight assertions across `console.spec.ts` (4), `mfa.spec.ts` (3) and `administration.spec.ts` (1), over a helper in `e2e/support.ts`. | WP2 moves all eight to `expectOnlyUiPreferences(page)`. The count in section 9 is confirmed. |
| **`verify-build.mjs`** | Asserts CSS content only; has no font or token assertions. | Extended in WP1. |
| **"Work Package"** | Already the repository's unit of reviewable work — [ADR 0028](../adr/0028-membership-grants-derived-at-query-time.md) refers to Work Packages 5 and 6 by that name. | Section 12 uses the same vocabulary. |

### Still open

- **Simplified mark.** If a mark without lettering exists or is commissioned, it replaces the badge in the rail and as the favicon, with the full badge kept for the sign-in pages. This is an asset swap at declared sizes and needs no architectural change, so it is not a blocker for any work package.
- **Font package names.** Confirm the exact `@fontsource-variable` package names and axis support (Newsreader's optical size in particular) when installing in WP1.
- **`--bp-pin` value.** 1200px is the starting value for the pin breakpoint. WP4 and WP6 may tune it; the principle it serves is fixed.
