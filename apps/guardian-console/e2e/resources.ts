import { expect, type Page } from '@playwright/test'

import { apiFrom } from './support.ts'

// Helpers for the Resources management journeys (resources-*.spec.ts). Nothing here is a test. Data is made through the API, from the
// signed-in page exactly as the Console would, so a journey that is about the SCREEN does not spend its time on set-up. Every name is
// random, so journeys running in parallel (and the next run) never meet each other's data; `RemoveAfter` deletes what a journey made.

export const ROOT = '/api/v1/admin/resources'
export const LIBRARY = '/api/v1/admin/resource-library'

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

async function publishCard(page: Page, packId: string, cardId: string): Promise<void> {
  expectStatus(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/cards/${cardId}/publish`),
    200,
    'publish a Card',
  )
}

export async function makeBasicCard(
  page: Page,
  packId: string,
  options: { title?: string; text?: string; publish?: boolean } = {},
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E Card')
  const id = createdId(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/cards`, {
      type: 'basic',
      title,
      content: documentOf(options.text ?? 'Some words worth keeping.'),
      uri: null,
      summary: null,
    }),
    'create a Card',
  )
  if (options.publish === true) await publishCard(page, packId, id)
  return { id, title }
}

export async function makeLinkCard(
  page: Page,
  packId: string,
  options: { title?: string; uri?: string } = {},
): Promise<Id & { title: string }> {
  const title = options.title ?? unique('E2E Link')
  const id = createdId(
    await apiFrom(page, 'POST', `${ROOT}/packs/${packId}/cards`, {
      type: 'external_link',
      title,
      content: null,
      uri: options.uri ?? 'https://example.org/a-page',
      summary: null,
    }),
    'create a link Card',
  )
  return { id, title }
}

interface Dom {
  FormData: new () => { append: (name: string, value: unknown, filename?: string) => void }
  File: new (parts: string[], name: string, options: { type: string }) => unknown
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
  file: { name: string; type: string; text: string },
): Promise<{ status: number; body: unknown }> {
  const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')
  const token = xsrf === undefined ? undefined : decodeURIComponent(xsrf.value)
  return page.evaluate(
    async ({ path, fields, file, token }) => {
      const dom = globalThis as unknown as Dom
      const form = new dom.FormData()
      for (const [name, value] of Object.entries(fields)) form.append(name, value)
      form.append('file', new dom.File([file.text], file.name, { type: file.type }), file.name)
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

/** Deletes what a journey made, quietly: a Pack (with its Cards and files) then the Category. Needs a recent proof, which a fresh session has. */
export class RemoveAfter {
  private readonly packs: string[] = []
  private readonly categories: string[] = []

  pack(id: string): void {
    this.packs.push(id)
  }

  category(id: string): void {
    this.categories.push(id)
  }

  async run(page: Page): Promise<void> {
    for (const id of this.packs)
      await apiFrom(page, 'DELETE', `${ROOT}/packs/${id}`).catch(() => undefined)
    for (const id of this.categories)
      await apiFrom(page, 'DELETE', `${ROOT}/categories/${id}`).catch(() => undefined)
  }
}
