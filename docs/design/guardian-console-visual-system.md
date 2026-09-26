# Guardian Console visual system: specification and implementation plan

**Product:** Flow Life Commons · Guardian Console (`apps/guardian-console`, React 19 + Tailwind CSS 4)
**Status:** Accepted, 25 September 2026. This is the source of truth for the Console's mutable visual values.
**Decision record:** [ADR 0030](../adr/0030-guardian-console-visual-system.md) holds the durable architecture. This file holds everything the ADR deliberately leaves out.
**Tokens:** [`apps/guardian-console/src/theme/tokens.css`](../../apps/guardian-console/src/theme/tokens.css) is the one definition of every value in this document that a browser reads. There is no second copy: a token changes there, and the tables below follow it. `src/theme/contrast.test.ts` reads that file and judges the contrast of every pair the design relies on.

This specification records the agreed design **as it shipped** (the seven work packages of section 12 are complete). Colours, type sizes, radii, shadows and spacing can still move; when they do, they move in `tokens.css` and here, not in the ADR. A value changing in this file is a normal design change, not an architectural one.

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
| Logo | The Flow Life Sanctuary badge, to be added as a same-origin image file (already allowed by `img-src 'self'`). Sizes: 50px in the rail, 38px beside the wordmark in the navigation sheet, 40px in the mobile top bar, 156px on the credential and status pages (96px below 640px, so the form stays in view on a phone). Shipped as `src/assets/brand/FlowLife-Logo-320.png` (see section 13). |
| Fonts | Self-hosted: Newsreader (titles), Hanken Grotesk (interface), JetBrains Mono (identifiers). This needs `font-src 'self'`, and that is the only policy change (ADR 0026, amended). |
| Preferences | One narrowly scoped `localStorage` key, owned by a single UI-preferences module. It holds a schema version, the theme mode and the drawer choice, and nothing else. Syncing to the account can come later. |
| Violet | Confirmed. Hue 253°, in the middle of the logo's own range (about 235° to 283°). |

## 2. Principles

1. **Semantic before literal.** Components use role tokens (`surface`, `primary`, `danger`), never palette names, and never need a `dark:` variant. A guardrail enforces this.
2. **Width follows the task.** Pages declare a width (prose, form, detail, wide). The shell never caps everything, and an operational list is not capped at all.
   Its corollary for navigation: **pin only when enough workspace remains.** Chrome that permanently occupies a column has to earn it from the content beside it, so the drawer's default is a function of the viewport, never a fixed habit.
3. **Consequence is visible.** Destructive actions sit in a danger zone, are outlined in context, and are solid only inside the confirming dialog.
4. **Violet means "here" or "act".** It is used for the current place, the primary action, focus and selection. It is never used on surfaces.
5. **Atmosphere stays at the edges.** The wash and the top line live at the top of the frame. Content surfaces stay calm and solid.
6. **Never colour alone.** Every status has a word and a shape. Focus is a 2px ring with an offset in both themes.
7. **Density is a desktop affordance, not a default.** The compact 14px operational interface holds on desktop. Where the pointer is coarse or the viewport is narrow, the interface steps up rather than asking the operator to pinch — and for form controls that step-up is a correctness requirement, not a comfort one (section 4).
8. **Keep today's accessibility behaviour.** Heading focus on navigation, announced alerts, native `<dialog>` focus return, and Cancel first in confirmations are all preserved exactly.

## 3. Colour tokens

Names follow shadcn's conventions where one exists (`surface` is shadcn's `card`, `surface-raised` is `popover`), so its primitives drop in without renaming. All values are in `src/theme/tokens.css`.

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
| `input` | `#7C867F` | `#6C7F87` | Form control boundary (3:1 or better against the field and against every ground a control sits on) |
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
| `nav-rail` | `rgba(190,213,199,.60)` | `rgba(2,7,9,.85)` | The permanent rail: the deepest navigation plane, translucent over the wash (no blur). Garden tints it green; Deep Tide takes it nearly to black |
| `nav` | `rgba(255,255,255,.50)` | `rgba(3,10,12,.38)` | The pinned drawer: the secondary, lighter plane, translucent over the wash (no blur). The overlay drawer and the mobile sheet are opaque `surface-raised`, never the rail plane |
| `nav-foreground` | `#38433E` | `#C2D0D4` | Items |
| `nav-strong` | `#1D1A26` | `#F2EFF7` | Drawer title, current rail label |
| `nav-muted` | `#646F6A` | `#8598A0` | Group labels (12.5px text: 4.5:1 over the rail's real backing) |
| `nav-active` | `#E6DFFC` | `rgba(167,144,255,.16)` | Current item and rail pill |
| `nav-active-foreground` | `#3B22A0` | `#FFFFFF` | |
| `nav-active-icon` | `#6140D6` | `#B8A6FF` | |
| `nav-border` | `rgba(202,210,205,.85)` | `rgba(48,62,69,.75)` | Rail and drawer edges |
| `wash` | `#DAE9E1` → `#E5EEE9` (30%) → transparent, 340px tall | `#11313A` → `#16243A` (34%) → transparent, 340px tall | Top of the window only, behind rail and content |
| `horizon` | Meadow, 3px: `#8FC3A6` → `#D9C27E` (45%) → `#EDA283` (75%) → `#D98AA4` | Violet, 2px: transparent → `#A790FF` (28%–72%) → transparent | Window top edge. Decorative and `aria-hidden`. |

### How it sits in Tailwind v4

- `src/index.css` imports `tailwindcss`, then `./theme/fonts.css` (the `@font-face` rules) and `./theme/tokens.css`.
- **Garden** is defined in full on `:root`. **Deep Tide** is defined in full on `[data-theme=dark]`. A `prefers-color-scheme: dark` block (guarded by `:root:not([data-theme=light])`) repeats Deep Tide, so the right theme paints before any script runs.
- An `@theme inline` block maps each variable to a Tailwind colour, giving utilities such as `bg-surface`, `text-muted-foreground` and `border-input`.
- `@custom-variant dark` is keyed to `[data-theme=dark]`, but components should never need it.
- The default Tailwind palette stays available, and a guardrail (`src/guardrails.test.ts`) stops anything in `src` from using it: raw palette and black/white utilities, arbitrary colour values, `dark:` variants, inline colour styles, colour literals in source or in any stylesheet outside `src/theme`, and reading the theme anywhere but the theme machinery and the account menu. The single exception is the QR code, whose black-on-white plate is drawn into the image itself because scanners need it in every theme.

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

- **Packages:** `@fontsource-variable/newsreader`, `@fontsource-variable/hanken-grotesk` and `@fontsource-variable/jetbrains-mono` (the `wght` axis; none of the three publishes an optical-size axis), bundled by Vite into hashed `woff2` files served from `/assets`, never inlined (`assetsInlineLimit: 0`).
- **Subsets:** the packages' own CSS declares every script with a `unicode-range`, and a browser fetches a subset only when a character in its range is rendered, so the Latin text and diacritics (the macron in Hēnare) cost their subsets and nothing else.
- **Loading:** all three use `font-display: swap`, as Fontsource ships them. The planned `optional` with a size-adjusted fallback for Hanken Grotesk needs per-font metric overrides that were not measured; it remains a possible refinement, not a shipped behaviour.
- **Numbers:** `tabular-nums` on tables, property lists, dates and counts.
- **Serif discipline:** Newsreader appears only in page, dialog and drawer titles and in the wordmark. Never in controls, labels or tables.

### 4.1 The form-control floor

Mobile browsers — Safari on iOS most visibly — zoom the viewport when a control smaller than 16px takes focus, and they do not zoom back out. The operator is then stranded in a magnified layout mid-form. The fix is a **system rule, not a per-field exception**:

> Every text-accepting control (`input`, `select`, `textarea`, and any control the primitives build on them) renders at **no less than 16px** wherever the pointer is coarse or the viewport is narrow. Everywhere else it renders at the 14px body size.

- It is carried by **one token**, `--text-control`, which resolves to 14px by default and to 16px under `(max-width: 767.98px), (pointer: coarse)`. The token is defined once in `tokens.css`; `Input`, `Select`, `Textarea` and `TotpCodeField` consume it and never set a size of their own.
- Because it is a token and not a utility, a new control inherits the floor by construction. A control that hard-codes `text-body` or `text-[14px]` is the bug, and `src/ui/formControlFloor.test.ts` is what catches it in the control primitives, with the guardrail against literal colours and sizes beside it.
- The desktop interface is unaffected: 14px controls beside 13px labels remain the operational density.
- The same breakpoints already step control **heights** up one size on coarse pointers (section 5), so the floor and the target sizes move together rather than fighting each other.

**Acceptance:** a unit test asserts the computed `font-size` of each control primitive is at least 16px under a coarse-pointer/narrow media context, and 14px otherwise; `e2e/layout.spec.ts` confirms 16px fields on an emulated touch screen and 14px on a desktop pointer.

## 5. Shape, space, elevation, motion

- **Radius:**
  - `radius-sm` 7px for controls and nav items.
  - `radius-md` 9px for menus, wells and alerts.
  - `radius-lg` 14px for panels, dialogs and the drawer.
  - `radius-pill` for badges, the rail pill and the avatar.
- **Control heights:** `control-sm` 28, `control-md` 34 (default), `control-lg` 40 (sign-in pages). On a coarse pointer they become 40, 40 and 44, so every control is at least 40px there (section 11), and everything in a row shares a height.
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
  - `prose` 42rem and `form` 36rem.
  - `wide` has **no maximum**: it is the operational-list width (Accounts, Members), and takes the whole width the shell leaves it, inside the shell's own gutters. It was a 92rem cap until the manual review found that on a wide display it left a large unused area beside the table; nothing else used it, so it was redefined rather than joined by a second full-width mode.
  - `detail` 74rem, a two-column grid with a 20rem aside once the page itself is 56rem wide (a container query: `detail` is capped below 1200px, so the window's width is the wrong measure). Below that the aside stacks first. A `PropertyList` stacks term over value inside anything narrower than 20rem, so an email address is never broken mid-word in the aside.
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
type NavItem    = { label: string; to: string; capability?: Capability; detail?: { pattern: string; label: string } }
type NavGroup   = { label: string; items: NavItem[] }
type NavSection = { id: string; label: string; drawerTitle?: string; icon: IconName; to?: string; groups?: NavGroup[] }

export const sections: NavSection[] = [
  { id: 'overview', label: 'Overview', icon: 'home', to: '/' },
  { id: 'admin', label: 'Admin', icon: 'people', groups: [
    { label: 'Accounts', items: [
      { label: 'All accounts',       to: '/admin/accounts',        capability: ACCOUNTS_VIEW,
        detail: { pattern: '/admin/accounts/:id', label: 'Account' } },
      { label: 'Invite an operator', to: '/admin/accounts/invite', capability: INVITATIONS_ISSUE } ] },
    { label: 'Members', items: [
      { label: 'All members',  to: '/admin/members',     capability: MEMBERSHIP_VIEW,
        detail: { pattern: '/admin/members/:personId', label: 'Member' } },
      { label: 'Add a member', to: '/admin/members/new', capability: MEMBERSHIP_MANAGE } ] } ] },
]
```

- **Capabilities:** items are filtered by `hasCapability` as today. A group with no visible items is dropped, and so is a section with no visible groups. The drawer title reads "Administration" and the rail label "Admin".
- **Current state:**
  - The item for the current route gets `aria-current="page"`. Beneath it, on one of its detail pages, the item gets `aria-current="true"` instead: the breadcrumb's last crumb is the one current page, and the navigation only says where it belongs.
  - An item is current on an exact match of its own `to`; a page beneath it (its `detail` pattern, such as `/admin/accounts/:id`) keeps it current. Exact pages win over detail patterns, so `/invite` and `/new` are never mistaken for a detail page. Matching uses the router's `matchPath`, and the same model yields the breadcrumbs (a group's first page is its list and has none; a detail page is named by what it has loaded).
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

- **The wide breakpoint is tunable.** 1200px is the starting value. Browser QA did not move it (section 13). CSS cannot interpolate a variable into a media query, so the value lives in two places that are kept in step by hand: the shell's `PIN_QUERY` in `src/shell/viewport.ts`, which is what the layout follows, and `--bp-pin` in `tokens.css`, which records it. If it ever moves, that is a change to this specification, to those two lines, and to nothing else. What must not change is the principle above.
- **The preference overrides the default, and only when set.** `nav` is **absent** from stored preferences until the operator pins or unpins the drawer themselves. While absent, the breakpoint decides. Once set, the choice is honoured at every desktop width — an operator who unpins at 1440px stays unpinned.
- **Below 1024px the preference is not consulted at all**, and is not cleared either: it is a desktop concept, and the operator gets it back when they return to a desktop width.
- **Crossing a breakpoint never silently rewrites the preference.** The layout re-derives; storage is written only by an explicit pin or unpin.

### Drawer behaviour

| Mode | Layout | Opens and closes | Focus |
|---|---|---|---|
| Pinned (default at ≥1200px) | A 236px grid column between the rail and the content, translucent over the wash. It pushes the content. | Always open for sections with groups. The header button unpins it (label "Unpin navigation panel"). | A normal part of the tab order: rail, then drawer, then top bar, then page. |
| Overlay (default at 1024–1199px) | 256px on `surface-raised` with `shadow-pop`, over the content, non-modal and with no scrim. | Toggled by the section's rail button (`aria-expanded`, `aria-controls`). Closes on choosing a page, Escape, a click outside, or focus leaving it by the keyboard. The header offers a pin button and a close button. | It sits in the document right after the rail, so the tab order is rail, drawer, top bar, page. Opening moves focus to the current item, or the first. Escape returns focus to the rail button. Tab past its last control, or Shift+Tab back to the rail, closes it and the element that took focus keeps it: it is non-modal, so nothing is trapped and nothing is sent back. Choosing a page hands focus to the page heading (current behaviour). |

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
  - Breadcrumbs sit on the left for detail and form pages ("Accounts / Tomás Okafor"), replacing today's "← Accounts" links. Below 1024px the same trail sits in a compact row under the top bar. Both come from the navigation model, so a page appears in one only where the model gives it a trail (not Overview, a list, or Account security).
  - The account trigger sits on the right.
- **Page header:** an `h1` in the display face, with an optional status badge, a one-line description, and the page's single primary action on the right.
- **Sign-in and invitation pages:** a centred 400px column over the wash with the horizon line. The logo sits above an `h1` "Sign in" (Newsreader, 32px), followed by a panel with the form and a full-width 40px primary button.

## 7. Account menu

- **Trigger:**
  - A 36px pill with an initials avatar, the display name and a chevron. Mobile shows the avatar only, with `aria-label="Account menu"`.
  - It carries `aria-haspopup="menu"`, `aria-expanded` and `aria-controls`.
- **Contents, in order:**
  1. A header with name and email. The first assignment name is omitted: `/me` does not carry assignments, and the menu makes no request of its own. It can be added when `/me` does.
  2. Account security.
  3. Theme: a Light, Dark, System segmented group of `menuitemradio`.
  4. Sign out.
- **Keys:**
  - Enter, Space or ↓ opens the menu on the first item, and ↑ opens it on the last.
  - ↑↓ move, Home and End jump, and ←→ move within Theme.
  - Escape closes and refocuses the trigger. Tab closes and moves on. A click outside closes without taking focus.
- **Theme choice:** applies at once, saves the preference, and keeps the menu open.
- **Sign out:** absorbs `SignOutButton`. While pending it reads "Signing out…" and is disabled. On failure the menu closes and today's error alert appears at the top of `main`, so the "does not pretend to be signed out" behaviour is kept.
- **Build:** a disclosure menu on plain elements: an always-mounted popup positioned by CSS under the trigger, a small roving-focus handler, and its own outside-press dismissal. It does not use the `popover` attribute, which needs anchor positioning or a positioning script to sit under the trigger, has no implementation in the jsdom test environment, and would add a second dismissal path to keep in step with the drawer and sheet. It passes the production-CSP browser journey (`e2e/shell.spec.ts`).

## 8. Components

The primitives use `cva` variants and a `cn()` helper (`clsx` plus `tailwind-merge`). They follow shadcn conventions, with the source owned in `src/ui`.

| Primitive | Variants and rules |
|---|---|
| `Button` | `primary · secondary · ghost · danger (outline) · danger-solid` × `sm · md · lg`. Pending state keeps its width and swaps the label. One primary per view. `danger-solid` is used only in confirmations. `buttonVariants` gives a router link the same look. `SubmitButton` is the form's primary submit with its label swap. |
| `Field`, `Input`, `Select`, `Checkbox` | Label, then hint, then control, then error. Errors are tied by `aria-describedby` with an icon plus text. `aria-invalid` gives a danger border and a 1px ring. `TextField` is a `Field` around an `Input` with the credential attributes fixed. |
| `Badge` | `success · warning · neutral · danger · accent`. Shape carries meaning without colour: a filled dot means live, a diamond means waiting, a hollow ring means off. `StatusBadge` and `MembershipStateBadge` only choose the words and the variant. |
| `Panel` | Header (title, description, actions) and body. A `danger` tone tints the border and ground, and the danger-zone panel always comes last. |
| `Alert` | `error · success · warning · info`, icon plus text. The `alert`/`status` roles and `focusOnMount` are exact. |
| `Modal`, `ConfirmDialog` | The native `<dialog>` and its focus logic, with a display-face title and a `scrim` backdrop, Cancel first and focused. |
| `DataTable`, `Pagination` | Tinted header pinned to the top of the window from 768px, 42px rows, name cell as the link, Previous/Next with the position, and labelled stacked records below 768px (each cell's required `label` is shown from `data-label`; a long value such as an email address stacks its label above it instead of beside it, so it has the record's whole width). |
| `PropertyList` | A 130px term column beside tabular values once the list is 20rem wide, term over value below that. Values wrap anywhere, so there is no `break-all`. |
| `EmptyState`, `Skeleton` | The empty state names what is empty and may offer one next action. Skeleton rows match the table's shape, beside the `role="status"` text. |
| `Page`, `DetailLayout`, `PageHeader` | `Page` takes a `width` (prose, form, detail, wide; `wide` is uncapped); `DetailLayout` is the detail grid and its aside; `PageHeader` is the h1 (display face), status, description and primary action. Title and focus behaviour is shared with `AuthLayout` through `usePageHeading`. |
| `AuthLayout` | The 400px column for every page outside the shell: badge, wordmark, h1, then everything the page says or asks inside one surface. |
| `TextLink` | An in-text link: the action colour, underlined. |
| `QrCode` | Black modules on a white plate drawn into the SVG itself, in every theme. |

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
- **Hooks:** `useTheme()` exposes the preference, the resolved theme and a setter. `useNavPreference()` does the same for the drawer's stored choice, and nothing more: it never looks at the viewport. Resolving that choice against the breakpoints is `useDrawerMode()` (`src/shell/drawer-mode.ts`, over `useViewportBand()` in `src/shell/viewport.ts`), which reports the resolved mode (`sheet`, `pinned` or `overlay`) and whether it came from the operator or the viewport.

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

**All of it landed together in WP1**, with its tests, so the policy and its record never disagreed.

## 11. Accessibility acceptance

- **Contrast:**
  - Body and metadata text at 4.5:1 or better.
  - Large titles and non-text UI (input borders, focus ring, rail pill, badge shapes) at 3:1 or better.
  - Both themes must pass. `src/theme/contrast.test.ts` computes it from `tokens.css` itself, for every text and non-text pair the interface uses (over 200 measurements). Translucent tokens are composited over what is really behind them, and the navigation (the rail plane and the drawer plane alike), which sits over the ground and the top of the wash, is measured over each. A further check holds the rail visibly, but quietly, deeper than the drawer in both themes.
  - The rail's **pill fill** is a soft cue, not the identifier: it is about 1.3:1 with the rail by design. What identifies the current place is the icon and label colour (at least 5:1) with `aria-current`, and, in the drawer, a solid bar.
- **axe:** `src/test/a11y.ts` (structure, in jsdom, over every route) and `e2e/accessibility.spec.ts` (a real browser, contrast enabled) cover every page and full-page screen in **both** themes, and the shell in each state: pinned drawer, overlay drawer open, account menu open, mobile sheet open.
- **Keyboard:** e2e journeys for the account menu, the overlay drawer (open, move, choose, Escape and focus return) and the mobile sheet.
- **Targets:** at least 24px on desktop and 40px or more on coarse pointers (proved for every button, field and select in `e2e/layout.spec.ts`). Rail buttons are 60 by 48.
- **Form controls on small screens:** at least 16px of text wherever the pointer is coarse or the viewport is narrow, so focusing a field never zooms the viewport and strands the operator (section 4.1). Proved by unit test on the primitives, and confirmed in a real touch-emulated browser (`e2e/layout.spec.ts`).
- **Reduced motion:** the drawer, sheet and dialogs do not animate, transitions are 0ms and skeletons do not pulse. Proved in a real browser, with the same page under no preference as the control that proves the test can fail (`e2e/layout.spec.ts`).

## 12. Delivery

The visual foundation was delivered as seven reviewable work packages on `feat/guardian-console-visual-foundation`, each independently green.

| Package | Commit | What it delivered |
|---|---|---|
| WP0 | `a6d91f0` | ADR 0030 and this specification. |
| WP1 | `0644b83` | Tokens, self-hosted fonts, the global canvas (ground, wash, horizon, base type), and `font-src 'self'` with ADR 0026 amended and every policy test moved with it. |
| WP2 | `bd031b0` | The one preferences module, `ThemeProvider`, the pre-render stamp in `main.tsx`, and the storage guardrail split. |
| WP3 | `e6ca69d` | The primitives of section 8, with unit and axe tests in both themes and the form-control floor. |
| WP4 | `5a04a74`, `0db821e` | The shell: navigation model, rail, pinned and overlay drawers, top bar and breadcrumbs, mobile sheet, account menu, the badge asset and wordmark. |
| WP5 | `ce2b05b` | Every page and credential screen on the primitives; the responsive table; the detail layout; `AuthLayout`; Home renamed Overview; page titles `<Page> · Flow Life Commons`. |
| WP6 | `b929f2f` | The permanent guardrails, the contrast check, the structural check on the pre-render stamp, the final accessibility, width and motion suites, and the removal of the transitional helpers (`classes.ts`, `PageHeading`). |

Settled while building, and now part of the design:

- **Tables.** Below 768px a table is stacked, labelled records; from 768px it is a table that never scrolls sideways (cells wrap or truncate), so its header can be pinned with `position: sticky` under `overflow: clip`. A long value (the address) in a stacked record has its label above it, not beside it, so it is not left with a line for its last character.
- **Titles.** `<Page> · Flow Life Commons`; the document's `index.html` title is "Flow Life Commons · Guardian Console". "Flow Life Guardian Console" is a guardrail failure.
- **Auth frame.** The h1 uses the standard `text-title` (31px) rather than a bespoke 32px, and the badge is 96px below 640px.
- **Account menu.** A disclosure menu on plain elements, not the `popover` attribute (section 7).
- **Manual acceptance refinement** (`fix(console): refine final visual foundation`): `wide` redefined as uncapped (`--container-page-wide` removed), and the rail given its own `nav-rail` surface so it is the deepest navigation plane over the pinned drawer's `nav`, as in the approved mockup. Both are mutable token/component refinements; ADR 0030 is unchanged.
- **Final audit remediation** (`fix(console): close visual foundation audit findings`): the overlay drawer follows the rail in the document and closes when keyboard focus leaves it; a detail page's parent item is `aria-current="true"`, not a second current page; the mobile shell shows the breadcrumb trail; long values in stacked records have the whole record's width; the cross-origin font probe asserts the browser's `font-src` violation report.
- **Token corrections found by the contrast check** (WP6): `input` in Garden `#8C968F` → `#7C867F`; `nav-muted` in Garden `#7E8984` → `#646F6A` and in Deep Tide `#6F848B` → `#8598A0`; `--control-sm` on a coarse pointer 34px → 40px.

## 13. Reconciliation with the repository

| Item | State |
|---|---|
| **Badge asset** | `src/assets/brand/FlowLife-Logo-320.png` (320px, resampled from the supplied 1022px `FlowLife-Logo.png`, which stays as the source) is imported through `src/shell/brand.ts`, so Vite emits it as a hashed same-origin file, never a `data:` URI. The largest use is 156px, so 320px covers a 2× display. `img-src 'self'` already permitted it. `FlowLife-Logo-WithAddress.png` sits beside it for customer-facing use and is not imported. |
| **ADR 0026 build shape** | Amended (2026-09-25, and again for the shipped badge): the build carries same-origin hashed fonts and one same-origin hashed image, no third-party origin, no `data:`. |
| **Product name** | The wordmark is "Flow Life Commons" over "Guardian Console"; `index.html` reads "Flow Life Commons · Guardian Console". |
| **Guardrails** | `src/guardrails.test.ts` bans raw palette and literal colours, `dark:` variants, and reading the theme (section 3); `localStorage` is legal in exactly `ui/preferences.ts`, and `sessionStorage`, `indexedDB` and `openDatabase` stay banned everywhere (section 9). |
| **Storage assertions** | The e2e storage assertions go through `expectOnlyUiPreferences(page)` (section 9). |
| **`verify-build.mjs`** | Asserts CSS content, design tokens, hashed fonts and the hashed image. |
| **`--bp-pin`** | 1200px, unchanged by QA: at 1199px the drawer defaults to overlay and at 1200px to pinned (`e2e/layout.spec.ts`). |

### Still open

- **Simplified mark.** If a mark without lettering exists or is commissioned, it replaces the badge in the rail and as the favicon, with the full badge kept for the credential pages. It is an asset swap at declared sizes and needs no architectural change.
- **Hanken Grotesk `optional` loading** with a measured size-adjusted fallback (section 4).
- **Screen-reader verification of the stacked table.** Below 768px the table's elements are given `display: block`, which some screen readers (Safari with VoiceOver in particular) treat as dropping the table's semantics. The semantic and axe checks pass, but they cannot show this; it needs a pass with real assistive technology before it is relied on.
