import { expect, test } from '@playwright/test'

test('Guardian Console loads, styles apply, and the API is reachable', async ({ page }) => {
  await page.goto('/')

  const heading = page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })
  await expect(heading).toBeVisible()

  // Tailwind compiled: `text-2xl` is 1.5rem = 24px (the browser default h1 is 32px).
  await expect(heading).toHaveCSS('font-size', '24px')

  // Proves gateway -> Vite -> browser -> CORS -> Laravel -> MariaDB in one assertion.
  await expect(page.getByText('API ok')).toBeVisible()
  await expect(page.getByText('database: ok')).toBeVisible()
})
