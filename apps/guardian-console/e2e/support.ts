import { createHmac } from 'node:crypto'

import { expect, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test'

// Helpers shared by the browser journeys. Nothing here is a test.

export const SESSION_COOKIE = '__Host-flowlife-session'
export const HOST = 'commons.flowlife.localhost'

/** A fresh, unique passphrase for each step. */
export const unique = (label: string): string => `e2e ${label} passphrase ${crypto.randomUUID()}`

export const sessionCookie = async (context: BrowserContext) =>
  (await context.cookies()).find((c) => c.name === SESSION_COOKIE)

/** What page scripts can read: `document.cookie` excludes HttpOnly cookies. */
export async function scriptVisibleCookies(page: Page): Promise<string> {
  return page.evaluate(
    () => (globalThis as unknown as { document: { cookie: string } }).document.cookie,
  )
}

/** GET /me from the page, exactly as the Console makes it (same origin, the browser's own cookies). */
export async function meStatus(page: Page): Promise<number> {
  return page.evaluate(async () => (await fetch('/api/v1/me')).status)
}

/** The address the page is at, as script sees it, and how many history entries the tab has. */
export async function locationOf(
  page: Page,
): Promise<{ href: string; hash: string; historyLength: number }> {
  return page.evaluate(() => {
    const g = globalThis as unknown as {
      location: { href: string; hash: string }
      history: { length: number }
    }
    return { href: g.location.href, hash: g.location.hash, historyLength: g.history.length }
  })
}

/** What browser storage holds: the Console must never put anything there. */
export async function storageSizes(page: Page): Promise<{ local: number; session: number }> {
  return page.evaluate(() => ({
    local: (globalThis as unknown as { localStorage: { length: number } }).localStorage.length,
    session: (globalThis as unknown as { sessionStorage: { length: number } }).sessionStorage
      .length,
  }))
}

/** Collects what the page writes to the browser console, so a journey can prove no secret was logged. */
export function captureConsole(page: Page): string[] {
  const lines: string[] = []
  page.on('console', (message) => lines.push(message.text()))
  page.on('pageerror', (error) => lines.push(error.message))
  return lines
}

/**
 * What `GET /me` answers to someone who presents ONLY this session cookie value, as a thief with a
 * copy would. The cookie's text is re-encrypted with a fresh random IV on every response, so comparing
 * cookie strings cannot tell whether the session id underneath changed; replaying an old value can: it
 * authenticates exactly as long as that id is alive. (Chromium refuses to have a Secure __Host- cookie
 * injected over plain HTTP, and a page may not set a Cookie header, so this goes through the Node HTTP
 * client, addressing the gateway and naming the host.)
 */
export async function replayedStatus(
  request: APIRequestContext,
  baseURL: string,
  value: string,
): Promise<number> {
  const port = new URL(baseURL).port
  const response = await request.get(`http://127.0.0.1:${port}/api/v1/me`, {
    headers: {
      Host: `${HOST}:${port}`,
      Accept: 'application/json',
      Cookie: `${SESSION_COOKIE}=${value}`,
    },
  })
  return response.status()
}

/** Mailpit is on its own host; Node cannot resolve *.localhost, so address the gateway and name the host. */
export async function mailpit(request: APIRequestContext, baseURL: string, path: string) {
  const port = new URL(baseURL).port
  return request.get(`http://127.0.0.1:${port}${path}`, {
    headers: { Host: `mail.flowlife.localhost:${port}` },
  })
}

interface MailList {
  messages: { ID: string }[]
}

export async function messageIdsTo(
  request: APIRequestContext,
  baseURL: string,
  email: string,
): Promise<string[]> {
  const response = await mailpit(
    request,
    baseURL,
    `/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`,
  )
  expect(response.ok()).toBe(true)
  return ((await response.json()) as MailList).messages.map((m) => m.ID)
}

// --- a second factor, as an authenticator app would compute it (RFC 6238) --------------------------------

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

function decodeBase32(secret: string): Buffer {
  let bits = ''
  for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
    bits += BASE32.indexOf(char).toString(2).padStart(5, '0')
  }
  const bytes: number[] = []
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2))
  return Buffer.from(bytes)
}

/** The 6-digit code for one 30-second step: HMAC-SHA1 with dynamic truncation, as RFC 4226 and 6238 say. */
export function totpForStep(secret: string, step: number): string {
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(step))
  const hash = createHmac('sha1', decodeBase32(secret)).update(counter).digest()
  const offset = (hash[19] ?? 0) & 0x0f
  const binary =
    (((hash[offset] ?? 0) & 0x7f) << 24) |
    ((hash[offset + 1] ?? 0) << 16) |
    ((hash[offset + 2] ?? 0) << 8) |
    (hash[offset + 3] ?? 0)
  return String(binary % 1_000_000).padStart(6, '0')
}

const lastStep = new Map<string, number>()

/**
 * A valid code for `secret` that has not been used before by this process. The platform accepts each time step
 * once and only the current step and one either side, so a second sign-in within the same 30 seconds uses the
 * NEXT step, and a third waits until that is in range. Give each journey its own secret and this is quick.
 */
export async function nextCode(secret: string): Promise<string> {
  const current = Math.floor(Date.now() / 30_000)
  const step = Math.max(current, (lastStep.get(secret) ?? -1) + 1)
  if (step > current + 1) {
    await new Promise((resolve) => setTimeout(resolve, (step - 1) * 30_000 - Date.now() + 500))
  }
  lastStep.set(secret, step)
  return totpForStep(secret, step)
}

/** The recovery codes the development seeder gives a fixture (E2eAccountSeeder::recoveryCodes). */
export function recoveryCodesFor(tag: string): string[] {
  return Array.from({ length: 10 }, (_, n) => `E2E${tag}-RC00-0000-000${String(n)}`)
}

/** A same-origin fetch from the page, exactly as the Console makes one (the XSRF-TOKEN cookie echoed in a header). */
export async function apiFrom(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ status: number; body: unknown }> {
  const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')
  const token = xsrf === undefined ? undefined : decodeURIComponent(xsrf.value)
  return page.evaluate(
    async ({ method, path, body, token }) => {
      const headers: Record<string, string> = { Accept: 'application/json' }
      if (body !== undefined) headers['Content-Type'] = 'application/json'
      if (token !== undefined) headers['X-XSRF-TOKEN'] = token
      const response = await fetch(path, {
        method,
        headers,
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      })
      const text = await response.text()
      return { status: response.status, body: text === '' ? null : (JSON.parse(text) as unknown) }
    },
    { method, path, body, token },
  )
}
