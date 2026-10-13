import { expect, type Browser, type Page } from '@playwright/test'

import { inTheme, type Theme } from './axe.ts'
import { apiFrom, signedInAs } from './support.ts'
import { workerSession, type VerifiedSession } from './verified.ts'

// Helpers for the Resources management journeys (resources-*.spec.ts). Nothing here is a test. Data is made through the API, from the
// signed-in page exactly as the Console would, so a journey that is about the SCREEN does not spend its time on set-up. Every name is
// random, so journeys running in parallel (and the next run) never meet each other's data; `RemoveAfter` deletes what a journey made.

export const ROOT = '/api/v1/admin/resources'
/**
 * What a READER is served (policy C, ADR 0038 R14): only what the signed-in Person is eligible for, as the platform's projection has
 * decided it. The Resource Library reads this for everyone without `resources.view`, and a journey about what a Guardian is or is not
 * shown reads it as the audience reader (below), never as a holder of `resources.view`, who is served `VIEWER_LIBRARY` instead.
 */
export const LIBRARY = '/api/v1/admin/my-resources'
/** What a holder of `resources.view` is served (policy A, R4-R9): every Pack and Card, Drafts and every audience included. */
export const VIEWER_LIBRARY = '/api/v1/admin/resource-library'

/**
 * A page signed in as the AUDIENCE READER: a real Console user who holds the Console and no Resources capability, and who is an Active
 * Guardian (recorded by the seeder through the Relationships use case). The library opens for them as policy C, and what they are
 * shown is exactly what a Guardian is eligible for. Content is made and managed by `plain-guardian`, never by this persona.
 */
export async function signedInAsReader(
  browser: Browser,
  baseURL: string | undefined,
  theme?: Theme,
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', 'audience-reader')
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

export const unique = (label: string): string => `${label} ${crypto.randomUUID().slice(0, 8)}`

/** A small file the platform's `fileinfo` calls a PDF (the committed detection sample is the same idea). */
export const pdfBytes = (marker = 'one'): Buffer =>
  Buffer.from(
    `%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% ${marker}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n`,
  )

export interface Id {
  id: string
}

/** An answer that must have `status`; its body is returned for the caller to read as the shape the API contract promises. */
function expectStatus(
  response: { status: number; body: unknown },
  status: number,
  what: string,
): unknown {
  expect(response.status, `${what}: ${JSON.stringify(response.body)}`).toBe(status)
  return response.body
}

/** The id of what a request created (201). */
function createdId(response: { status: number; body: unknown }, what: string): string {
  const body = expectStatus(response, 201, what)
  const id = typeof body === 'object' && body !== null && 'id' in body ? body.id : undefined
  expect(typeof id, `${what}: no id in ${JSON.stringify(body)}`).toBe('string')
  return String(id)
}

export async function makeCategory(
  page: Page,
  name = unique('E2E Category'),
): Promise<Id & { name: string }> {
  return {
    id: createdId(await apiFrom(page, 'POST', `${ROOT}/categories`, { name }), 'create a Category'),
    name,
  }
}

export async function makePack(
  page: Page,
  options: {
    title?: string
    categoryId?: string | null
    audiences?: string[]
    isSeries?: boolean
  } = {},
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E Pack')
  const id = createdId(
    await apiFrom(page, 'POST', `${ROOT}/packs`, {
      title,
      summary: null,
      is_series: options.isSeries ?? false,
      category_id: options.categoryId ?? null,
    }),
    'create a Pack',
  )
  if ((options.audiences ?? []).length > 0) {
    expectStatus(
      await apiFrom(page, 'PUT', `${ROOT}/packs/${id}/audiences`, { audiences: options.audiences }),
      200,
      'set audiences',
    )
  }
  return { id, title }
}

export const documentOf = (text: string) => ({
  type: 'doc',
  content: [{ type: 'paragraph', content: [{ type: 'text', text }] }],
})

export async function publishCard(page: Page, packId: string, cardId: string): Promise<void> {
  expectStatus(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/cards/${cardId}/publish`),
    200,
    'publish a Card',
  )
}

export async function makeBasicCard(
  page: Page,
  packId: string,
  options: {
    title?: string
    text?: string
    /** A whole document, instead of one paragraph of `text`. */
    document?: unknown
    summary?: string | null
    uri?: string | null
    publish?: boolean
  } = {},
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E Card')
  const id = createdId(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/cards`, {
      type: 'basic',
      title,
      content: options.document ?? documentOf(options.text ?? 'Some words worth keeping.'),
      uri: options.uri ?? null,
      summary: options.summary ?? null,
    }),
    'create a Card',
  )
  if (options.publish === true) await publishCard(page, packId, id)
  return { id, title }
}

export async function makeLinkCard(
  page: Page,
  packId: string,
  options: { title?: string; uri?: string; summary?: string | null; publish?: boolean } = {},
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E Link')
  const id = createdId(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/cards`, {
      type: 'external_link',
      title,
      content: null,
      uri: options.uri ?? 'https://example.org/a-page',
      summary: options.summary ?? null,
    }),
    'create a link Card',
  )
  if (options.publish === true) await publishCard(page, packId, id)
  return { id, title }
}

interface Dom {
  FormData: new () => { append: (name: string, value: unknown, filename?: string) => void }
  File: new (parts: unknown[], name: string, options: { type: string }) => unknown
  atob: (encoded: string) => string
  Uint8Array: { from: (source: string, map: (char: string) => number) => unknown }
  fetch: (
    path: string,
    init: { method: string; headers: Record<string, string>; body: unknown },
  ) => Promise<{
    status: number
    text: () => Promise<string>
  }>
}

/** A multipart request from the page, as the Console makes one (the XSRF token echoed in a header; no Content-Type of ours). */
export async function uploadFrom(
  page: Page,
  path: string,
  fields: Record<string, string>,
  file: { name: string; type: string; text?: string; base64?: string },
): Promise<{ status: number; body: unknown }> {
  const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')
  const token = xsrf === undefined ? undefined : decodeURIComponent(xsrf.value)
  return page.evaluate(
    async ({ path, fields, file, token }) => {
      const dom = globalThis as unknown as Dom
      const form = new dom.FormData()
      for (const [name, value] of Object.entries(fields)) form.append(name, value)
      // A binary file travels as base64 (a string cannot hold arbitrary bytes), and is turned back into bytes here.
      const part =
        file.base64 === undefined
          ? file.text
          : dom.Uint8Array.from(dom.atob(file.base64), (char) => char.charCodeAt(0))
      form.append('file', new dom.File([part], file.name, { type: file.type }), file.name)
      const headers: Record<string, string> = { Accept: 'application/json' }
      if (token !== undefined) headers['X-XSRF-TOKEN'] = token
      const response = await dom.fetch(path, { method: 'POST', headers, body: form })
      const text = await response.text()
      return { status: response.status, body: text === '' ? null : (JSON.parse(text) as unknown) }
    },
    { path, fields, file, token },
  )
}

export async function makeFileCard(
  page: Page,
  packId: string,
  options: { title?: string; name?: string; publish?: boolean } = {},
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E File')
  const id = createdId(
    await uploadFrom(
      page,
      `${ROOT}/packs/${packId}/cards`,
      { type: 'file', title },
      {
        name: options.name ?? 'handbook.pdf',
        type: 'application/pdf',
        text: pdfBytes().toString('latin1'),
      },
    ),
    'create a File Card',
  )
  if (options.publish === true) await publishCard(page, packId, id)
  return { id, title }
}

export async function publishPack(page: Page, packId: string): Promise<void> {
  expectStatus(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/publish`),
    200,
    'publish a Pack',
  )
}

interface PackBody {
  title: string
  summary: string | null
  is_series: boolean
  state: string
  revision: number
  audiences: string[]
  cards: {
    id: string
    title: string
    state: string
    position: number
    audience_mode: string
    audiences: string[]
    file: { name: string } | null
  }[]
}

export async function packOf(page: Page, packId: string): Promise<PackBody> {
  return expectStatus(
    await apiFrom(page, 'GET', `${ROOT}/packs/${packId}`),
    200,
    'read a Pack',
  ) as PackBody
}

/** What one cleanup did: what it deleted, what was already gone, and how its session got the proof that deleting a Pack needs. */
export interface CleanupReport {
  removed: string[]
  alreadyGone: string[]
  signIns: number
  proofs: number
}

/**
 * Deletes what a journey made, and only that: the Packs it registered (with their Cards and files), then the Categories. Nothing is
 * looked up by name and nothing else is touched, so journeys running in parallel each remove exactly their own.
 *
 * It deletes in a session of the WORKER's own (`workerSession`, e2e/verified.ts), never in the journey's: deleting a Pack needs a proof
 * from the last 15 minutes, a long run outlives the minted sessions' proofs, and proving on a session other journeys share would sign
 * them out. When the platform asks for verification, that session proves (with its own authenticator) and the deletion is made again.
 *
 * Every answer is checked. `204` is removed. `404` with the management route's own not-found code (`pack_not_found`,
 * `category_not_found`) means the journey deleted it itself, and is reported as already gone. Anything else (a refusal, a Category
 * still holding a Pack, a verification that could not be established) is a failure: every leftover is listed, by kind and id with the
 * platform's answer, the remaining deletions are still attempted so one failure hides no other, and then `run` throws, so the
 * journey's hook fails rather than reporting success.
 */
export class RemoveAfter {
  private readonly packs: string[] = []
  private readonly categories: string[] = []
  private readonly session: ((browser: Browser) => VerifiedSession) | undefined

  /** `session` decides where the deletions are made (default: this worker's own verified session). */
  constructor(session?: (browser: Browser) => VerifiedSession) {
    this.session = session
  }

  pack(id: string): void {
    this.packs.push(id)
  }

  category(id: string): void {
    this.categories.push(id)
  }

  /** `from` is any page of the journey; only its browser is used. */
  async run(from: Page): Promise<CleanupReport> {
    const packs = this.packs.splice(0)
    const categories = this.categories.splice(0)
    const report: CleanupReport = { removed: [], alreadyGone: [], signIns: 0, proofs: 0 }
    if (packs.length === 0 && categories.length === 0) return report

    const browser = from.context().browser()
    if (browser === null) throw new Error('Cleanup needs the browser the journey ran in.')
    const session = this.session?.(browser) ?? workerSession(browser)
    const before = { signIns: session.signIns, proofs: session.proofs }
    const failures: string[] = []

    const remove = async (
      kind: 'Pack' | 'Category',
      path: string,
      id: string,
      goneCode: string,
    ) => {
      const what = `${kind} ${id}`
      try {
        const answer = await session.request('DELETE', path)
        const code = (answer.body as { code?: unknown } | null)?.code
        if (answer.status === 204) report.removed.push(what)
        else if (answer.status === 404 && code === goneCode) report.alreadyGone.push(what)
        else failures.push(`${what}: ${String(answer.status)} ${JSON.stringify(answer.body)}`)
      } catch (error) {
        failures.push(`${what}: ${error instanceof Error ? error.message : String(error)}`)
      }
    }
    // Packs first: a Category that still holds a Pack is refused (`category_not_empty`).
    for (const id of packs) await remove('Pack', `${ROOT}/packs/${id}`, id, 'pack_not_found')
    for (const id of categories)
      await remove('Category', `${ROOT}/categories/${id}`, id, 'category_not_found')

    report.signIns = session.signIns - before.signIns
    report.proofs = session.proofs - before.proofs
    if (failures.length > 0) {
      throw new Error(
        `Cleanup left ${String(failures.length)} fixture(s) behind:\n${failures.map((f) => `  - ${f}`).join('\n')}`,
      )
    }
    return report
  }
}

/** A 1x1 PNG: the smallest file the platform's own detection calls an image. */
export const PNG_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

/** A File Card of any allowed kind; the platform judges the content, whatever the name or the claimed type say. */
export async function makeUploadCard(
  page: Page,
  packId: string,
  options: {
    title?: string
    name: string
    type: string
    text?: string
    base64?: string
    publish?: boolean
  },
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E File')
  const file = {
    name: options.name,
    type: options.type,
    ...(options.text !== undefined && { text: options.text }),
    ...(options.base64 !== undefined && { base64: options.base64 }),
  }
  const id = createdId(
    await uploadFrom(page, `${ROOT}/packs/${packId}/cards`, { type: 'file', title }, file),
    'create a File Card',
  )
  if (options.publish === true) await publishCard(page, packId, id)
  return { id, title }
}

/** Narrows a Card to part of its Pack's audience. */
export async function narrowCard(
  page: Page,
  packId: string,
  cardId: string,
  audiences: string[],
): Promise<void> {
  expectStatus(
    await apiFrom(page, 'PUT', `${ROOT}/packs/${packId}/cards/${cardId}/audiences`, {
      mode: 'narrowed',
      audiences,
    }),
    200,
    'narrow a Card',
  )
}
