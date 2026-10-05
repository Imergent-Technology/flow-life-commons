// Post-build sanity check that Tailwind 4 actually ran (ADR 0012).
// A misconfigured plugin still "builds" but ships unprocessed CSS.
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

// `VERIFY_BUILD_DIST` points the check at another build (a mutation test builds one on purpose); the default is the real one.
const dist = process.env.VERIFY_BUILD_DIST ?? join(import.meta.dirname, '..', 'dist')
const assets = join(dist, 'assets')
const cssFiles = readdirSync(assets).filter((f) => f.endsWith('.css'))
if (cssFiles.length === 0) {
  throw new Error('verify:build: no CSS emitted in dist/assets')
}

const css = cssFiles.map((f) => readFileSync(join(assets, f), 'utf8')).join('\n')
// Utilities the Console really uses. Built at run time on purpose: Tailwind scans every project file,
// this one included, so a needle spelled out as a class name here would generate ITSELF and pass
// whether or not the app used it.
const needles = [
  ['text', 'section'],
  ['font', 'semibold'],
  ['max', 'w', 'page-form'],
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
// `@theme inline` emits a role's utility only once something in the source uses it, so the role utilities
// the primitives are written in are asserted below (needles built at run time, for the reason given above),
// beside the parts that are hand-written CSS and always ship:
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

// The semantic role utilities the shared primitives are written in. If Tailwind stops resolving the
// `@theme inline` mapping, the primitives would still build and render, unstyled.
const roleUtilities = [
  ['bg', 'surface'],
  ['bg', 'primary'],
  ['bg', 'danger-soft'],
  ['text', 'muted-foreground'],
  ['border', 'input'],
  ['border', 'border-strong'],
  ['max', 'w', 'page-detail'],
  ['bg', 'nav-rail'],
  ['font', 'display'],
].map((parts) => `.${parts.join('-')}`)
for (const needle of roleUtilities) {
  if (!css.includes(needle)) {
    throw new Error(`verify:build: expected the semantic utility ${needle} in built CSS (ADR 0030)`)
  }
}
if (
  !css.includes('--color-surface:var(--surface)') &&
  !css.includes('background-color:var(--surface)')
) {
  throw new Error('verify:build: expected role utilities to resolve through the theme variables')
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

// The brand image (ADR 0030 S6; ADR 0026 amended for it): one same-origin file under img-src 'self'. Like a
// font, it must not become a data: URI, which that directive does not cover and no source authorizes.
const images = readdirSync(assets).filter((f) => /\.(png|svg|webp|jpe?g)$/.test(f))
if (images.length === 0) {
  throw new Error('verify:build: expected the hashed brand image in dist/assets')
}
// The formats Vite inlines for an imported image. (The `qr` library carries a GIF data-URI helper the
// Console never calls, its code renders SVG, so a bare `data:image` match would be a false alarm.)
const inlinedImage = /data:image\/(?:png|svg\+xml|webp|jpe?g|avif)/
const scripts = readdirSync(assets)
  .filter((f) => f.endsWith('.js'))
  .map((f) => readFileSync(join(assets, f), 'utf8'))
  .join('\n')
if (inlinedImage.test(css) || inlinedImage.test(scripts)) {
  throw new Error(
    "verify:build: an image was inlined as a data: URI; img-src 'self' does not cover it (see vite.config.ts assetsInlineLimit)",
  )
}
if (!images.some((file) => scripts.includes(file))) {
  throw new Error('verify:build: the brand image is emitted but nothing in the build refers to it')
}

// The rich-text editor is not in the Console's initial load (ADR 0037, decision 28; WP2's carry-forward, WP4). Tiptap and ProseMirror
// are about 225 kB gzipped, and almost no page edits rich text, so Vite must put the editor in a chunk of its own that the entry
// reaches only through `import()`. "The initial load" is what index.html itself loads: its module scripts and any modulepreload it
// lists. None of those may carry editor code; at least one OTHER chunk must (or the check would pass on a build that dropped the
// editor, or on markers that no longer exist); and index.html must not name that chunk at all.
const html = readFileSync(join(dist, 'index.html'), 'utf8')
const initial = new Set(
  [...html.matchAll(/<(?:script|link)\b[^>]*\b(?:src|href)="\/assets\/([^"]+\.js)"/g)].map(
    (match) => match[1],
  ),
)
if (initial.size === 0) {
  throw new Error(
    'verify:build: index.html names no entry script, so the initial load cannot be checked',
  )
}
const editorMarkers = /ProseMirror|prosemirror|tiptap/
const jsFiles = readdirSync(assets).filter((f) => f.endsWith('.js'))
const carriers = jsFiles.filter((f) => editorMarkers.test(readFileSync(join(assets, f), 'utf8')))
if (carriers.length === 0) {
  throw new Error(
    'verify:build: no chunk carries the rich-text editor; the lazy-load check would prove nothing',
  )
}
const eager = carriers.filter((f) => initial.has(f))
if (eager.length > 0) {
  throw new Error(
    `verify:build: the rich-text editor is in the initial load (${eager.join(', ')}). It must be reached only through import() (see richtext/LazyRichTextEditor.tsx)`,
  )
}
const named = carriers.filter((f) => html.includes(f))
if (named.length > 0) {
  throw new Error(`verify:build: index.html itself loads the editor chunk (${named.join(', ')})`)
}

console.log(
  `verify:build: OK (${String(cssFiles.length)} CSS file, ${String(woffFiles.length)} font files, ${String(images.length)} image, Tailwind utilities and design tokens present)`,
)
console.log(
  `verify:build: OK (the rich-text editor is split into ${carriers.join(', ')} and kept out of the initial load: ${[...initial].join(', ')})`,
)
