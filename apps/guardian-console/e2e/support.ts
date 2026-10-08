import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'

import {
  expect,
  type APIRequestContext,
  type Browser,
  type BrowserContext,
  type Page,
} from '@playwright/test'

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

/**
 * What browser storage holds. `sessionStorage` must be empty, always: the Console never puts anything
 * there. `localStorage` may hold at most the one UI-preferences key (ADR 0030), and if it exists its
 * shape is checked exactly — this is a security assertion, not a count. A malicious or accidental
 * extra field such as `token`, `email`, `secret` or `account_id` fails it even though storage still
 * holds only the one key, because the check is on the parsed object's fields, not on how many keys
 * exist.
 */
export async function expectOnlyUiPreferences(page: Page): Promise<void> {
  const result = await page.evaluate(() => {
    const g = globalThis as unknown as { localStorage: Storage; sessionStorage: Storage }
    return {
      localKeys: Object.keys(g.localStorage),
      sessionLength: g.sessionStorage.length,
      raw: g.localStorage.getItem('flowlife.console.ui'),
    }
  })

  expect(result.sessionLength, 'sessionStorage must be empty').toBe(0)
  expect(
    result.localKeys.length,
    `localStorage must hold at most the UI-preferences key; found ${result.localKeys.join(', ')}`,
  ).toBeLessThanOrEqual(1)
  if (result.localKeys.length === 1) {
    expect(result.localKeys[0], 'the one key localStorage may hold').toBe('flowlife.console.ui')
  }

  const raw = result.raw
  if (raw === null) return

  let parsed: unknown
  expect(() => {
    parsed = JSON.parse(raw)
  }, 'the UI-preferences key must hold valid JSON').not.toThrow()
  expect(typeof parsed === 'object' && parsed !== null, 'must be a JSON object').toBe(true)

  const record = parsed as Record<string, unknown>
  const allowed = new Set(['v', 'theme', 'nav'])
  const unexpected = Object.keys(record).filter((key) => !allowed.has(key))
  expect(
    unexpected,
    `unexpected field(s) in the UI-preferences key: ${unexpected.join(', ')}`,
  ).toEqual([])
  expect(record.v, 'schema version').toBe(1)
  expect(['system', 'light', 'dark'], 'theme').toContain(record.theme)
  if ('nav' in record) expect(['pinned', 'overlay'], 'nav').toContain(record.nav)
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

/**
 * What is on the clipboard, read by the page itself (the context must have been granted `clipboard-read`). Typed
 * structurally because the e2e project has no DOM types.
 */
export async function clipboardText(page: Page): Promise<string> {
  return page.evaluate(() =>
    (navigator as unknown as { clipboard: { readText(): Promise<string> } }).clipboard.readText(),
  )
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

// --- sessions the platform itself minted (apps/platform/database/seeders/E2eSessionSeeder.php) --------------------

/** The sessions `./flow test e2e` mints before the run, one per journey that needs one. */
export type FixtureSession =
  | 'admin-read'
  | 'admin-stale'
  | 'admin-story'
  | 'admin-recover'
  | 'plain-guardian'
  | 'discussions-stale'
  | 'resources-stale-card'
  | 'resources-stale-pack'
  | 'resources-stale-library'

interface MintedSession {
  session: string
  xsrf: string
}

/**
 * A page that is ALREADY signed in, for journeys that are about something other than signing in. The platform minted the
 * session by its own login and second-factor challenge (in-process, with a known Account and a single-use recovery code),
 * so it is exactly what a browser signing in would hold: it is not a shortcut around the platform's checks, only around
 * spending the public login rate budget on set-up. Journeys about signing in itself (invitation, enrolment, the challenge)
 * still do it for real, in the browser.
 *
 * Chromium will not have a Secure `__Host-` cookie injected for an http address, but accepts it for the same host addressed
 * as https, and then sends it to the http origin the Console is served from (as it does the one the server sets itself).
 */
export async function signedInAs(
  browser: Browser,
  baseURL: string,
  name: FixtureSession,
  contextOptions: Parameters<Browser['newContext']>[0] = {},
): Promise<Page> {
  const all = JSON.parse(readFileSync('e2e/.fixtures/sessions.json', 'utf8')) as Record<
    string,
    MintedSession
  >
  const fixture = all[name]
  if (fixture === undefined)
    throw new Error(`No minted session named ${name}. Run ./flow test e2e.`)

  const context = await browser.newContext({ baseURL, ...contextOptions })
  await context.addCookies([
    {
      name: SESSION_COOKIE,
      value: fixture.session,
      url: `https://${new URL(baseURL).host}`,
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
    },
  ])
  return context.newPage()
}

/** The invitation the platform mailed to an address: the whole link (with the token in its fragment), once it arrives. */
export async function invitationEmailedTo(
  request: APIRequestContext,
  baseURL: string,
  email: string,
): Promise<{ link: string; token: string; text: string }> {
  for (let attempt = 0; attempt < 30; attempt++) {
    const ids = await messageIdsTo(request, baseURL, email)
    const id = ids[0]
    if (id !== undefined) {
      const message = await mailpit(request, baseURL, `/api/v1/message/${id}`)
      const text = ((await message.json()) as { Text: string }).Text
      const found = /(https?:\/\/\S+\/accept-invitation#token=([A-Za-z0-9_-]{43}))/.exec(text)
      if (found?.[1] !== undefined && found[2] !== undefined) {
        return { link: found[1], token: found[2], text }
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 500))
  }
  throw new Error(`No invitation email arrived for ${email}.`)
}

/** Opens the account menu from its button and returns the menu. */
export async function openAccountMenu(page: Page) {
  await page.getByRole('button', { name: /account menu/i }).click()
  const menu = page.getByRole('menu', { name: 'Account' })
  await expect(menu).toBeVisible()
  return menu
}

/** Signs out the way an operator does: through the account menu. */
export async function signOut(page: Page): Promise<void> {
  const menu = await openAccountMenu(page)
  await menu.getByRole('menuitem', { name: 'Sign out' }).click()
}

/** Goes to Account security through the account menu, which is where it lives. */
export async function goToAccountSecurity(page: Page): Promise<void> {
  const menu = await openAccountMenu(page)
  await menu.getByRole('menuitem', { name: 'Account security' }).click()
}

/**
 * Fails if any single piece of visible text looks like a 43-character token. Checked per text node, because a
 * token is one string: joining every element's text with no separator (as `toContainText` on the body does)
 * would invent long runs out of adjacent labels such as navigation links.
 */
export async function expectNoTokenShapedText(page: Page): Promise<void> {
  const found = await page.evaluate(() => {
    const g = globalThis as unknown as {
      document: {
        body: unknown
        createTreeWalker: (
          root: unknown,
          whatToShow: number,
        ) => { nextNode: () => { textContent: string | null } | null }
      }
    }
    const walker = g.document.createTreeWalker(g.document.body, 4) // NodeFilter.SHOW_TEXT
    const hits: string[] = []
    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
      if (/[A-Za-z0-9_-]{43}/.test(node.textContent ?? '')) hits.push(node.textContent ?? '')
    }
    return hits
  })
  expect(found, 'token-shaped text on the page').toEqual([])
}

/** A member to look at: the first listed, or a new one if the development database has none. */
export async function aMemberId(admin: Page): Promise<string> {
  const listed = await apiFrom(admin, 'GET', '/api/v1/admin/members?per_page=1')
  // GET .../members wraps its rows in `data` (MembershipPresenter::page); each row is a Member.
  const first = (listed.body as { data: { person: { id: string } }[] }).data[0]
  if (first !== undefined) return first.person.id
  const day = 86_400_000
  const created = await apiFrom(admin, 'POST', '/api/v1/admin/members', {
    display_name: 'E2E Accessibility Member',
    starts_at: new Date(Date.now() - day).toISOString(),
    open_ended: false,
    ends_at: new Date(Date.now() + 30 * day).toISOString(),
    source: 'operator',
    source_reference: null,
  })
  // POST .../members answers the created Member ITSELF (MembershipPresenter::record), not wrapped in
  // `data`: that envelope belongs to the list endpoint above, not this one (openapi.yaml's own
  // `registerMember` operation names `Member` as its 201 schema, not a page of them).
  return (created.body as { person: { id: string } }).person.id
}

/**
 * The id of a People demo Person (`CrmDemoSeeder`, which `./flow test e2e` runs first), found by the directory's own search. Read-only
 * journeys use these; any journey that CHANGES something makes its own Person with a random name instead.
 */
export async function aDemoPersonId(admin: Page, name: string): Promise<string> {
  const found = await apiFrom(admin, 'GET', `/api/v1/admin/people?q=${encodeURIComponent(name)}`)
  const rows = (found.body as { data: { id: string; display_name: string }[] }).data
  const match = rows.find((row) => row.display_name === name)
  if (match === undefined)
    throw new Error(
      `The demo Person "${name}" is not in the directory. Run ./flow test e2e (it seeds the CRM demo data).`,
    )
  return match.id
}

const threads = new Map<string, string>()

/**
 * A discussion made through the API by the signed-in persona (so its controls show for them), for the read-only audits: a
 * title and a message with no break in them to prove nothing overflows, an edited message and a tombstone, and, for 'resolved',
 * a resolution. One per worker per kind: the audits only look at it, and each run's fixture Accounts are new.
 */
export async function aThreadId(page: Page, kind: 'open' | 'resolved'): Promise<string> {
  const known = threads.get(kind)
  if (known !== undefined) return known
  const unbroken = 'Unbroken'.repeat(14)
  const started = await apiFrom(page, 'POST', '/api/v1/admin/discussions', {
    title: `E2E ${kind} thread ${unbroken} ${crypto.randomUUID().slice(0, 8)}`,
    body: `An opening message with a long word: ${unbroken}${unbroken}`,
  })
  const id = (started.body as { id: string }).id
  const edited = await apiFrom(page, 'POST', `/api/v1/admin/discussions/${id}/messages`, {
    body: 'A reply to be edited',
  })
  const gone = await apiFrom(page, 'POST', `/api/v1/admin/discussions/${id}/messages`, {
    body: 'A reply to be removed',
  })
  await apiFrom(
    page,
    'PATCH',
    `/api/v1/admin/discussions/${id}/messages/${(edited.body as { id: string }).id}`,
    {
      body: 'A reply that was edited',
    },
  )
  await apiFrom(
    page,
    'DELETE',
    `/api/v1/admin/discussions/${id}/messages/${(gone.body as { id: string }).id}`,
  )
  if (kind === 'resolved') await apiFrom(page, 'POST', `/api/v1/admin/discussions/${id}/resolve`)
  threads.set(kind, id)
  return id
}
