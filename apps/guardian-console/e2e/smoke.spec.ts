import { expect, test } from '@playwright/test'

test('Guardian Console loads, styles apply, and the API is reachable same-origin', async ({
  page,
  baseURL,
}) => {
  const healthResponse = page.waitForResponse((r) => new URL(r.url()).pathname === '/api/v1/health')

  await page.goto('/')

  const heading = page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })
  await expect(heading).toBeVisible()

  // Tailwind compiled: `text-2xl` is 1.5rem = 24px (the browser default h1 is 32px).
  await expect(heading).toHaveCSS('font-size', '24px')

  // Proves gateway -> Vite -> browser -> Laravel -> MariaDB in one assertion.
  await expect(page.getByText('API ok')).toBeVisible()
  await expect(page.getByText('database: ok')).toBeVisible()

  // The API call went to the page's own origin, so it did not depend on CORS (ADR 0016).
  const response = await healthResponse
  expect(new URL(response.url()).origin).toBe(new URL(baseURL ?? '').origin)
})
