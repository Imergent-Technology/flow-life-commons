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
for (const needle of ['.text-2xl', '.font-semibold', '.max-w-2xl']) {
  if (!css.includes(needle)) {
    throw new Error(`verify:build: expected Tailwind utility ${needle} in built CSS`)
  }
}
if (css.includes('@import "tailwindcss"') || css.includes("@import 'tailwindcss'")) {
  throw new Error('verify:build: unprocessed @import "tailwindcss" found in built CSS')
}
console.log(`verify:build: OK (${String(cssFiles.length)} CSS file, Tailwind utilities present)`)
