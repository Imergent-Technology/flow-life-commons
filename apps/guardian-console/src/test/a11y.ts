import axe from 'axe-core'
import { expect } from 'vitest'

/**
 * Runs axe-core over what is on screen and fails with the violations named. Colour contrast is left to a real browser (jsdom
 * has no layout or styles to judge it by); everything structural (names, labels, roles, ids, landmarks, tables) is checked.
 */
export async function expectNoAxeViolations(container: Element = document.body): Promise<void> {
  // The router's location probe is test scaffolding, not part of the Console.
  const results = await axe.run(
    { include: [container], exclude: [['[data-testid="location"]']] },
    { rules: { 'color-contrast': { enabled: false } } },
  )
  const summary = results.violations.map(
    (v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join('; ')})`,
  )
  expect(summary).toEqual([])
}
