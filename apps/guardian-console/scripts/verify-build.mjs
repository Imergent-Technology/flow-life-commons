// Post-build sanity check that Tailwind 4 actually ran (ADR 0012).
// A misconfigured plugin still "builds" but ships unprocessed CSS.
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

const assets = join(import.meta.dirname, '..', 'dist', 'assets')
const cssFiles = readdirSync(assets).filter((f) => f.endsWith('.css'))
if (cssFiles.length === 0) {
  throw new Error('verify:build: no CSS emitted in dist/assets')
}

const css = cssFiles.map((f) => readFileSync(join(assets, f), 'utf8')).join('\n')
// Utilities the Console really uses. Built at run time on purpose: Tailwind scans every project file,
// this one included, so a needle spelled out as a class name here would generate ITSELF and pass
// whether or not the app used it.
const needles = [
  ['text', '2xl'],
  ['font', 'semibold'],
  ['max', 'w', 'md'],
].map((parts) => `.${parts.join('-')}`)
for (const needle of needles) {
  if (!css.includes(needle)) {
    throw new Error(`verify:build: expected Tailwind utility ${needle} in built CSS`)
  }
}
if (css.includes('@import "tailwindcss"') || css.includes("@import 'tailwindcss'")) {
  throw new Error('verify:build: unprocessed @import "tailwindcss" found in built CSS')
}

// The visual system's token foundation (ADR 0030, docs/design/guardian-console-visual-system.md).
//
// A `bg-surface`-style needle would prove nothing yet, for the same tree-shaking reason the utilities
// above are chosen with care: `@theme inline` (the design's own choice, so a theme swap follows
// `data-theme` at runtime instead of baking in build-time values) only emits a role's CSS variable and
// utility once something actually renders it, and no page or primitive references any role token until
// WP3. What WP1 changes and can prove today is the base layer and the theme-independent scales, which
// are emitted regardless of usage because they are either hand-written CSS or already-consumed Tailwind
// keys:
//
// - `--radius-md` is redefined by the design from Tailwind's stock value to 9px, and `rounded-md` is
//   already used in the Console (TextField and others) — so this single assertion proves the token
//   system is live and actually overriding Tailwind, on markup nobody has touched yet.
// - The Garden and Deep Tide token sets, and the global canvas that consumes them, are hand-written CSS
//   in `@layer base` and always ship regardless of what utilities anything uses.
if (
  !css.includes('--radius-md:9px') ||
  !css.includes('.rounded-md{border-radius:var(--radius-md)}')
) {
  throw new Error(
    'verify:build: expected the design token radius scale to override rounded-md (ADR 0030)',
  )
}
if (!css.includes('--background:#f3f5f3') || !css.includes('--background:#0e1417')) {
  throw new Error('verify:build: expected both the Garden and Deep Tide --background values')
}
if (
  !css.includes('background-image:var(--wash)') ||
  !css.includes('font-family:var(--font-sans)')
) {
  throw new Error('verify:build: expected the base canvas (wash, base typography) in built CSS')
}
if (!css.includes('input,select,textarea{font-size:var(--text-control)}')) {
  throw new Error(
    'verify:build: expected the form-control floor rule (design spec §4.1) in built CSS',
  )
}

// Fonts: self-hosted, same-origin, wired to their roles (ADR 0030 §6; no CDN, no data: URI).
const woffFiles = readdirSync(assets).filter((f) => f.endsWith('.woff2'))
if (woffFiles.length === 0) {
  throw new Error('verify:build: expected at least one hashed .woff2 font asset in dist/assets')
}
for (const family of [
  'Newsreader Variable',
  'Hanken Grotesk Variable',
  'JetBrains Mono Variable',
]) {
  if (!css.includes(family)) {
    throw new Error(`verify:build: expected the '${family}' family wired into a font role`)
  }
}
// Vite base64-inlines any asset under its default 4KB threshold, and the smallest font subset files
// land under it: a real trap, caught once already (vite.config.ts sets assetsInlineLimit: 0). A font
// that arrives as a data: URI is not covered by font-src 'self' and no data: source is authorized.
if (css.includes('data:font')) {
  throw new Error(
    "verify:build: a font was inlined as a data: URI; font-src 'self' does not cover it (see vite.config.ts assetsInlineLimit)",
  )
}

console.log(
  `verify:build: OK (${String(cssFiles.length)} CSS file, ${String(woffFiles.length)} font files, Tailwind utilities and design tokens present)`,
)
