import { expect, test, type Browser, type Locator, type Page } from '@playwright/test'

import { axeViolations, inTheme, THEMES, type Theme } from './axe.ts'
import { apiFrom, signedInAs } from './support.ts'

// The Guardian Discussions closeout (ADR 0035, G2 Work Package 3), in real Chromium through the real gateway, over the demo data
// (`DiscussionsDemoSeeder`, which `./flow test e2e` runs first). discussions.spec.ts already proves the working journey of one
// Guardian (start, reply, edit, resolve, reopen, remove, search) and the capability layers. This file adds what it leaves out: the
// demo dataset itself, two Guardians in one thread, ownership against real server refusals, paging, what the wire carries, that a
// stale verification does not block taking part, and the accessibility, keyboard and narrow-screen behaviour of the states that matter.
//
// Isolation: journeys that only READ use the demo threads by title. Any journey that CHANGES something makes its own discussion
// with a random title, so the demo data is the same on the next run (a test below pins that it has not drifted).

test.describe.configure({ timeout: 120_000 })

const OPEN = 'Autumn gathering: set-up crew and roles'
const RESOLVED = 'Room hire for the winter series'
const UNKNOWN_AUTHOR = 'Volunteer welcome checklist'
const PAGING = 'Harvest gathering debrief'
const SINGLE = 'Banner and signage ideas'

/** The demo, as the seeder wrote it: title, state, message count. */
const DEMO: [string, string, number][] = [
  [OPEN, 'open', 4],
  [RESOLVED, 'resolved', 4],
  [UNKNOWN_AUTHOR, 'open', 4],
  [PAGING, 'open', 27],
  [SINGLE, 'open', 1],
]

// The seeder writes as the first two (by email) Accounts that may take part, which in the browser suite are these two.
const A = 'E2E Plain Guardian' // e2e.admin.guardian@: the first operator, so the "a" of the demo
const B = 'E2E Admin Read' // e2e.admin.read@: the second operator, so the "b"

/** What the page's elements are called in the browser, for the callbacks that run there (this project has no DOM library). */
interface Labelled {
  getAttribute: (name: string) => string | null
}

type Who = 'plain-guardian' | 'admin-read'

/** A signed-in Guardian already on the Console (an API lookup from a blank page has no origin to ask). */
async function guardianOn(
  browser: Browser,
  baseURL: string | undefined,
  as: Who | 'discussions-stale' = 'plain-guardian',
  theme?: Theme,
): Promise<Page> {
  const page = await signedInAs(browser, baseURL ?? '', as)
  if (theme !== undefined) await inTheme(page, theme)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible()
  return page
}

const body = (page: Page) => page.locator('[data-page-width]')

interface ListedDiscussion {
  id: string
  title: string
  state: string
  creator: { id: string; display_name: string | null } | null
  message_count: number
  last_activity_at: string
  resolved_at: string | null
  resolved_by: { display_name: string | null } | null
}

interface ListedMessage {
  id: string
  sequence: number
  removed: boolean
  body?: string
  edited_at?: string | null
  author: { id: string; display_name: string | null }
}

/** Every discussion the server lists, in the order it lists them (all pages). */
async function listAll(page: Page): Promise<ListedDiscussion[]> {
  const all: ListedDiscussion[] = []
  for (let n = 1; ; n++) {
    const got = (
      await apiFrom(page, 'GET', `/api/v1/admin/discussions?page=${String(n)}&per_page=100`)
    ).body as { data: ListedDiscussion[]; meta: { last_page: number } }
    all.push(...got.data)
    if (n >= got.meta.last_page) return all
  }
}

async function messagesOf(page: Page, id: string): Promise<ListedMessage[]> {
  const all: ListedMessage[] = []
  for (let n = 1; ; n++) {
    const got = (
      await apiFrom(
        page,
        'GET',
        `/api/v1/admin/discussions/${id}/messages?page=${String(n)}&per_page=100`,
      )
    ).body as { data: ListedMessage[]; meta: { last_page: number } }
    all.push(...got.data)
    if (n >= got.meta.last_page) return all
  }
}

async function demoIds(page: Page): Promise<Record<string, string>> {
  const listed = await listAll(page)
  const ids: Record<string, string> = {}
  for (const [title] of DEMO) {
    const found = listed.find((d) => d.title === title)
    if (found === undefined)
      throw new Error(
        `The demo discussion "${title}" is not there. Run ./flow test e2e (it seeds the Discussions demo data).`,
      )
    ids[title] = found.id
  }
  return ids
}

/** A discussion of the signed-in persona's own, made through the API, with a reply to edit and one to remove. */
async function ownThread(
  page: Page,
  label: string,
  words: { title?: string; opening?: string } = {},
): Promise<{ id: string; title: string; messages: { id: string }[] }> {
  const tag = crypto.randomUUID().slice(0, 8)
  const title = words.title ?? `E2E ${label} ${tag}`
  const started = await apiFrom(page, 'POST', '/api/v1/admin/discussions', {
    title,
    body: words.opening ?? `Opening words ${tag}`,
  })
  const id = (started.body as { id: string }).id
  const messages = (
    (await apiFrom(page, 'GET', `/api/v1/admin/discussions/${id}/messages`)).body as {
      data: { id: string }[]
    }
  ).data
  return { id, title, messages }
}

async function reply(page: Page, id: string, text: string): Promise<{ id: string }> {
  const made = await apiFrom(page, 'POST', `/api/v1/admin/discussions/${id}/messages`, {
    body: text,
  })
  expect(made.status).toBe(201)
  return made.body as { id: string }
}

/** The numbers in the article labels of the thread shown, in the order shown. */
async function shownSequences(page: Page): Promise<number[]> {
  await expect(page.getByRole('article').first()).toBeVisible()
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0)
  const labels = await page
    .getByRole('article')
    .evaluateAll((els) => (els as Labelled[]).map((el) => el.getAttribute('aria-label') ?? ''))
  return labels.map((label) => {
    const match = /^Message (\d+)/.exec(label)
    return match === null ? -1 : Number(match[1])
  })
}

/** Opens a discussion from the list the way a Guardian does: by searching its title. */
async function openFromList(page: Page, title: string): Promise<void> {
  await page
    .getByRole('navigation', { name: 'Console' })
    .getByRole('link', { name: 'Discussions' })
    .click()
  await expect(page.getByRole('heading', { level: 1, name: 'Discussions' })).toBeVisible()
  await page.getByRole('searchbox', { name: 'Search titles' }).fill(title)
  await page.getByRole('button', { name: 'Search' }).click()
  await page.getByRole('table', { name: 'Discussions' }).getByRole('link', { name: title }).click()
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
}

const ALLOWED_KEYS = new Set([
  'data',
  'meta',
  'page',
  'per_page',
  'total',
  'last_page',
  'id',
  'title',
  'state',
  'creator',
  'display_name',
  'message_count',
  'last_activity_at',
  'created_at',
  'resolved_at',
  'resolved_by',
  'sequence',
  'author',
  'removed',
  'removed_at',
  'body',
  'edited_at',
  'edited_by',
])

/** Every key at any depth of a JSON value. */
function keysOf(value: unknown, into = new Set<string>()): Set<string> {
  if (Array.isArray(value)) value.forEach((entry) => keysOf(entry, into))
  else if (typeof value === 'object' && value !== null) {
    for (const [key, entry] of Object.entries(value)) {
      into.add(key)
      keysOf(entry, into)
    }
  }
  return into
}

test.describe('the demo data, read as a Guardian reads it', () => {
  test('is what the seeder wrote, authored by the fixture Guardians, and has not drifted since an earlier run', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const listed = await listAll(guardian)

    for (const [title, state, count] of DEMO) {
      const found = listed.filter((d) => d.title === title)
      expect(found, `${title}: exactly one`).toHaveLength(1)
      expect(found[0]?.state, `${title}: state`).toBe(state)
      expect(found[0]?.message_count, `${title}: message count`).toBe(count)
    }

    const byTitle = (title: string) => listed.find((d) => d.title === title)
    // Authorship names Persons that EXIST now: the fixture Accounts are recreated on every run and the seeder repairs the rest.
    expect(byTitle(OPEN)?.creator?.display_name, 'the first operator is the plain Guardian').toBe(A)
    expect(
      byTitle(RESOLVED)?.creator?.display_name,
      'the second operator is the administrator',
    ).toBe(B)
    expect(byTitle(RESOLVED)?.resolved_by?.display_name).toBe(A)

    let edited = 0
    let removed = 0
    let unknown = 0
    for (const [title, , count] of DEMO) {
      const messages = await messagesOf(guardian, byTitle(title)?.id ?? '')
      expect(
        messages.map((m) => m.sequence),
        `${title}: sequences`,
      ).toEqual(Array.from({ length: count }, (_, i) => i + 1))
      for (const m of messages) {
        if (m.author.display_name === null) unknown++
        if (m.removed) removed++
        else if (m.edited_at != null) edited++
      }
    }
    // One edited message, one tombstone, one unknown author: the same on every run, whatever earlier journeys did elsewhere.
    expect({ edited, removed, unknown }).toEqual({ edited: 1, removed: 1, unknown: 1 })

    await guardian.context().close()
  })

  test('is listed by last activity, newest first, and a resolution newer than every message does not move a thread', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const listed = await listAll(guardian)
    const demo = listed.filter((d) => DEMO.some(([title]) => title === d.title))

    expect(demo.map((d) => d.title)).toEqual([OPEN, UNKNOWN_AUTHOR, RESOLVED, PAGING, SINGLE])
    const resolved = demo.find((d) => d.title === RESOLVED)
    const ms = (iso: string | null | undefined) => Date.parse(iso ?? '')
    expect(ms(resolved?.resolved_at)).toBeGreaterThan(ms(resolved?.last_activity_at))
    // ...so resolving moved nothing: the thread still sorts below one whose last MESSAGE is older than the resolution.
    expect(ms(resolved?.last_activity_at)).toBeLessThan(
      ms(demo.find((d) => d.title === UNKNOWN_AUTHOR)?.last_activity_at),
    )

    // The page shows the server's order, untouched: its rows are the first page of the same list.
    await guardian.goto('/discussions')
    const table = guardian.getByRole('table', { name: 'Discussions' })
    await expect(table).toBeVisible()
    const rows = (await table.getByRole('rowheader').allInnerTexts()).map((t) => t.trim())
    expect(rows).toEqual(listed.slice(0, rows.length).map((d) => d.title))
    expect(rows.length).toBeGreaterThan(0)

    await guardian.context().close()
  })

  test('is found by TITLE only, narrowed by state, and opens at its first message', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await guardian
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'Discussions' })
      .click()
    const table = guardian.getByRole('table', { name: 'Discussions' })
    const search = guardian.getByRole('searchbox', { name: 'Search titles' })
    const show = guardian.getByRole('combobox', { name: 'Show' })
    const none = guardian.getByText('No discussions match.')

    await search.fill('Room hire')
    await guardian.getByRole('button', { name: 'Search' }).click()
    await expect(table.getByRole('rowheader')).toHaveCount(1)
    await expect(table.getByRole('link', { name: RESOLVED })).toBeVisible()
    await expect(table.getByRole('row', { name: new RegExp(RESOLVED) })).toContainText('Resolved')
    await show.selectOption('open')
    await expect(none).toBeVisible()
    await show.selectOption('resolved')
    await expect(table.getByRole('link', { name: RESOLVED })).toBeVisible()
    await show.selectOption('')

    // A word that is only in a message is not found, and the same word in a title is.
    await search.fill('lanterns')
    await guardian.getByRole('button', { name: 'Search' }).click()
    await expect(none).toBeVisible()
    await search.fill('signage')
    await guardian.getByRole('button', { name: 'Search' }).click()
    await table.getByRole('link', { name: SINGLE }).click()
    await expect(guardian.getByRole('heading', { level: 1, name: SINGLE })).toBeVisible()
    await expect(guardian.getByRole('article')).toHaveCount(1)
    await expect(guardian.getByRole('article', { name: /^Message 1 by/ })).toContainText('lanterns')
    await expect(body(guardian).getByText('Opening message', { exact: true })).toBeVisible()
    await expect(body(guardian).getByText('Open', { exact: true })).toBeVisible()

    await guardian.context().close()
  })

  test('reads in sequence, and a long thread pages without losing its order', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    await openFromList(guardian, PAGING)

    expect(await shownSequences(guardian)).toEqual(Array.from({ length: 25 }, (_, i) => i + 1))
    await expect(guardian.getByRole('navigation', { name: 'Pages' })).toContainText(
      'Page 1 of 2 (27 messages)',
    )
    const next = guardian.getByRole('button', { name: 'Next' })
    const previous = guardian.getByRole('button', { name: 'Previous' })
    await expect(previous).toBeDisabled()

    // By keyboard: the control is a real button.
    await next.focus()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByRole('navigation', { name: 'Pages' })).toContainText('Page 2 of 2')
    expect(await shownSequences(guardian)).toEqual([26, 27])
    await expect(next).toBeDisabled()
    await expect(guardian.getByRole('article', { name: /^Message 27 by/ })).toContainText(
      'Point 26',
    )

    await previous.focus()
    await guardian.keyboard.press('Enter')
    expect(await shownSequences(guardian)).toEqual(Array.from({ length: 25 }, (_, i) => i + 1))

    await guardian.context().close()
  })

  test('shows each person only what is theirs to change: their words, never a namesake’s, a tombstone or an unknown author', async ({
    browser,
    baseURL,
  }) => {
    const a = await guardianOn(browser, baseURL, 'plain-guardian')
    const b = await guardianOn(browser, baseURL, 'admin-read')
    const ids = await demoIds(a)
    const editable = async (page: Page) => {
      await expect(page.getByRole('article').first()).toBeVisible()
      return page
        .getByRole('button', { name: /^Edit your message \d+$/ })
        .evaluateAll((els) =>
          (els as Labelled[]).map((el) =>
            Number(/(\d+)$/.exec(el.getAttribute('aria-label') ?? '')?.[1]),
          ),
        )
    }

    // The open thread: the first operator wrote messages 1 and 3 (and started it); the second wrote 2 and 4. Message 3 was edited.
    await a.goto(`/discussions/${ids[OPEN] ?? ''}`)
    await expect(a.getByRole('heading', { level: 1, name: OPEN })).toBeVisible()
    await expect(a.getByRole('article')).toHaveCount(4)
    expect(await editable(a)).toEqual([1, 3])
    await expect(a.getByRole('button', { name: 'Edit title' })).toBeVisible()
    await expect(a.getByRole('article', { name: /^Message 3 by/ })).toContainText('Edited')
    await expect(a.getByRole('article', { name: /^Message 1 by/ })).not.toContainText('Edited')
    await b.goto(`/discussions/${ids[OPEN] ?? ''}`)
    await expect(b.getByRole('article')).toHaveCount(4)
    expect(await editable(b)).toEqual([2, 4])
    await expect(b.getByRole('button', { name: 'Edit title' })).toHaveCount(0)

    // The resolved thread: replies are closed, the tombstone keeps its place and offers nothing, and the title is its creator's.
    await a.goto(`/discussions/${ids[RESOLVED] ?? ''}`)
    await expect(a.getByRole('region', { name: 'Replies are closed' })).toBeVisible()
    await expect(a.getByRole('textbox', { name: 'Your reply' })).toHaveCount(0)
    const tombstone = a.getByRole('article', { name: 'Message 3, removed' })
    await expect(tombstone).toContainText('This message was removed.')
    await expect(tombstone.getByRole('button')).toHaveCount(0)
    await expect(body(a)).not.toContainText('Scrap that')
    expect(await shownSequences(a)).toEqual([1, 2, 3, 4])
    expect(await editable(a)).toEqual([2]) // the removed 3 is not offered
    await expect(a.getByRole('button', { name: 'Edit title' })).toHaveCount(0) // the second operator started it
    await expect(a.getByText(/Resolved by E2E Plain Guardian/)).toBeVisible()
    await b.goto(`/discussions/${ids[RESOLVED] ?? ''}`)
    expect(await editable(b)).toEqual([1, 4])
    await expect(b.getByRole('button', { name: 'Edit title' })).toBeVisible()
    await expect(b.getByRole('button', { name: 'Reopen discussion' })).toBeVisible()

    // An author Identity no longer holds: the words stay, the name is "Unknown person", and nobody is offered the message.
    for (const page of [a, b]) {
      await page.goto(`/discussions/${ids[UNKNOWN_AUTHOR] ?? ''}`)
      const unknown = page.getByRole('article', { name: 'Message 3 by Unknown person' })
      await expect(unknown).toContainText('Add the tidy-up rota')
      await expect(unknown.getByRole('button')).toHaveCount(0)
      expect(await editable(page)).not.toContain(3)
    }

    await a.context().close()
    await b.context().close()
  })

  test('is refused by the server, not just hidden: another’s words, an unknown author, a tombstone and a title', async ({
    browser,
    baseURL,
  }) => {
    const a = await guardianOn(browser, baseURL, 'plain-guardian')
    const b = await guardianOn(browser, baseURL, 'admin-read')
    const ids = await demoIds(a)
    const at = (title: string, message?: string) =>
      `/api/v1/admin/discussions/${ids[title] ?? ''}${message === undefined ? '' : `/messages/${message}`}`
    const open = await messagesOf(a, ids[OPEN] ?? '')
    const resolved = await messagesOf(a, ids[RESOLVED] ?? '')
    const unknown = (await messagesOf(a, ids[UNKNOWN_AUTHOR] ?? '')).find(
      (m) => m.author.display_name === null,
    )
    const snapshot = JSON.stringify([open, resolved, unknown])
    const idOf = (list: ListedMessage[], sequence: number) =>
      list.find((m) => m.sequence === sequence)?.id ?? ''

    const notAuthor = [
      await apiFrom(a, 'PATCH', at(OPEN, idOf(open, 2)), { body: 'Rewritten' }), // the other operator's message
      await apiFrom(a, 'DELETE', at(OPEN, idOf(open, 2))),
      await apiFrom(b, 'PATCH', at(OPEN, idOf(open, 1)), { body: 'Rewritten' }),
      await apiFrom(b, 'PATCH', at(OPEN), { title: 'Not yours to rename' }), // the first operator started it
      await apiFrom(a, 'PATCH', at(RESOLVED), { title: 'Not yours to rename' }), // ...and the second started this one
      await apiFrom(a, 'PATCH', at(UNKNOWN_AUTHOR, unknown?.id ?? ''), { body: 'Mine now' }), // nobody wrote it
      await apiFrom(b, 'PATCH', at(UNKNOWN_AUTHOR, unknown?.id ?? ''), { body: 'Mine now' }),
      await apiFrom(a, 'DELETE', at(UNKNOWN_AUTHOR, unknown?.id ?? '')),
      await apiFrom(b, 'DELETE', at(UNKNOWN_AUTHOR, unknown?.id ?? '')),
    ]
    for (const [index, attempt] of notAuthor.entries()) {
      expect(attempt.status, `attempt ${String(index)}`).toBe(403)
      expect((attempt.body as { code: string }).code, `attempt ${String(index)}`).toBe('not_author')
    }

    // A tombstone is not editable, even by the person who wrote it.
    const edit = await apiFrom(a, 'PATCH', at(RESOLVED, idOf(resolved, 3)), {
      body: 'Bringing it back',
    })
    expect(edit.status).toBe(409)
    expect((edit.body as { code: string }).code).toBe('message_removed')

    // Nothing moved: every message and its text are as they were.
    expect(
      JSON.stringify([
        await messagesOf(a, ids[OPEN] ?? ''),
        await messagesOf(a, ids[RESOLVED] ?? ''),
        (await messagesOf(a, ids[UNKNOWN_AUTHOR] ?? '')).find(
          (m) => m.author.display_name === null,
        ),
      ]),
    ).toBe(snapshot)

    await a.context().close()
    await b.context().close()
  })

  test('carries only discussion fields and a Person’s id and name: no Account, login, role, capability, MFA or Membership', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL)
    const ids = await demoIds(guardian)
    const responses = [
      (await apiFrom(guardian, 'GET', '/api/v1/admin/discussions?per_page=100')).body,
      ...(await Promise.all(
        Object.values(ids).flatMap((id) => [
          apiFrom(guardian, 'GET', `/api/v1/admin/discussions/${id}`).then((r) => r.body),
          apiFrom(guardian, 'GET', `/api/v1/admin/discussions/${id}/messages?per_page=100`).then(
            (r) => r.body,
          ),
        ]),
      )),
    ]

    const unexpected = new Set<string>()
    for (const response of responses)
      for (const key of keysOf(response)) if (!ALLOWED_KEYS.has(key)) unexpected.add(key)
    expect([...unexpected]).toEqual([])

    // Every Person object is exactly an id and a name.
    const people: Record<string, unknown>[] = []
    const collect = (value: unknown, key?: string) => {
      if (Array.isArray(value))
        value.forEach((v) => {
          collect(v, key)
        })
      else if (typeof value === 'object' && value !== null) {
        if (['creator', 'resolved_by', 'author', 'edited_by'].includes(key ?? ''))
          people.push(value as Record<string, unknown>)
        for (const [k, v] of Object.entries(value)) collect(v, k)
      }
    }
    responses.forEach((r) => {
      collect(r)
    })
    expect(people.length).toBeGreaterThan(10)
    for (const person of people) expect(Object.keys(person).sort()).toEqual(['display_name', 'id'])

    // And nowhere is a login address, whatever key it is under; a removed message has no text key at all.
    const everything = JSON.stringify(responses)
    expect(everything).not.toMatch(/@example\.org/)
    expect(everything).not.toContain('Scrap that')
    const resolved = await messagesOf(guardian, ids[RESOLVED] ?? '')
    const gone = resolved.find((m) => m.removed)
    expect(gone).toBeDefined()
    expect('body' in (gone ?? {})).toBe(false)
    expect('edited_at' in (gone ?? {})).toBe(false)

    await guardian.context().close()
  })
})

test.describe('two Guardians in one thread', () => {
  test('one starts, the other replies and resolves, the creator corrects while resolved, and either reopens', async ({
    browser,
    baseURL,
  }) => {
    const creator = await guardianOn(browser, baseURL, 'admin-read')
    const other = await guardianOn(browser, baseURL, 'plain-guardian')
    const tag = crypto.randomUUID().slice(0, 8)
    const title = `E2E Duet ${tag}`

    // The creator starts it through the form.
    await creator
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'Discussions' })
      .click()
    await body(creator).getByRole('link', { name: 'Start a discussion' }).click()
    await creator.getByRole('textbox', { name: 'Title', exact: true }).fill(title)
    await creator
      .getByRole('textbox', { name: 'Opening message' })
      .fill(`Opening by the creator ${tag}`)
    await creator.getByRole('button', { name: 'Start discussion' }).click()
    await expect(creator.getByRole('heading', { level: 1, name: title })).toBeVisible()
    const id = new URL(creator.url()).pathname.split('/').pop() ?? ''
    const state = async (page: Page) =>
      (await apiFrom(page, 'GET', `/api/v1/admin/discussions/${id}`)).body as ListedDiscussion
    const created = await state(creator)
    expect(created.creator?.display_name).toBe(B)
    expect(created.state).toBe('open')

    // The other Guardian finds it, sees the creator's words as the creator's, and may take part but not change them.
    await openFromList(other, title)
    await expect(other.getByRole('article', { name: `Message 1 by ${B}` })).toContainText(
      `Opening by the creator ${tag}`,
    )
    await expect(
      other.getByRole('button', { name: /Edit your message|Remove your message/ }),
    ).toHaveCount(0)
    await expect(other.getByRole('button', { name: 'Edit title' })).toHaveCount(0)
    await other.getByRole('textbox', { name: 'Your reply' }).fill(`A reply from the other ${tag}`)
    await other.getByRole('button', { name: 'Post reply' }).click()
    await expect(other.getByRole('article', { name: `Message 2 by ${A}` })).toContainText(
      `A reply from the other ${tag}`,
    )
    await expect(other.getByRole('button', { name: 'Edit your message 2' })).toBeVisible()
    const afterReply = await state(other)
    expect(afterReply.message_count).toBe(2)
    expect(afterReply.last_activity_at >= created.last_activity_at).toBe(true)

    // Resolving is theirs to do, though the discussion is not: it records who, and it is NOT activity.
    await other.getByRole('button', { name: 'Mark as resolved' }).click()
    await expect(other.getByText('Marked as resolved.')).toBeVisible()
    const resolved = await state(other)
    expect(resolved.state).toBe('resolved')
    expect(resolved.resolved_by?.display_name).toBe(A)
    expect(resolved.last_activity_at).toBe(afterReply.last_activity_at)

    // The creator sees it resolved with replies closed, and the server refuses a reply.
    await creator.reload()
    await expect(creator.getByText(new RegExp(`Resolved by ${A}`))).toBeVisible()
    await expect(creator.getByRole('region', { name: 'Replies are closed' })).toBeVisible()
    const refused = await apiFrom(creator, 'POST', `/api/v1/admin/discussions/${id}/messages`, {
      body: 'Too late',
    })
    expect(refused.status).toBe(409)
    expect((refused.body as { code: string }).code).toBe('discussion_resolved')

    // Resolved is not frozen: the creator corrects their own words and the title, and neither is activity.
    await creator.getByRole('button', { name: 'Edit your message 1' }).click()
    await creator.getByRole('textbox', { name: 'Your message' }).fill(`Opening, corrected ${tag}`)
    await creator.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(creator.getByRole('article', { name: /^Message 1 by/ })).toContainText(
      `Opening, corrected ${tag}`,
    )
    await creator.getByRole('button', { name: 'Edit title' }).click()
    await creator.getByRole('textbox', { name: 'Title', exact: true }).fill(`${title} (settled)`)
    await creator.getByRole('button', { name: 'Save title' }).click()
    await expect(
      creator.getByRole('heading', { level: 1, name: `${title} (settled)` }),
    ).toBeVisible()
    // The other's own message can still be withdrawn while resolved; the creator is offered nothing on it.
    await expect(
      creator.getByRole('button', { name: /Edit your message 2|Remove your message 2/ }),
    ).toHaveCount(0)
    expect((await state(creator)).last_activity_at).toBe(afterReply.last_activity_at)

    // Either may reopen, and a reply then follows; reopening is not activity either.
    await creator.getByRole('button', { name: 'Reopen discussion' }).click()
    await expect(creator.getByRole('textbox', { name: 'Your reply' })).toBeVisible()
    const reopened = await state(creator)
    expect(reopened.state).toBe('open')
    expect(reopened.resolved_at).toBeNull()
    expect(reopened.last_activity_at).toBe(afterReply.last_activity_at)
    await other.reload()
    await other.getByRole('textbox', { name: 'Your reply' }).fill(`Back again ${tag}`)
    await other.getByRole('button', { name: 'Post reply' }).click()
    await expect(other.getByRole('article', { name: `Message 3 by ${A}` })).toBeVisible()
    expect((await state(other)).last_activity_at >= afterReply.last_activity_at).toBe(true)

    // The creator withdraws their opening message: a tombstone, in place, in an open thread, that nothing can bring back.
    await creator.reload()
    expect(await shownSequences(creator)).toEqual([1, 2, 3])
    await creator.getByRole('button', { name: 'Remove your message 1' }).click()
    await creator.getByRole('button', { name: 'Remove message' }).click()
    const tombstone = creator.getByRole('article', { name: 'Message 1, removed' })
    await expect(tombstone).toContainText('This message was removed.')
    await expect(tombstone).toContainText('Opening message')
    await expect(tombstone.getByRole('button')).toHaveCount(0)
    expect(await shownSequences(creator)).toEqual([1, 2, 3])
    await expect(body(creator)).not.toContainText(`Opening, corrected ${tag}`)
    await other.reload()
    await expect(other.getByRole('article', { name: 'Message 1, removed' })).toBeVisible()
    await expect(body(other)).not.toContainText(`Opening, corrected ${tag}`)
    const first = (await messagesOf(creator, id)).find((m) => m.sequence === 1)
    const restore = await apiFrom(
      creator,
      'PATCH',
      `/api/v1/admin/discussions/${id}/messages/${first?.id ?? ''}`,
      { body: 'Restored' },
    )
    expect(restore.status).toBe(409)
    expect(JSON.stringify(await messagesOf(creator, id))).not.toContain(`Opening, corrected ${tag}`)

    await creator.context().close()
    await other.context().close()
  })

  test('a list of more than one page pages, searched by a title they all share', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL, 'admin-read')
    const tag = crypto.randomUUID().slice(0, 8)
    for (let n = 1; n <= 26; n++) {
      const made = await apiFrom(guardian, 'POST', '/api/v1/admin/discussions', {
        title: `E2E Paged ${tag} number ${String(n).padStart(2, '0')}`,
        body: `Paged discussion ${String(n)}`,
      })
      expect(made.status).toBe(201)
    }

    await guardian
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'Discussions' })
      .click()
    await guardian.getByRole('searchbox', { name: 'Search titles' }).fill(`E2E Paged ${tag}`)
    await guardian.getByRole('button', { name: 'Search' }).click()
    const table = guardian.getByRole('table', { name: 'Discussions' })
    const pages = guardian.getByRole('navigation', { name: 'Pages' })
    await expect(pages).toContainText('Page 1 of 2 (26 discussions)')
    // Newest activity first: the last one made is the first one shown.
    const rows = (await table.getByRole('rowheader').allInnerTexts()).map((t) => t.trim())
    expect(rows).toHaveLength(25)
    expect(rows[0]).toContain('number 26')
    await guardian.getByRole('button', { name: 'Next' }).click()
    await expect(pages).toContainText('Page 2 of 2')
    await expect(table.getByRole('rowheader')).toHaveCount(1)
    await expect(table.getByRole('link', { name: /number 01$/ })).toBeVisible()
    await guardian.getByRole('button', { name: 'Previous' }).click()
    await expect(table.getByRole('rowheader')).toHaveCount(25)

    await guardian.context().close()
  })
})

test.describe('who may take part, and what proof it needs', () => {
  test('a stale verification does not stop taking part, while an unrelated privileged action is still refused', async ({
    browser,
    baseURL,
  }) => {
    const stale = await guardianOn(browser, baseURL, 'discussions-stale')
    const tag = crypto.randomUUID().slice(0, 8)
    // The session is alive but its last proof is older than the window: it can still start, reply, resolve and reopen, in the Console.
    await stale.goto('/discussions/new')
    await stale.getByRole('textbox', { name: 'Title', exact: true }).fill(`E2E Stale ${tag}`)
    await stale
      .getByRole('textbox', { name: 'Opening message' })
      .fill('Started without a fresh proof')
    await stale.getByRole('button', { name: 'Start discussion' }).click()
    await expect(stale.getByRole('heading', { level: 1, name: `E2E Stale ${tag}` })).toBeVisible()
    await stale.getByRole('textbox', { name: 'Your reply' }).fill('Replying without a fresh proof')
    await stale.getByRole('button', { name: 'Post reply' }).click()
    await expect(stale.getByText('Replying without a fresh proof')).toBeVisible()
    await stale.getByRole('button', { name: 'Mark as resolved' }).click()
    await expect(stale.getByText('Marked as resolved.')).toBeVisible()
    await stale.getByRole('button', { name: 'Reopen discussion' }).click()
    await expect(stale.getByRole('textbox', { name: 'Your reply' })).toBeVisible()
    await expect(stale.getByRole('dialog')).toHaveCount(0) // no proof prompt anywhere

    // The same session, the same minute: an action that grants authority still wants the proof (refused before anything is read).
    const refused = await apiFrom(stale, 'POST', '/api/v1/admin/invitations', {})
    expect(refused.status).toBe(403)
    expect(refused.body).toMatchObject({ verification_required: true })

    await stale.context().close()
  })

  test('an Account without the Console is refused the discussions API, and is shown no Discussions', async ({
    page,
  }) => {
    await page.goto('/login')
    await page.getByLabel('Email address').fill('e2e.noaccess@example.org')
    await page.getByLabel('Password', { exact: true }).fill('e2e-noaccess-password-not-a-secret')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible()

    await expect(page.getByRole('link', { name: 'Discussions' })).toHaveCount(0)
    for (const [method, path] of [
      ['GET', '/api/v1/admin/discussions'],
      ['POST', '/api/v1/admin/discussions'],
    ] as const) {
      const refused = await apiFrom(
        page,
        method,
        path,
        method === 'POST' ? { title: 'No', body: 'No' } : undefined,
      )
      expect(refused.status, `${method} ${path}`).toBe(403)
    }
  })
})

// --- accessibility, keyboard and narrow screens ------------------------------------------------------------------

/** What axe says about the page as it stands, once it has settled. */
async function audit(page: Page): Promise<string[]> {
  await page.waitForLoadState('networkidle')
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0)
  return axeViolations(page)
}

for (const theme of THEMES) {
  test.describe(`accessibility of the Discussions states in ${theme}`, () => {
    test(`the demo threads, a page of a long one and an empty search pass axe (${theme})`, async ({
      browser,
      baseURL,
    }) => {
      const guardian = await guardianOn(browser, baseURL, 'plain-guardian', theme)
      const ids = await demoIds(guardian)

      for (const [title] of DEMO) {
        await guardian.goto(`/discussions/${ids[title] ?? ''}`)
        await expect(guardian.getByRole('heading', { level: 1, name: title })).toBeVisible()
        await expect(guardian.getByRole('article').first()).toBeVisible()
        expect(await audit(guardian), title).toEqual([])
      }

      await guardian.goto(`/discussions/${ids[PAGING] ?? ''}`)
      await guardian.getByRole('button', { name: 'Next' }).click()
      await expect(guardian.getByRole('navigation', { name: 'Pages' })).toContainText('Page 2 of 2')
      expect(await audit(guardian), 'the second page of a long thread').toEqual([])

      await guardian.goto('/discussions')
      await guardian
        .getByRole('searchbox', { name: 'Search titles' })
        .fill('no title says this at all')
      await guardian.getByRole('button', { name: 'Search' }).click()
      await expect(guardian.getByText('No discussions match.')).toBeVisible()
      expect(await audit(guardian), 'no results').toEqual([])
      await guardian.context().close()
    })

    test(`the form errors, the edit form, the remove dialog and a refused reply pass axe (${theme})`, async ({
      browser,
      baseURL,
    }) => {
      const guardian = await guardianOn(browser, baseURL, 'plain-guardian', theme)

      // A validation error on the start form: a title of only spaces is refused by the server, in words.
      await guardian.goto('/discussions/new')
      await guardian.getByRole('textbox', { name: 'Title', exact: true }).fill('   ')
      await guardian.getByRole('textbox', { name: 'Opening message' }).fill('Something to say')
      await guardian.getByRole('button', { name: 'Start discussion' }).click()
      await expect(
        guardian
          .getByRole('alert')
          .or(guardian.locator('[role="alert"], [aria-invalid="true"]'))
          .first(),
      ).toBeVisible()
      expect(await audit(guardian), 'a refused start').toEqual([])

      // Edit state, remove dialog, a reply refused because the discussion was resolved elsewhere.
      const mine = await ownThread(guardian, 'A11y states')
      const second = await reply(guardian, mine.id, 'A reply to work on')
      await guardian.goto(`/discussions/${mine.id}`)
      await expect(guardian.getByRole('article', { name: /^Message 2 by/ })).toBeVisible()
      await guardian.getByRole('button', { name: 'Edit your message 2' }).click()
      await expect(guardian.getByRole('textbox', { name: 'Your message' })).toBeVisible()
      expect(await audit(guardian), 'editing').toEqual([])
      await guardian.getByRole('textbox', { name: 'Your message' }).fill('   ')
      await guardian.getByRole('button', { name: 'Save', exact: true }).click()
      await expect(guardian.getByRole('textbox', { name: 'Your message' })).toBeVisible()
      expect(await audit(guardian), 'a refused edit').toEqual([])
      await guardian.getByRole('button', { name: 'Cancel' }).first().click()

      await guardian.getByRole('button', { name: 'Remove your message 2' }).click()
      await expect(guardian.getByRole('dialog', { name: 'Remove your message?' })).toBeVisible()
      expect(await audit(guardian), 'the remove dialog').toEqual([])
      await guardian.keyboard.press('Escape')

      await guardian.getByRole('textbox', { name: 'Your reply' }).fill('A reply that will be late')
      await apiFrom(guardian, 'POST', `/api/v1/admin/discussions/${mine.id}/resolve`) // resolved "elsewhere"
      await guardian.getByRole('button', { name: 'Post reply' }).click()
      await expect(guardian.getByText(/was resolved, so the reply was not added/)).toBeVisible()
      expect(await audit(guardian), 'a refused reply').toEqual([])
      expect(second.id).not.toBe('')
      await guardian.context().close()
    })
  })
}

test.describe('keyboard and focus', () => {
  test('a whole discussion can be started, answered, corrected, resolved and withdrawn from without a mouse', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL, 'plain-guardian')
    const tag = crypto.randomUUID().slice(0, 8)
    const title = `E2E Keys ${tag}`

    // The filters and search: type, Enter; choose a state with the arrow keys.
    await guardian.goto('/discussions')
    const search = guardian.getByRole('searchbox', { name: 'Search titles' })
    await search.focus()
    await guardian.keyboard.type('Room hire')
    await guardian.keyboard.press('Enter')
    await expect(
      guardian.getByRole('table', { name: 'Discussions' }).getByRole('rowheader'),
    ).toHaveCount(1)
    const show = guardian.getByRole('combobox', { name: 'Show' })
    await show.focus()
    await guardian.keyboard.press('ArrowDown') // Open
    await expect(guardian.getByText('No discussions match.')).toBeVisible()
    await guardian.keyboard.press('ArrowDown') // Resolved
    await expect(
      guardian.getByRole('table', { name: 'Discussions' }).getByRole('link', { name: RESOLVED }),
    ).toBeVisible()

    // Start: the form is reached and sent from the keyboard.
    await guardian.goto('/discussions/new')
    await guardian.getByRole('textbox', { name: 'Title', exact: true }).focus()
    await guardian.keyboard.type(title)
    await guardian.keyboard.press('Tab')
    await expect(guardian.getByRole('textbox', { name: 'Opening message' })).toBeFocused()
    await guardian.keyboard.type(`Opening ${tag}`)
    await guardian.keyboard.press('Tab')
    await expect(guardian.getByRole('button', { name: 'Start discussion' })).toBeFocused()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByRole('heading', { level: 1, name: title })).toBeVisible()

    // Reply: type, Tab to the button, Enter. The box is emptied and the reply is shown.
    const box = guardian.getByRole('textbox', { name: 'Your reply' })
    await box.focus()
    await guardian.keyboard.type(`Keyboard reply ${tag}`)
    await guardian.keyboard.press('Tab')
    await expect(guardian.getByRole('button', { name: 'Post reply' })).toBeFocused()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByRole('article', { name: /^Message 2 by/ })).toContainText(
      `Keyboard reply ${tag}`,
    )
    await expect(box).toHaveValue('')

    // Edit: Enter on the button opens the form; keyboard reaches Save; focus is somewhere real afterwards, never nowhere.
    const edit = guardian.getByRole('button', { name: 'Edit your message 2' })
    await edit.focus()
    await guardian.keyboard.press('Enter')
    const editBox = guardian.getByRole('textbox', { name: 'Your message' })
    await expect(editBox).toBeVisible()
    await editBox.focus()
    await guardian.keyboard.press('ControlOrMeta+a')
    await guardian.keyboard.type(`Keyboard reply, edited ${tag}`)
    await guardian.keyboard.press('Tab')
    await expect(guardian.getByRole('button', { name: 'Save', exact: true })).toBeFocused()
    await guardian.keyboard.press('Enter')
    // The form is gone and so is the control that was used: the outcome takes focus, not the top of the page.
    const outcome = (text: string) => guardian.getByRole('status').filter({ hasText: text })
    await expect(outcome('Saved.')).toBeFocused()
    await expect(guardian.getByRole('article', { name: /^Message 2 by/ })).toContainText(
      `Keyboard reply, edited ${tag}`,
    )

    // Resolve and reopen by keyboard; focus stays on the control or moves somewhere real.
    const resolve = guardian.getByRole('button', { name: 'Mark as resolved' })
    await resolve.focus()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByText('Marked as resolved.')).toBeVisible()
    await expect(outcome('Marked as resolved.')).toBeFocused()
    const reopen = guardian.getByRole('button', { name: 'Reopen discussion' })
    await reopen.focus()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByRole('textbox', { name: 'Your reply' })).toBeVisible()
    await expect(outcome('Reopened.')).toBeFocused()

    // Remove: the dialog takes focus, keeps it inside while Tab is pressed, Escape gives it back; then it is done by keyboard.
    const remove = guardian.getByRole('button', { name: 'Remove your message 2' })
    await remove.focus()
    await guardian.keyboard.press('Enter')
    const dialog = guardian.getByRole('dialog', { name: 'Remove your message?' })
    await expect(dialog).toBeVisible()
    // The browser traps focus in a modal <dialog>: Tab wraps within it (through the browser's own chrome, where nothing on the page
    // is focused), and never reaches the page behind. So focus is always in the dialog or on no page element at all.
    for (let n = 0; n < 6; n++) {
      await guardian.keyboard.press('Tab')
      const behind = await dialog.evaluate((el) => {
        const now = (globalThis as unknown as Focus).document.activeElement
        const inside = (el as unknown as { contains: (n: unknown) => boolean }).contains(now)
        return !inside && now !== null && now.tagName.toLowerCase() !== 'body'
      })
      expect(behind, `Tab ${String(n + 1)} reaches a control behind the dialog`).toBe(false)
    }
    await guardian.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(remove).toBeFocused()
    await guardian.keyboard.press('Enter')
    await expect(dialog).toBeVisible()
    await dialog.getByRole('button', { name: 'Remove message' }).focus()
    await guardian.keyboard.press('Enter')
    await expect(guardian.getByRole('article', { name: 'Message 2, removed' })).toBeVisible()
    await expect(outcome('Your message was removed.')).toBeFocused()

    await guardian.context().close()
  })
})

interface Dom {
  document: { documentElement: { scrollWidth: number; clientWidth: number } }
}
interface Focusable {
  tagName: string
  textContent: string | null
  getAttribute: (name: string) => string | null
}
interface Focus {
  document: { activeElement: Focusable | null }
}
const sideways = (page: Page): Promise<number> =>
  page.evaluate(() => {
    const root = (globalThis as unknown as Dom).document.documentElement
    return root.scrollWidth - root.clientWidth
  })

async function insideViewport(locator: Locator, width: number): Promise<boolean> {
  const box = await locator.boundingBox()
  return box !== null && box.x >= 0 && box.x + box.width <= width + 0.5
}

for (const width of [320, 375]) {
  test(`the interactive Discussions states do not scroll sideways at ${String(width)}px`, async ({
    browser,
    baseURL,
  }) => {
    const guardian = await guardianOn(browser, baseURL, 'plain-guardian')
    await guardian.setViewportSize({ width, height: 800 })
    const ids = await demoIds(guardian)
    const unbroken = 'Unbroken'.repeat(14)
    const long = `${unbroken}${unbroken}${unbroken} ${'a long sentence of ordinary words '.repeat(12)}`
    const mine = await ownThread(guardian, 'Layout', {
      title: `E2E Layout ${unbroken} ${crypto.randomUUID().slice(0, 8)}`,
      opening: long,
    })
    const second = await reply(guardian, mine.id, long)
    const third = await reply(guardian, mine.id, 'To be removed')
    await apiFrom(guardian, 'DELETE', `/api/v1/admin/discussions/${mine.id}/messages/${third.id}`)
    const check = async (what: string) => {
      await guardian.waitForLoadState('networkidle')
      expect(await sideways(guardian), `${what} at ${String(width)}px`).toBeLessThanOrEqual(0)
    }

    // The demo, read: the list over real rows, a long thread's page 2, the unknown author, the tombstone.
    for (const route of [
      '/discussions',
      `/discussions/${ids[PAGING] ?? ''}`,
      `/discussions/${ids[UNKNOWN_AUTHOR] ?? ''}`,
      `/discussions/${ids[RESOLVED] ?? ''}`,
    ]) {
      await guardian.goto(route)
      await expect(guardian.getByRole('heading', { level: 1 })).toBeVisible()
      await check(route)
    }
    await guardian.goto(`/discussions/${ids[PAGING] ?? ''}`)
    await guardian.getByRole('button', { name: 'Next' }).click()
    await expect(guardian.getByRole('navigation', { name: 'Pages' })).toContainText('Page 2 of 2')
    await check('page 2 of a long thread')

    // A thread with long unbroken words: the page, the edit form, the title form, the dialog, a refused start, a refused reply.
    await guardian.goto(`/discussions/${mine.id}`)
    await expect(guardian.getByRole('article', { name: 'Message 3, removed' })).toBeVisible()
    await check('a long thread with a tombstone')
    await guardian.getByRole('button', { name: `Edit your message 2` }).click()
    await expect(guardian.getByRole('textbox', { name: 'Your message' })).toBeVisible()
    await check('editing a long message')
    await guardian.getByRole('button', { name: 'Cancel' }).first().click()
    await guardian.getByRole('button', { name: 'Edit title' }).click()
    await expect(guardian.getByRole('textbox', { name: 'Title', exact: true })).toBeVisible()
    await check('editing a long title')
    await guardian.getByRole('button', { name: 'Cancel' }).first().click()
    await guardian.getByRole('button', { name: 'Remove your message 2' }).click()
    const dialog = guardian.getByRole('dialog', { name: 'Remove your message?' })
    await expect(dialog).toBeVisible()
    await check('the remove dialog')
    expect(await insideViewport(dialog, width), 'the dialog fits the screen').toBe(true)
    await guardian.keyboard.press('Escape')
    await guardian.getByRole('textbox', { name: 'Your reply' }).fill(long)
    await check('a long unsent reply')
    await apiFrom(guardian, 'POST', `/api/v1/admin/discussions/${mine.id}/resolve`)
    await guardian.getByRole('button', { name: 'Post reply' }).click()
    await expect(guardian.getByText(/was resolved, so the reply was not added/)).toBeVisible()
    await check('a refused reply')

    await guardian.goto('/discussions/new')
    await guardian.getByRole('textbox', { name: 'Title', exact: true }).fill('   ')
    await guardian.getByRole('textbox', { name: 'Opening message' }).fill(long)
    await guardian.getByRole('button', { name: 'Start discussion' }).click()
    await expect(guardian.locator('[role="alert"], [aria-invalid="true"]').first()).toBeVisible()
    await check('a refused start')

    expect(second.id).not.toBe('')
    await guardian.context().close()
  })
}
