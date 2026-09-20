import { expect, test, type Page } from '@playwright/test'

// The development gateway must present ONE origin whose paths are split between
// Laravel and the Vite-served Guardian Console, as in production (ADR 0016).
//
// Requests are made with same-origin fetch() from the loaded page, which is exactly
// how the Console will call the API. (Playwright's Node-side `request` fixture cannot
// resolve *.localhost; browsers can.)

interface Fetched {
  status: number
  contentType: string
  body: string
}

async function fetchFromPage(page: Page, path: string): Promise<Fetched> {
  return page.evaluate(async (target) => {
    const response = await fetch(target, { headers: { Accept: 'text/html' } })
    return {
      status: response.status,
      contentType: response.headers.get('content-type') ?? '',
      body: await response.text(),
    }
  }, path)
}

test.describe('same-origin gateway', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/')
  })

  test('routes /api to Laravel', async ({ page }) => {
    const result = await fetchFromPage(page, '/api/v1/health')

    expect(result.status).toBe(200)
    expect(result.contentType).toContain('application/json')
    expect(JSON.parse(result.body)).toMatchObject({ status: 'ok', api_version: 'v1' })
  })

  test('routes /up to Laravel', async ({ page }) => {
    const result = await fetchFromPage(page, '/up')

    expect(result.status).toBe(200)
    // Laravel's liveness page, not the Console shell.
    expect(result.body).not.toContain('id="root"')
  })

  for (const path of ['/api', '/api/nope', '/api/v1/nope']) {
    test(`answers unknown API path ${path} with JSON, never the Console`, async ({ page }) => {
      const result = await fetchFromPage(page, path)

      expect(result.status).toBe(404)
      expect(result.contentType).toContain('application/json')
      expect(result.body).not.toContain('id="root"')
    })
  }

  test('keeps client-side routing working', async ({ page }) => {
    // A deep link the Console has no server-side file for still boots the Console.
    await page.goto('/some/client/route')

    await expect(
      page.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' }),
    ).toBeVisible()
  })

  test('connects Vite HMR through the gateway', async ({ page }) => {
    const socket = page.waitForEvent('websocket', (ws) => ws.url().includes('token='))
    const connected = page.waitForEvent('console', (message) =>
      message.text().includes('[vite] connected'),
    )

    await page.reload()

    // The HMR websocket targets the gateway's origin, not the Vite container.
    expect(new URL((await socket).url()).host).toBe(new URL(page.url()).host)
    await connected
  })

  test('no longer serves the retired guardian. and api. hosts', async ({ request, baseURL }) => {
    // Node cannot resolve *.localhost, so talk to the gateway by address and
    // present the retired hostnames in the Host header.
    const port = new URL(baseURL ?? '').port

    for (const host of ['guardian', 'api']) {
      const response = await request.get(`http://127.0.0.1:${port}/`, {
        headers: { Host: `${host}.flowlife.localhost:${port}` },
      })
      expect(response.status(), host).toBe(404)
    }
  })
})
