import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { shown } from '../../admin/time.ts'
import { operator, serveOperator } from '../../test/admin.ts'
import { expectNoAxeViolations } from '../../test/a11y.ts'
import {
  DISCUSSION_ID,
  discussionNotFound,
  discussionResolvedConflict,
  messageNotFound,
  messageRemovedConflict,
  invalidDiscussionInput,
  MESSAGE2_ID,
  MESSAGE3_ID,
  messagesPage,
  ME,
  notAuthor,
  OTHER,
  resolvedDiscussion,
  wireDiscussion,
  wireMessage,
  wireTombstone,
} from '../../test/discussions.ts'
import { json, type FakeApi } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const VIEW = ['console.access', 'discussions.view']
const PARTICIPATE = ['console.access', 'discussions.view', 'discussions.participate']

const base = `/api/v1/admin/discussions/${DISCUSSION_ID}`
const HEADER = `GET ${base}` as const
const MESSAGES = `GET ${base}/messages` as const
const REPLY = `POST ${base}/messages` as const
const RETITLE = `PATCH ${base}` as const
const RESOLVE = `POST ${base}/resolve` as const
const REOPEN = `POST ${base}/reopen` as const
const EDIT = (id: string) => `PATCH ${base}/messages/${id}` as const
const REMOVE = (id: string) => `DELETE ${base}/messages/${id}` as const

/** What the fake server currently holds. A handler changes it, and the next read shows it, as the real one would. */
interface World {
  discussion: Record<string, unknown>
  messages: Record<string, unknown>[]
}

const settled = () =>
  waitFor(() => {
    expect(document.querySelector('[aria-busy="true"]')).toBeNull()
  })

const pageText = () => document.querySelector('[data-page-width]')?.textContent ?? ''

const mine = (overrides: Record<string, unknown> = {}) => wireMessage({ author: ME, ...overrides })

async function openThread(
  options: {
    capabilities?: string[]
    discussion?: Record<string, unknown>
    messages?: Record<string, unknown>[]
  } = {},
) {
  const user = userEvent.setup()
  const world: World = {
    discussion: options.discussion ?? wireDiscussion(),
    messages: options.messages ?? [
      wireMessage(),
      mine({ id: MESSAGE2_ID, sequence: 2, body: 'My own reply.' }),
      wireMessage({ id: MESSAGE3_ID, sequence: 3, body: 'Hone again.' }),
    ],
  }
  const api = serveOperator(operator(options.capabilities ?? PARTICIPATE))
  api.on(HEADER, () => json(world.discussion))
  api.on(MESSAGES, () => json(messagesPage(world.messages)))
  renderApp(`/discussions/${DISCUSSION_ID}`)
  await screen.findByRole('heading', { level: 1, name: String(world.discussion.title) })
  await settled()
  return { user, api, world }
}

const articles = () =>
  within(screen.getByRole('list', { name: 'Messages' })).getAllByRole('article')
const article = (name: RegExp | string) =>
  within(screen.getByRole('list', { name: 'Messages' })).getByRole('article', { name })
const buttonNames = () =>
  screen.getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent)

/** The requests that changed something. */
/** The item at `index`, or a failure that says so (the repository allows neither a cast nor a non-null assertion). */
function nth<T>(list: T[], index: number): T {
  const item = list[index]
  if (item === undefined) throw new Error(`There is no item ${String(index)}.`)
  return item
}

const writes = (api: FakeApi) => api.calls.filter((c) => c.method !== 'GET')

describe('reading a discussion', () => {
  it('shows the title, the state in words and who started it', async () => {
    await openThread()

    expect(screen.getByRole('heading', { level: 1, name: 'Where do we meet?' })).toBeInTheDocument()
    expect(pageText()).toContain('Open')
    expect(pageText()).toContain('Started by Hone Guardian')
  })

  it("lists the messages in the server's sequence order, flat, whatever the times say", async () => {
    await openThread({
      messages: [
        wireMessage({ created_at: '2026-10-05T00:00:00Z', body: 'First by sequence' }),
        wireMessage({
          id: MESSAGE2_ID,
          sequence: 2,
          created_at: '2026-10-01T00:00:00Z',
          body: 'Second by sequence',
        }),
        wireMessage({
          id: MESSAGE3_ID,
          sequence: 3,
          created_at: '2026-10-03T00:00:00Z',
          body: 'Third by sequence',
        }),
      ],
    })

    const items = articles()
    expect(items.map((a) => a.textContent)).toEqual([
      expect.stringContaining('First by sequence'),
      expect.stringContaining('Second by sequence'),
      expect.stringContaining('Third by sequence'),
    ])
    expect(items[0]).toHaveTextContent('Opening message')
    expect(items[1]).toHaveTextContent('Message 2')
    // Flat: one list, nothing nested, and nothing that answers one message to another.
    const list = screen.getByRole('list', { name: 'Messages' })
    expect(list.querySelector('ol ol, ul ul, ol ul, ul ol')).toBeNull()
  })

  it('shows who wrote each message and when', async () => {
    await openThread({
      messages: [wireMessage({ created_at: '2026-10-01T09:30:00Z' })],
    })

    const first = nth(articles(), 0)
    expect(first).toHaveTextContent('Hone Guardian')
    expect(within(first).getByText(shown('2026-10-01T09:30:00Z'))).toHaveAttribute(
      'datetime',
      '2026-10-01T09:30:00Z',
    )
  })

  it('says in words that a message was edited, and when, and never suggests a history', async () => {
    await openThread({
      messages: [
        wireMessage(),
        mine({ id: MESSAGE2_ID, sequence: 2, edited_at: '2026-10-02T12:00:00Z', edited_by: ME }),
      ],
    })

    const edited = article(/Message 2/)
    expect(edited).toHaveTextContent('Edited')
    expect(within(edited).getByText(shown('2026-10-02T12:00:00Z'))).toBeInTheDocument()
    expect(within(nth(articles(), 0)).queryByText(/Edited/)).not.toBeInTheDocument()
    expect(pageText()).not.toMatch(/history|previous version|revision|original text/i)
  })

  it('shows an author Identity no longer holds as an unknown person, never as an id', async () => {
    await openThread({
      messages: [
        wireMessage({ author: { id: '01J0000000000000000GHOST001', display_name: null } }),
      ],
    })

    expect(articles()[0]).toHaveTextContent('Unknown person')
    expect(pageText()).not.toContain('GHOST001')
  })

  it('keeps a removed message in its place as a placeholder, with none of what it said', async () => {
    await openThread({
      messages: [
        wireMessage(),
        wireTombstone({ removed_at: '2026-10-01T11:00:00Z' }),
        wireMessage({ id: MESSAGE3_ID, sequence: 3, body: 'After the gap.' }),
      ],
    })

    const items = articles()
    expect(items).toHaveLength(3) // the place is kept: the chronology still makes sense
    const tombstone = nth(items, 1)
    expect(tombstone).toHaveAccessibleName('Message 2, removed')
    expect(tombstone).toHaveTextContent('This message was removed.')
    expect(tombstone).toHaveTextContent('Message 2 by Hone Guardian')
    expect(tombstone).toHaveTextContent('Removed')
    expect(within(tombstone).getByText(shown('2026-10-01T11:00:00Z'))).toBeInTheDocument()
    // Not an empty card that looks like a loading bug, and nothing to restore.
    expect(tombstone.textContent.trim()).not.toBe('')
    expect(within(tombstone).queryByRole('button')).not.toBeInTheDocument()
    expect(pageText()).not.toMatch(/restore|undo|recover/i)
  })

  it('shows a resolved discussion as resolved, by whom and when, and as readable rather than frozen or archived', async () => {
    await openThread({ discussion: resolvedDiscussion({ resolved_by: OTHER }) })

    expect(pageText()).toContain('Resolved')
    expect(pageText()).toContain('Resolved by Hone Guardian')
    expect(screen.getByText(shown('2026-10-03T10:00:00Z'))).toHaveAttribute(
      'datetime',
      '2026-10-03T10:00:00Z',
    )
    expect(pageText()).toContain('It takes no new replies, but it stays here to read.')
    expect(pageText()).not.toMatch(/archiv|frozen|locked|closed for good/i)
    expect(articles()).toHaveLength(3) // still all there to read
  })

  it('shows an unknown resolver as an unknown person', async () => {
    await openThread({
      discussion: resolvedDiscussion({
        resolved_by: { id: '01J0000000000000000GHOST002', display_name: null },
      }),
    })

    expect(pageText()).toContain('Resolved by Unknown person')
    expect(pageText()).not.toContain('GHOST002')
  })

  it('pages the messages by the server, with a labelled pager', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(HEADER, json(wireDiscussion()))
    api.on(MESSAGES, (call) =>
      json(
        new URL(call.path, 'http://x').searchParams.get('page') === '2'
          ? messagesPage([wireMessage({ id: MESSAGE3_ID, sequence: 26, body: 'On page two.' })], {
              page: 2,
              total: 26,
              last_page: 2,
            })
          : messagesPage([wireMessage()], { page: 1, total: 26, last_page: 2 }),
      ),
    )
    renderApp(`/discussions/${DISCUSSION_ID}`)
    await screen.findByRole('list', { name: 'Messages' })

    const pager = screen.getByRole('navigation', { name: 'Pages' })
    expect(pager).toHaveTextContent('Page 1 of 2 (26 messages)')
    await user.click(within(pager).getByRole('button', { name: 'Next' }))
    expect(await screen.findByText('On page two.')).toBeInTheDocument()
  })

  it('says plainly when the discussion is not there, and offers the way back', async () => {
    const api = serveOperator(operator(VIEW))
    api.on(HEADER, discussionNotFound())
    renderApp(`/discussions/${DISCUSSION_ID}`)

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That discussion could not be found.',
    )
    expect(screen.getByRole('link', { name: 'Back to discussions' })).toHaveAttribute(
      'href',
      '/discussions',
    )
  })

  it('says plainly that the messages could not load, and asks again on request', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW))
    api.on(HEADER, json(wireDiscussion()))
    api.on(MESSAGES, json({ message: 'SQLSTATE secret' }, 500))
    renderApp(`/discussions/${DISCUSSION_ID}`)

    const alert = await screen.findByRole('alert')
    expect(alert).not.toHaveTextContent('SQLSTATE')
    api.on(MESSAGES, json(messagesPage([wireMessage()])))
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('list', { name: 'Messages' })).toBeInTheDocument()
  })
})

describe('what someone who may only read can do', () => {
  it('reads everything and is offered no way to change anything', async () => {
    await openThread({
      capabilities: VIEW,
      messages: [wireMessage(), mine({ id: MESSAGE2_ID, sequence: 2 })],
    })

    expect(articles()).toHaveLength(2)
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument() // no reply form
    expect(buttonNames().filter((n) => /resolve|reopen|edit|remove|reply|post/i.test(n))).toEqual(
      [],
    )
  })

  it('is offered no reply form on a resolved discussion either, and no explanation aimed at someone who could reply', async () => {
    await openThread({ capabilities: VIEW, discussion: resolvedDiscussion() })

    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(screen.queryByText('Replies are closed')).not.toBeInTheDocument()
  })
})

describe('replying', () => {
  it('offers a simple reply form on an open discussion, with nothing to choose but the words', async () => {
    await openThread()

    const form = screen.getByRole('form', { name: 'Reply to this discussion' })
    expect(within(form).getAllByRole('textbox')).toHaveLength(1)
    expect(within(form).queryByRole('combobox')).not.toBeInTheDocument()
    expect(within(form).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(form).not.toHaveTextContent(/recipient|author|private|attach|parent/i)
  })

  it('sends only the text, shows the new message at the end and clears the form', async () => {
    const { user, api, world } = await openThread()
    api.on(REPLY, () => {
      world.messages.push(
        mine({ id: '01J00000000000000000MSG004', sequence: 4, body: 'A fresh reply' }),
      )
      return json(world.messages[3], 201)
    })

    await user.type(screen.getByRole('textbox', { name: 'Your reply' }), 'A fresh reply')
    await user.click(screen.getByRole('button', { name: 'Post reply' }))

    expect(await screen.findByText('A fresh reply')).toBeInTheDocument()
    expect(api.callsTo(REPLY)[0]?.body).toEqual({ body: 'A fresh reply' }) // never an author
    expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue('')
    expect(screen.getByRole('status', { name: '' })).toBeDefined()
    expect(pageText()).toContain('Reply posted.')
    // ...but a reply leaves focus where it is: the box is still there for the next one.
    expect(screen.getByText('Reply posted.').closest('[role="status"]')).not.toHaveFocus()
    expect(articles()).toHaveLength(4)
  })

  it("shows the server's validation beside the field and keeps what was typed", async () => {
    const { user, api } = await openThread()
    api.on(REPLY, invalidDiscussionInput('body', 'A message may not contain control characters.'))

    await user.type(screen.getByRole('textbox', { name: 'Your reply' }), 'Some words')
    await user.click(screen.getByRole('button', { name: 'Post reply' }))

    await waitFor(() => {
      expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveAccessibleDescription(
        'A message may not contain control characters.',
      )
    })
    expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue('Some words')
  })

  it('replaces the form with a plain explanation once the discussion is resolved, rather than letting it vanish', async () => {
    await openThread({ discussion: resolvedDiscussion() })

    expect(screen.queryByRole('textbox', { name: 'Your reply' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Post reply' })).not.toBeInTheDocument()
    const panel = screen.getByRole('region', { name: 'Replies are closed' })
    expect(panel).toHaveTextContent('This discussion is resolved, so it takes no new replies.')
    expect(panel).toHaveTextContent('Reopen it to carry on')
  })

  it('refuses the reply, says so, re-reads the discussion and keeps the draft when it was resolved while the reply was being written', async () => {
    const { user, api, world } = await openThread()
    api.on(REPLY, () => {
      world.discussion = resolvedDiscussion() // someone else resolved it meanwhile
      return discussionResolvedConflict()
    })
    api.on(REOPEN, () => {
      world.discussion = wireDiscussion()
      return json(world.discussion)
    })

    await user.type(screen.getByRole('textbox', { name: 'Your reply' }), 'My careful reply')
    await user.click(screen.getByRole('button', { name: 'Post reply' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This discussion was resolved, so the reply was not added. Reopen it to carry on.',
    )
    // The page now says what is true: resolved, replies closed, and the unsent words are kept.
    expect(await screen.findByRole('region', { name: 'Replies are closed' })).toHaveTextContent(
      'The reply you had started is kept.',
    )
    expect(pageText()).toContain('Resolved')
    expect(articles()).toHaveLength(3) // the reply was NOT added

    // Reopening brings the form back with the words still in it.
    await user.click(screen.getByRole('button', { name: 'Reopen discussion' }))
    expect(await screen.findByRole('textbox', { name: 'Your reply' })).toHaveValue(
      'My careful reply',
    )
  })
})

describe('editing your own message', () => {
  it("offers Edit and Remove on the messages the signed-in person wrote, and on no one else's", async () => {
    await openThread()

    expect(
      within(article(/Message 2/)).getByRole('button', { name: 'Edit your message 2' }),
    ).toBeInTheDocument()
    expect(
      within(article(/Message 2/)).getByRole('button', { name: 'Remove your message 2' }),
    ).toBeInTheDocument()
    for (const other of [nth(articles(), 0), article(/Message 3/)]) {
      expect(within(other).queryByRole('button')).not.toBeInTheDocument()
    }
  })

  it('tells whose message it is by Person id, never by name: a stranger with the same name gets no controls', async () => {
    await openThread({
      messages: [
        wireMessage({
          author: { id: '01J000000000000000IMPOSTOR1', display_name: ME.display_name },
        }),
        mine({ id: MESSAGE2_ID, sequence: 2 }),
      ],
    })

    expect(within(nth(articles(), 0)).queryByRole('button')).not.toBeInTheDocument()
    expect(
      within(nth(articles(), 1)).getByRole('button', { name: 'Edit your message 2' }),
    ).toBeInTheDocument()
  })

  it('gives no controls on an author Identity no longer holds, or on a tombstone', async () => {
    await openThread({
      messages: [
        wireMessage({ author: { id: '01J0000000000000000GHOST001', display_name: null } }),
        wireTombstone({ author: ME }),
      ],
    })

    for (const item of articles())
      expect(within(item).queryByRole('button')).not.toBeInTheDocument()
  })

  it('still lets an author edit in a resolved discussion', async () => {
    const { user } = await openThread({ discussion: resolvedDiscussion() })

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    expect(screen.getByRole('textbox', { name: 'Your message' })).toHaveValue('My own reply.')
  })

  it('edits only the text: sends only the body, then shows the new text marked as edited', async () => {
    const { user, api, world } = await openThread()
    api.on(EDIT(MESSAGE2_ID), () => {
      world.messages[1] = mine({
        id: MESSAGE2_ID,
        sequence: 2,
        body: 'A corrected reply.',
        edited_at: '2026-10-04T08:00:00Z',
        edited_by: ME,
      })
      return json(world.messages[1])
    })

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    const box = screen.getByRole('textbox', { name: 'Your message' })
    await user.clear(box)
    await user.type(box, 'A corrected reply.')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('A corrected reply.')).toBeInTheDocument()
    // Exactly the body: not the author, the sequence, the time or the discussion.
    expect(api.callsTo(EDIT(MESSAGE2_ID))[0]?.body).toEqual({ body: 'A corrected reply.' })
    expect(article(/Message 2/)).toHaveTextContent('Edited')
    expect(screen.queryByRole('form', { name: /Edit your message/ })).not.toBeInTheDocument()
    expect(within(article(/Message 2/)).getByText(/Hone|Gwen/)).toHaveTextContent('Gwen Guardian') // the author is unchanged
    // The control that was used has gone or is disabled, so the outcome takes keyboard focus rather than dropping it to the page top.
    await waitFor(() => {
      expect(screen.getByText('Saved.').closest('[role="status"]')).toHaveFocus()
    })
  })

  it('sends nothing at all when the text is as it was', async () => {
    const { user, api } = await openThread()

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await user.type(screen.getByRole('textbox', { name: 'Your message' }), '   ')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(writes(api)).toEqual([])
    expect(screen.queryByRole('form', { name: /Edit your message/ })).not.toBeInTheDocument()
  })

  it('cancels without sending anything', async () => {
    const { user, api } = await openThread()

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await user.type(screen.getByRole('textbox', { name: 'Your message' }), ' changed')
    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(writes(api)).toEqual([])
    expect(screen.getByText('My own reply.')).toBeInTheDocument()
  })

  it("shows the server's validation beside the field and keeps the form open", async () => {
    const { user, api } = await openThread()
    api.on(EDIT(MESSAGE2_ID), invalidDiscussionInput('body', 'Write something to post.'))

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await user.clear(screen.getByRole('textbox', { name: 'Your message' }))
    await user.type(screen.getByRole('textbox', { name: 'Your message' }), 'x')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(screen.getByRole('textbox', { name: 'Your message' })).toHaveAccessibleDescription(
        'Write something to post.',
      )
    })
  })

  it('closes the form, says so and shows the tombstone when the message was removed while it was being edited', async () => {
    const { user, api, world } = await openThread()
    api.on(EDIT(MESSAGE2_ID), () => {
      world.messages[1] = wireTombstone({ author: ME })
      return messageRemovedConflict()
    })

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await user.type(screen.getByRole('textbox', { name: 'Your message' }), ' more')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'That message has been removed, so it can no longer be changed.',
    )
    expect(await screen.findByText('This message was removed.')).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: /Edit your message/ })).not.toBeInTheDocument()
  })

  it('says it is not theirs to change when the server refuses with not_author', async () => {
    const { user, api } = await openThread()
    api.on(EDIT(MESSAGE2_ID), notAuthor())

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await user.type(screen.getByRole('textbox', { name: 'Your message' }), ' more')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Only the person who wrote that can change it.',
    )
  })

  it('says the message is gone when it cannot be found', async () => {
    const { user, api } = await openThread()
    api.on(EDIT(MESSAGE2_ID), messageNotFound())

    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await user.type(screen.getByRole('textbox', { name: 'Your message' }), ' more')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('That message could not be found.')
  })
})

describe('removing your own message', () => {
  async function openRemoving(discussion?: Record<string, unknown>) {
    const world = await openThread(discussion === undefined ? {} : { discussion })
    await world.user.click(screen.getByRole('button', { name: 'Remove your message 2' }))
    return { ...world, dialog: await screen.findByRole('dialog', { name: 'Remove your message?' }) }
  }

  it('asks first, says what will happen and that it cannot be undone, and names the message', async () => {
    const { dialog } = await openRemoving()

    expect(dialog).toHaveTextContent('The text will be removed for everyone.')
    expect(dialog).toHaveTextContent(
      'A placeholder, “This message was removed.”, stays in its place',
    )
    expect(dialog).toHaveTextContent('This cannot be undone.')
    expect(dialog).toHaveTextContent('My own reply.') // which one
    expect(dialog).not.toHaveTextContent(/restore|trash|archive|recover/i)
  })

  it('makes Cancel the safe path: it is the next stop after the dialog opens, and cancelling sends nothing', async () => {
    const { user, api, dialog } = await openRemoving()

    // The dialog takes focus first; the very next stop is Cancel, never the destructive button. (Escape and the return of focus
    // belong to the browser's own <dialog>, which jsdom does not run: they are proved in the real-browser journey.)
    await user.tab()
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus()
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(writes(api)).toEqual([])
    expect(screen.getByText('My own reply.')).toBeInTheDocument()
  })

  it('removes it, keeps its place as a placeholder and shows none of the old text', async () => {
    const { user, api, world, dialog } = await openRemoving()
    api.on(REMOVE(MESSAGE2_ID), () => {
      world.messages[1] = wireTombstone({ author: ME, removed_at: '2026-10-04T09:00:00Z' })
      return json(world.messages[1])
    })

    await user.click(within(dialog).getByRole('button', { name: 'Remove message' }))

    expect(await screen.findByText('This message was removed.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(articles()).toHaveLength(3) // the place is kept
    expect(screen.queryByText('My own reply.')).not.toBeInTheDocument()
    expect(pageText()).toContain('Your message was removed.')
    expect(within(article(/Message 2, removed/)).queryByRole('button')).not.toBeInTheDocument() // and there is no undo
    // The control that was used has gone or is disabled, so the outcome takes keyboard focus rather than dropping it to the page top.
    await waitFor(() => {
      expect(screen.getByText('Your message was removed.').closest('[role="status"]')).toHaveFocus()
    })
    expect(api.callsTo(REMOVE(MESSAGE2_ID))).toHaveLength(1)
  })

  it('still lets an author remove in a resolved discussion', async () => {
    const { user, api, world, dialog } = await openRemoving(resolvedDiscussion())
    api.on(REMOVE(MESSAGE2_ID), () => {
      world.messages[1] = wireTombstone({ author: ME })
      return json(world.messages[1])
    })

    await user.click(within(dialog).getByRole('button', { name: 'Remove message' }))
    expect(await screen.findByText('This message was removed.')).toBeInTheDocument()
  })

  it('keeps the dialog open with a plain message when the removal fails', async () => {
    const { user, api, dialog } = await openRemoving()
    api.on(REMOVE(MESSAGE2_ID), json({ message: 'SQLSTATE secret' }, 500))

    await user.click(within(dialog).getByRole('button', { name: 'Remove message' }))

    const alert = await within(dialog).findByRole('alert')
    expect(alert).toHaveTextContent('temporarily unavailable')
    expect(dialog).not.toHaveTextContent('SQLSTATE')
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('closes the dialog and says so when the message cannot be found any more', async () => {
    const { user, api, dialog } = await openRemoving()
    api.on(REMOVE(MESSAGE2_ID), messageNotFound())

    await user.click(within(dialog).getByRole('button', { name: 'Remove message' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('That message could not be found.')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})

describe('correcting the title', () => {
  it('is offered to the person who started the discussion, and to no other participant', async () => {
    await openThread({ discussion: wireDiscussion({ creator: ME }) })
    expect(screen.getByRole('button', { name: 'Edit title' })).toBeInTheDocument()
  })

  it('is not offered to another participant, though they may resolve', async () => {
    await openThread() // started by Hone, signed in as Gwen
    expect(screen.queryByRole('button', { name: 'Edit title' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Mark as resolved' })).toBeInTheDocument()
  })

  it('is not offered to someone who may only read, even if they started it', async () => {
    await openThread({ capabilities: VIEW, discussion: wireDiscussion({ creator: ME }) })
    expect(screen.queryByRole('button', { name: 'Edit title' })).not.toBeInTheDocument()
  })

  it('tells the creator by Person id, not by name', async () => {
    await openThread({
      discussion: wireDiscussion({
        creator: { id: '01J000000000000000IMPOSTOR1', display_name: ME.display_name },
      }),
    })
    expect(screen.queryByRole('button', { name: 'Edit title' })).not.toBeInTheDocument()
  })

  it('sends only the title, and shows the new one with a plain confirmation', async () => {
    const { user, api, world } = await openThread({ discussion: wireDiscussion({ creator: ME }) })
    api.on(RETITLE, () => {
      world.discussion = wireDiscussion({ creator: ME, title: 'Where shall we meet?' })
      return json(world.discussion)
    })

    await user.click(screen.getByRole('button', { name: 'Edit title' }))
    const box = screen.getByRole('textbox', { name: 'Title' })
    await user.clear(box)
    await user.type(box, 'Where shall we meet?')
    await user.click(screen.getByRole('button', { name: 'Save title' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Where shall we meet?' }),
    ).toBeInTheDocument()
    expect(api.callsTo(RETITLE)[0]?.body).toEqual({ title: 'Where shall we meet?' })
    expect(pageText()).toContain('Title saved.')
    // The control that was used has gone or is disabled, so the outcome takes keyboard focus rather than dropping it to the page top.
    await waitFor(() => {
      expect(screen.getByText('Title saved.').closest('[role="status"]')).toHaveFocus()
    })
    expect(screen.queryByRole('form', { name: 'Edit the title' })).not.toBeInTheDocument()
  })

  it('sends nothing when the title is as it was', async () => {
    const { user, api } = await openThread({ discussion: wireDiscussion({ creator: ME }) })

    await user.click(screen.getByRole('button', { name: 'Edit title' }))
    await user.click(screen.getByRole('button', { name: 'Save title' }))

    expect(writes(api)).toEqual([])
    expect(screen.queryByRole('form', { name: 'Edit the title' })).not.toBeInTheDocument()
  })

  it('is still allowed while the discussion is resolved', async () => {
    const { user } = await openThread({ discussion: resolvedDiscussion({ creator: ME }) })

    await user.click(screen.getByRole('button', { name: 'Edit title' }))
    expect(screen.getByRole('textbox', { name: 'Title' })).toHaveValue('Where do we meet?')
  })

  it("shows the server's validation beside the field", async () => {
    const { user, api } = await openThread({ discussion: wireDiscussion({ creator: ME }) })
    api.on(RETITLE, invalidDiscussionInput('title', 'A title is limited to 200 characters.'))

    await user.click(screen.getByRole('button', { name: 'Edit title' }))
    await user.type(screen.getByRole('textbox', { name: 'Title' }), ' more')
    await user.click(screen.getByRole('button', { name: 'Save title' }))

    await waitFor(() => {
      expect(screen.getByRole('textbox', { name: 'Title' })).toHaveAccessibleDescription(
        'A title is limited to 200 characters.',
      )
    })
  })

  it('says it is not theirs to change when the server refuses with not_author', async () => {
    const { user, api } = await openThread({ discussion: wireDiscussion({ creator: ME }) })
    api.on(RETITLE, notAuthor())

    await user.click(screen.getByRole('button', { name: 'Edit title' }))
    await user.type(screen.getByRole('textbox', { name: 'Title' }), ' more')
    await user.click(screen.getByRole('button', { name: 'Save title' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Only the person who wrote that can change it.',
    )
  })
})

describe('resolving and reopening', () => {
  it('resolves an open discussion: it says Resolved and by whom, and the reply form gives way', async () => {
    const { user, api, world } = await openThread()
    api.on(RESOLVE, () => {
      world.discussion = resolvedDiscussion({ resolved_by: ME })
      return json(world.discussion)
    })

    await user.click(screen.getByRole('button', { name: 'Mark as resolved' }))

    expect(
      await screen.findByText('Resolved by Gwen Guardian', { exact: false }),
    ).toBeInTheDocument()
    expect(pageText()).toContain('Marked as resolved.')
    // The control that was used has gone or is disabled, so the outcome takes keyboard focus rather than dropping it to the page top.
    await waitFor(() => {
      expect(screen.getByText('Marked as resolved.').closest('[role="status"]')).toHaveFocus()
    })
    expect(screen.getByRole('button', { name: 'Reopen discussion' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mark as resolved' })).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: 'Your reply' })).not.toBeInTheDocument()
    expect(api.callsTo(RESOLVE)[0]?.body).toBeNull() // nothing is sent but the request itself
  })

  it('reopens a resolved discussion: replies are possible again', async () => {
    const { user, api, world } = await openThread({ discussion: resolvedDiscussion() })
    api.on(REOPEN, () => {
      world.discussion = wireDiscussion()
      return json(world.discussion)
    })

    await user.click(screen.getByRole('button', { name: 'Reopen discussion' }))

    expect(await screen.findByRole('textbox', { name: 'Your reply' })).toBeInTheDocument()
    expect(pageText()).toContain('Reopened.')
    // The control that was used has gone or is disabled, so the outcome takes keyboard focus rather than dropping it to the page top.
    await waitFor(() => {
      expect(screen.getByText('Reopened.').closest('[role="status"]')).toHaveFocus()
    })
    expect(pageText()).not.toContain('Resolved by')
    expect(screen.getByRole('button', { name: 'Mark as resolved' })).toBeInTheDocument()
  })

  it('is offered to any participant, whoever started the discussion', async () => {
    await openThread() // started by Hone, signed in as Gwen
    expect(screen.getByRole('button', { name: 'Mark as resolved' })).toBeInTheDocument()
  })

  it('says plainly when the change could not be made, and leaves the state as it was', async () => {
    const { user, api } = await openThread()
    api.on(RESOLVE, json({ message: 'SQLSTATE secret' }, 500))

    await user.click(screen.getByRole('button', { name: 'Mark as resolved' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('temporarily unavailable')
    expect(alert).not.toHaveTextContent('SQLSTATE')
    expect(screen.getByRole('button', { name: 'Mark as resolved' })).toBeInTheDocument()
  })
})

describe('what is never offered', () => {
  it('has no nesting, official, moderation, notification, mention, reaction, attachment, privacy or chat controls', async () => {
    await openThread({ discussion: wireDiscussion({ creator: ME }) })

    const names = buttonNames()
    expect(
      names.filter((n) =>
        /nest|official|moderat|notif|subscribe|follow|mention|react|emoji|attach|upload|private|invite|chat|restore|undo|archive|delete|reply to|quote/i.test(
          n,
        ),
      ),
    ).toEqual([])
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    // Every message is a flat record: the only controls on it are the author's own Edit and Remove.
    for (const item of articles()) {
      for (const control of within(item).queryAllByRole('button')) {
        expect(control.getAttribute('aria-label')).toMatch(/^(Edit|Remove) your message \d+$/)
      }
    }
  })

  it('shows no Account or security information anywhere on the thread', async () => {
    await openThread()

    expect(pageText()).not.toMatch(
      /guardian@example\.org|@example\.org|account|password|role|capabilit|mfa|membership/i,
    )
  })
})

describe('accessibility', () => {
  it('has no violation on an open thread with messages, an edited one and a tombstone', async () => {
    await openThread({
      messages: [
        wireMessage(),
        mine({ id: MESSAGE2_ID, sequence: 2, edited_at: '2026-10-02T12:00:00Z', edited_by: ME }),
        wireTombstone({ id: MESSAGE3_ID, sequence: 3 }),
      ],
    })
    await expectNoAxeViolations()
  })

  it('has no violation on a resolved thread', async () => {
    await openThread({ discussion: resolvedDiscussion({ creator: ME }) })
    await expectNoAxeViolations()
  })

  it('has no violation while a message is being edited', async () => {
    const { user } = await openThread()
    await user.click(screen.getByRole('button', { name: 'Edit your message 2' }))
    await expectNoAxeViolations()
  })

  it('has no violation while the title is being edited', async () => {
    const { user } = await openThread({ discussion: wireDiscussion({ creator: ME }) })
    await user.click(screen.getByRole('button', { name: 'Edit title' }))
    await expectNoAxeViolations()
  })

  it('has no violation with the remove dialog open', async () => {
    const { user } = await openThread()
    await user.click(screen.getByRole('button', { name: 'Remove your message 2' }))
    await screen.findByRole('dialog', { name: 'Remove your message?' })
    await expectNoAxeViolations()
  })

  it('can be used entirely from the keyboard: reply, then edit, by Tab and Enter', async () => {
    const { user, api, world } = await openThread()
    api.on(REPLY, () => {
      world.messages.push(
        mine({ id: '01J00000000000000000MSG004', sequence: 4, body: 'Typed by keyboard' }),
      )
      return json(world.messages[3], 201)
    })

    screen.getByRole('textbox', { name: 'Your reply' }).focus()
    await user.keyboard('Typed by keyboard')
    await user.tab()
    expect(screen.getByRole('button', { name: 'Post reply' })).toHaveFocus()
    await user.keyboard('{Enter}')
    expect(await screen.findByText('Typed by keyboard')).toBeInTheDocument()

    screen.getByRole('button', { name: 'Edit your message 2' }).focus()
    await user.keyboard('{Enter}')
    expect(screen.getByRole('textbox', { name: 'Your message' })).toBeInTheDocument()
  })
})
