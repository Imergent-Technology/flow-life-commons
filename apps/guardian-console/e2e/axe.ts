import { readFileSync } from 'node:fs'

import type { Page } from '@playwright/test'

const AXE = readFileSync('node_modules/axe-core/axe.min.js', 'utf8')

interface AxeViolation {
  id: string
  help: string
  nodes: { target: string[] }[]
}

/** Runs axe over the current page, with colour contrast ON, and returns readable violations. */
export async function axeViolations(page: Page): Promise<string[]> {
  // Evaluated in the page, not added to it as a <script>: the production build is served under `script-src 'self'`, which (rightly)
  // refuses an inline script, and the policy is not loosened or bypassed for the audit, so the page is audited as it is shipped.
  await page.evaluate(AXE)
  const violations = await page.evaluate(async () => {
    const runner = (globalThis as unknown as { axe: { run: (o: unknown) => Promise<unknown> } }).axe
    const results = (await runner.run({
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    })) as { violations: AxeViolation[] }
    return results.violations.map(
      (v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join('; ')})`,
    )
  })
  return violations
}

export const THEMES = ['light', 'dark'] as const
export type Theme = (typeof THEMES)[number]

/** Seeds the one approved preference key before any page script runs, so the page boots in that theme. */
export async function inTheme(page: Page, theme: Theme): Promise<void> {
  await page.context().addInitScript((value) => {
    ;(
      globalThis as unknown as { localStorage: { setItem: (k: string, v: string) => void } }
    ).localStorage.setItem('flowlife.console.ui', JSON.stringify({ v: 1, theme: value }))
  }, theme)
}
