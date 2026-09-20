import { expect, test } from '@playwright/test'

// The lightest end-to-end check: the Console is served, its styles are compiled, and it talks to the API
// on its own origin. Nobody is signed in, so what loads is the login page (an unknown visitor is sent there).
// The signed-in landing page, and the health it shows (gateway -> Vite -> browser -> Laravel -> MariaDB),
// are checked in console.spec.ts.
test('Guardian Console loads, styles apply, and the API is reachable same-origin', async ({
  page,
  baseURL,
}) => {
  // On start the Console asks the platform who is signed in: the first API call, and it must be same-origin.
  const meResponse = page.waitForResponse((r) => new URL(r.url()).pathname === '/api/v1/me')

  await page.goto('/')

  const heading = page.getByRole('heading', { level: 1, name: 'Sign in' })
  await expect(heading).toBeVisible()

  // Tailwind compiled: `text-2xl` is 1.5rem = 24px (the browser default h1 is 32px).
  await expect(heading).toHaveCSS('font-size', '24px')

  // The API call went to the page's own origin, so it did not depend on CORS (ADR 0016).
  const response = await meResponse
  expect(new URL(response.url()).origin).toBe(new URL(baseURL ?? '').origin)
  expect(response.status()).toBe(401) // nobody is signed in
})
