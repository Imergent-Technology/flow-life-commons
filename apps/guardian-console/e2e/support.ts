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
