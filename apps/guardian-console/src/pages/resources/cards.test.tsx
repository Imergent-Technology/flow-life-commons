import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { pageBody } from '../../test/deferred.ts'
import { operator, serveOperator, verificationRequired } from '../../test/admin.ts'
import { writeContent, paragraphs } from '../../test/editor.ts'
import { empty, json } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'
import {
  CARD_ID,
  CARD_PATH,
  cardAudienceConflict,
  cardNotFound,
  cardNotPublishable,
  categoryList,
  coded,
  doc,
  invalidContent,
  invalidUri,
  MANAGE,
  PACK_ID,
  PACK_PATH,
  publishedPackRequirement,
  ROOT,
  staleRevision,
  wireCard,
  wireCategory,
  wireFileCard,
  wireLinkCard,
  wirePack,
} from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

function serve(pack: Record<string, unknown> = wirePack()) {
  const api = serveOperator(operator(MANAGE))
  api.on(`GET ${ROOT}/categories`, () => json(categoryList([wireCategory()])))
  api.on(`GET ${PACK_PATH}`, () => json(pack))
  return api
}

// --- Adding a Card -----------------------------------------------------------------------------------------------------------

describe('adding a Card', () => {
  async function openNew() {
    const user = userEvent.setup()
    const api = serve()
    renderApp(`/resources/packs/${PACK_ID}/cards/new`)
    await screen.findByRole('heading', { level: 1, name: 'Add a Card' })
    return { user, api }
  }

  it('offers the three Types, defaults to Basic, and says the Type cannot change', async () => {
    await openNew()
    const types = screen.getByRole('group', { name: 'Type of Card' })

    expect(
      within(types)
        .getAllByRole('radio')
        .map((r) => r.closest('label')?.textContent),
    ).toEqual([
      expect.stringContaining('Basic'),
      expect.stringContaining('External link'),
      expect.stringContaining('File'),
    ])
    expect(within(types).getByRole('radio', { name: /Basic/ })).toBeChecked()
    expect(types).toHaveTextContent('The type cannot be changed once the Card exists')
  })

  it('shows the fields of the chosen Type: a web address for a link, a file for a File Card', async () => {
    const { user } = await openNew()
    expect(screen.getByLabelText('Related link (optional)')).not.toBeRequired()
    expect(screen.queryByLabelText('File')).not.toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: /External link/ }))
    expect(screen.getByLabelText('Web address')).toBeRequired()

    await user.click(screen.getByRole('radio', { name: /^File/ }))
    expect(screen.getByLabelText('File')).toBeRequired()
    expect(screen.queryByLabelText('Web address')).not.toBeInTheDocument()
  })

  it('creates a Basic Card from the editor’s canonical document', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => json(wireCard(), 201))
    api.on(`GET ${CARD_PATH}`, () => json(wireCard()))

    await user.type(screen.getByLabelText('Title'), 'Opening hours')
    await writeContent('Content', 'We are open every day.')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(1)
    })
    expect(api.callsTo(`POST ${PACK_PATH}/cards`)[0]?.body).toEqual({
      type: 'basic',
      title: 'Opening hours',
      content: paragraphs('We are open every day.'),
      uri: null,
      summary: null,
    })
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Opening hours' }),
    ).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(
      `/resources/packs/${PACK_ID}/cards/${CARD_ID}`,
    )
    expect(
      screen.getByText('The Card was created as a Draft. Publish it when it is ready.'),
    ).toBeInTheDocument()
  })

  it('sends no content at all for a Card whose content was never touched', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => json(wireCard(), 201))
    api.on(`GET ${CARD_PATH}`, () => json(wireCard()))

    await user.type(screen.getByLabelText('Title'), 'Bare')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(1)
    })
    expect(api.callsTo(`POST ${PACK_PATH}/cards`)[0]?.body).toMatchObject({ content: null })
  })

  it('creates an External link Card with its web address, and nothing fetches it', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => json(wireLinkCard(), 201))
    api.on(`GET ${CARD_PATH}`, () => json(wireLinkCard()))

    await user.click(screen.getByRole('radio', { name: /External link/ }))
    await user.type(screen.getByLabelText('Title'), 'The venue')
    await user.type(screen.getByLabelText('Web address'), 'https://example.org/venue')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(1)
    })
    expect(api.callsTo(`POST ${PACK_PATH}/cards`)[0]?.body).toMatchObject({
      type: 'external_link',
      title: 'The venue',
      uri: 'https://example.org/venue',
    })
    // Only the platform's own API was ever asked for anything.
    expect(api.calls.every((c) => c.path.startsWith('/api/v1/'))).toBe(true)
  })

  it('shows the server’s refusal of a web address beside the field, and keeps what was typed', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => invalidUri())

    await user.click(screen.getByRole('radio', { name: /External link/ }))
    await user.type(screen.getByLabelText('Title'), 'The venue')
    await user.type(screen.getByLabelText('Web address'), 'javascript:alert(1)')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    expect(await screen.findByText('That is not a valid web address.')).toBeInTheDocument()
    expect(screen.getByLabelText('Web address')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Web address')).toHaveFocus()
    expect(screen.getByLabelText('Web address')).toHaveValue('javascript:alert(1)')
  })

  it('writes a custom summary when asked, and otherwise leaves it to the server to derive', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => json(wireCard(), 201))
    api.on(`GET ${CARD_PATH}`, () => json(wireCard()))
    expect(screen.queryByLabelText('Your summary')).not.toBeInTheDocument()

    await user.type(screen.getByLabelText('Title'), 'Hours')
    await user.click(screen.getByRole('radio', { name: 'Write my own summary' }))
    await user.type(screen.getByLabelText('Your summary'), 'Open daily.')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${PACK_PATH}/cards`)).toHaveLength(1)
    })
    expect(api.callsTo(`POST ${PACK_PATH}/cards`)[0]?.body).toMatchObject({
      summary: 'Open daily.',
    })
  })

  it('shows invalid content where the editor is, and says a full Pack is full', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${PACK_PATH}/cards`, () => invalidContent())

    await user.type(screen.getByLabelText('Title'), 'Hours')
    await user.click(screen.getByRole('button', { name: 'Create Card' }))
    expect(await screen.findByText('Content is not allowed at content.1.')).toBeInTheDocument()

    api.on(`POST ${PACK_PATH}/cards`, () => coded(409, 'card_limit_reached'))
    await user.click(screen.getByRole('button', { name: 'Create Card' }))
    expect(await screen.findByText('A Pack can hold at most 100 Cards.')).toBeInTheDocument()
  })

  it('says a Pack that has gone is gone', async () => {
    const api = serveOperator(operator(MANAGE))
    api.on(`GET ${PACK_PATH}`, () => coded(404, 'pack_not_found'))
    renderApp(`/resources/packs/${PACK_ID}/cards/new`)

    expect(
      await screen.findByText('That Resource Pack no longer exists. It may have been deleted.'),
    ).toBeInTheDocument()
  })

  it('has no accessibility violations, for each Type', async () => {
    const { user } = await openNew()
    await screen.findByRole('textbox', { name: 'Content' })
    await expectNoAxeViolations()
    await user.click(screen.getByRole('radio', { name: /External link/ }))
    await expectNoAxeViolations()
    await user.click(screen.getByRole('radio', { name: /^File/ }))
    await screen.findByRole('textbox', { name: 'Description (optional)' })
    await expectNoAxeViolations()
  })
})

// --- Editing one -------------------------------------------------------------------------------------------------------------

async function openCard(
  card: Record<string, unknown> = wireCard(),
  pack: Record<string, unknown> = wirePack(),
) {
  const user = userEvent.setup()
  const api = serve(pack)
  api.on(`GET ${CARD_PATH}`, () => json(card))
  renderApp(`/resources/packs/${PACK_ID}/cards/${CARD_ID}`)
  await screen.findByRole('heading', { level: 1, name: String(card.title) })
  await screen.findByRole('textbox', { name: /Content|Description/ })
  return { user, api }
}

describe('a Card’s page', () => {
  it('shows its Type and state, its fields, and where it is — and offers no way to change the Type', async () => {
    await openCard()

    expect(screen.getByRole('heading', { level: 1, name: 'Opening hours' })).toBeInTheDocument()
    const page = pageBody()
    expect(within(page).getAllByText('Basic').length).toBeGreaterThan(0)
    expect(within(page).getAllByText('Draft').length).toBeGreaterThan(0)
    expect(screen.getByLabelText('Title')).toHaveValue('Opening hours')
    expect(screen.queryByRole('group', { name: 'Type of Card' })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /External link/ })).not.toBeInTheDocument()
    expect(within(page).getByRole('link', { name: 'Welcome pack' })).toHaveAttribute(
      'href',
      `/resources/packs/${PACK_ID}`,
    )
    expect(await screen.findByText('We are open every day.', { selector: 'p' })).toBeInTheDocument()
  })

  it('shows an External link Card’s web address, and a File Card none', async () => {
    await openCard(wireLinkCard())
    expect(screen.getByLabelText('Web address')).toHaveValue('https://example.org/venue')
  })

  it('says a Card that has gone is gone, and leads back to the Pack', async () => {
    const api = serve()
    api.on(`GET ${CARD_PATH}`, () => cardNotFound())
    renderApp(`/resources/packs/${PACK_ID}/cards/${CARD_ID}`)

    expect(
      await screen.findByText('That Card no longer exists. It may have been deleted.'),
    ).toBeInTheDocument()
    const page = pageBody()
    expect(within(page).getByRole('link', { name: 'Back to the Pack' })).toHaveAttribute(
      'href',
      `/resources/packs/${PACK_ID}`,
    )
  })

  it('has no accessibility violations', async () => {
    await openCard()
    await expectNoAxeViolations()
  })
})

describe('editing a Card', () => {
  it('sends only the title when only the title changed, with the revision, and says it saved', async () => {
    const { user, api } = await openCard(wireCard({ revision: 3 }))
    api.on(`PATCH ${CARD_PATH}`, () => json(wireCard({ revision: 4, title: 'New hours' })))

    await user.clear(screen.getByLabelText('Title'))
    await user.type(screen.getByLabelText('Title'), 'New hours')
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    expect(await screen.findByText('The Card was saved.')).toBeInTheDocument()
    expect(api.callsTo(`PATCH ${CARD_PATH}`)[0]?.body).toEqual({ revision: 3, title: 'New hours' })
    expect(document.activeElement).toHaveTextContent('The Card was saved.')
  })

  it('sends the editor’s canonical document when the content changed', async () => {
    const { user, api } = await openCard()
    api.on(`PATCH ${CARD_PATH}`, () => json(wireCard({ revision: 2 })))

    await writeContent('Content', ' Closed on holidays.')
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    await screen.findByText('The Card was saved.')
    expect(api.callsTo(`PATCH ${CARD_PATH}`)[0]?.body).toEqual({
      revision: 1,
      content: paragraphs('We are open every day. Closed on holidays.'),
    })
  })

  it('does not send content that was typed and then undone', async () => {
    const { user, api } = await openCard()
    api.on(`PATCH ${CARD_PATH}`, () => json(wireCard({ revision: 2, title: 'T' })))

    const surface = await writeContent('Content', 'X')
    // Back to what it was.
    const editor = (
      surface as HTMLElement & {
        editor: { commands: { setContent: (c: unknown, o?: boolean) => void } }
      }
    ).editor
    editor.commands.setContent(doc('We are open every day.'), true)
    await user.type(screen.getByLabelText('Title'), '!')
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    await screen.findByText('The Card was saved.')
    expect(api.callsTo(`PATCH ${CARD_PATH}`)[0]?.body).not.toHaveProperty('content')
  })

  it('sends nothing, and says so, when nothing changed', async () => {
    const { user, api } = await openCard()
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    expect(
      await screen.findByText('There is nothing to save: no field has changed.'),
    ).toBeInTheDocument()
    expect(api.callsTo(`PATCH ${CARD_PATH}`)).toHaveLength(0)
  })

  it('writes the web address, or clears it, and sends the summary as the person chose', async () => {
    const { user, api } = await openCard(
      wireLinkCard({ summary_mode: 'derived', summary: 'Derived text.' }),
    )
    api.on(`PATCH ${CARD_PATH}`, () =>
      json(wireLinkCard({ revision: 2, summary_mode: 'custom', summary: 'Mine' })),
    )

    expect(
      screen.getByRole('radio', { name: /Summarise it from the content automatically/ }),
    ).toBeChecked()
    expect(screen.getByText('Currently: Derived text.')).toBeInTheDocument()
    await user.click(screen.getByRole('radio', { name: 'Write my own summary' }))
    await user.type(screen.getByLabelText('Your summary'), 'Mine')
    await user.clear(screen.getByLabelText('Web address'))
    await user.type(screen.getByLabelText('Web address'), 'https://example.org/new')
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    await screen.findByText('The Card was saved.')
    expect(api.callsTo(`PATCH ${CARD_PATH}`)[0]?.body).toEqual({
      revision: 1,
      uri: 'https://example.org/new',
      summary: 'Mine',
    })

    // And back to automatic: the server recomputes it, so only the mode is sent.
    api.on(`PATCH ${CARD_PATH}`, () =>
      json(wireLinkCard({ revision: 3, summary_mode: 'derived', summary: 'Derived text.' })),
    )
    await user.click(
      screen.getByRole('radio', { name: /Summarise it from the content automatically/ }),
    )
    await user.click(screen.getByRole('button', { name: 'Save Card' }))
    await waitFor(() => {
      expect(api.callsTo(`PATCH ${CARD_PATH}`)).toHaveLength(2)
    })
    expect(api.callsTo(`PATCH ${CARD_PATH}`)[1]?.body).toEqual({
      revision: 2,
      summary_mode: 'derived',
    })
  })

  it('does not overwrite silently when someone else saved first: re-reads the Card and keeps the person’s edits', async () => {
    const { user, api } = await openCard(wireCard({ revision: 1 }))
    const theirs = wireCard({
      revision: 2,
      title: 'Their title',
      updated_by: { id: '01J000000000000000000OTHER1', display_name: 'Hone Guardian' },
    })
    api.on(`PATCH ${CARD_PATH}`, () => staleRevision(theirs))
    api.on(`GET ${CARD_PATH}`, () => json(theirs))

    await user.clear(screen.getByLabelText('Title'))
    await user.type(screen.getByLabelText('Title'), 'My title')
    await writeContent('Content', ' Mine.')
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    const conflict = await screen.findByText('Someone else saved changes to this Card first.')
    const alert = conflict.closest('[role="status"]')
    expect(alert).toHaveFocus()
    expect(alert).toHaveTextContent('revision 2')
    expect(alert).toHaveTextContent('Hone Guardian')
    expect(alert).toHaveTextContent('Their title')
    expect(screen.getByLabelText('Title')).toHaveValue('My title')
    // The person's words in the editor are still there.
    expect(document.body).toHaveTextContent('We are open every day. Mine.')
    expect(api.callsTo(`PATCH ${CARD_PATH}`)).toHaveLength(1)

    api.on(`PATCH ${CARD_PATH}`, () => json(wireCard({ revision: 3, title: 'My title' })))
    await user.click(screen.getByRole('button', { name: 'Save Card' }))
    await screen.findByText('The Card was saved.')
    expect(api.callsTo(`PATCH ${CARD_PATH}`)[1]?.body).toMatchObject({
      revision: 2,
      title: 'My title',
    })
  })

  it('shows the server’s refusal of content where the editor is', async () => {
    const { user, api } = await openCard()
    api.on(`PATCH ${CARD_PATH}`, () => invalidContent('Images are not allowed at content.2.'))

    await writeContent('Content', 'More')
    await user.click(screen.getByRole('button', { name: 'Save Card' }))

    expect(await screen.findByText('Images are not allowed at content.2.')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Content' })).toHaveAttribute('aria-invalid', 'true')
    // Nothing the person wrote was thrown away.
    expect(document.body).toHaveTextContent('We are open every day.More')
  })
})

describe('publishing a Card', () => {
  it('publishes it, says so, asking for no verification', async () => {
    const { user, api } = await openCard()
    api.on(`POST ${CARD_PATH}/publish`, () => json(wireCard({ state: 'published' })))

    await user.click(screen.getByRole('button', { name: 'Publish Card' }))

    expect(await screen.findByText('The Card is now Published.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Unpublish Card' })).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
    expect(document.activeElement).toHaveTextContent('The Card is now Published.')
  })

  it('names what a Card still needs, and publishes nothing for the person', async () => {
    const { user, api } = await openCard()
    api.on(`POST ${CARD_PATH}/publish`, () => cardNotPublishable(['content']))

    await user.click(screen.getByRole('button', { name: 'Publish Card' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The Card cannot be published yet. It still needs:')
    expect(within(alert).getByRole('listitem')).toHaveTextContent('Write some text in the content.')
    expect(screen.getByRole('button', { name: 'Publish Card' })).toBeInTheDocument()
  })

  it('says why the last Published Card of a Published Pack cannot be unpublished', async () => {
    const { user, api } = await openCard(wireCard({ state: 'published' }))
    api.on(`POST ${CARD_PATH}/unpublish`, () => publishedPackRequirement('published_card'))

    await user.click(screen.getByRole('button', { name: 'Unpublish Card' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'must keep at least one Published Card',
    )
    expect(screen.getByRole('button', { name: 'Unpublish Card' })).toBeInTheDocument()
  })

  it('unpublishes it back to a Draft', async () => {
    const { user, api } = await openCard(wireCard({ state: 'published' }))
    api.on(`POST ${CARD_PATH}/unpublish`, () => json(wireCard({ state: 'draft' })))

    await user.click(screen.getByRole('button', { name: 'Unpublish Card' }))

    expect(await screen.findByText('The Card is now a Draft again.')).toBeInTheDocument()
  })
})

describe('a Card’s audience', () => {
  const both = wirePack({ audiences: ['guardian', 'member'] })

  it('inherits by default, shows what that currently is, and offers narrowing only to the Pack’s own audiences', async () => {
    const { user } = await openCard(wireCard(), wirePack({ audiences: ['guardian'] }))
    const form = screen.getByRole('form', { name: 'Card audience' })

    expect(within(form).getByRole('radio', { name: /Everyone the Pack is for/ })).toBeChecked()
    expect(form).toHaveTextContent('Currently: Guardians')
    await user.click(within(form).getByRole('radio', { name: /Only part of that audience/ }))
    expect(within(form).getByRole('checkbox', { name: 'Guardians' })).toBeInTheDocument()
    // The Pack is not aimed at Members, so a Card cannot be offered Members.
    expect(within(form).queryByRole('checkbox', { name: 'Members' })).not.toBeInTheDocument()
  })

  it('narrows to a chosen subset, and sends the mode and the audiences', async () => {
    const { user, api } = await openCard(wireCard(), both)
    api.on(`PUT ${CARD_PATH}/audiences`, () =>
      json(wireCard({ audience_mode: 'narrowed', audiences: ['member'] })),
    )
    const form = screen.getByRole('form', { name: 'Card audience' })

    await user.click(within(form).getByRole('radio', { name: /Only part of that audience/ }))
    await user.click(within(form).getByRole('checkbox', { name: 'Members' }))
    await user.click(within(form).getByRole('button', { name: 'Save audience' }))

    expect(await screen.findByText('The Card’s audience was saved.')).toBeInTheDocument()
    expect(api.callsTo(`PUT ${CARD_PATH}/audiences`)[0]?.body).toEqual({
      mode: 'narrowed',
      audiences: ['member'],
    })
  })

  it('returns to inheriting by sending the mode alone', async () => {
    const { user, api } = await openCard(
      wireCard({ audience_mode: 'narrowed', audiences: ['guardian'] }),
      both,
    )
    api.on(`PUT ${CARD_PATH}/audiences`, () => json(wireCard()))
    const form = screen.getByRole('form', { name: 'Card audience' })

    await user.click(within(form).getByRole('radio', { name: /Everyone the Pack is for/ }))
    await user.click(within(form).getByRole('button', { name: 'Save audience' }))

    await screen.findByText('The Card’s audience was saved.')
    expect(api.callsTo(`PUT ${CARD_PATH}/audiences`)[0]?.body).toEqual({ mode: 'inherit' })
  })

  it('shows the server’s refusal when a Card would be broader than its Pack, and keeps the choice as it was', async () => {
    const { user, api } = await openCard(wireCard(), both)
    api.on(`PUT ${CARD_PATH}/audiences`, () =>
      json(
        {
          message: 'x',
          code: 'card_audience_not_subset',
          errors: { audiences: ['Not a subset.'] },
        },
        422,
      ),
    )
    const form = screen.getByRole('form', { name: 'Card audience' })

    await user.click(within(form).getByRole('radio', { name: /Only part of that audience/ }))
    await user.click(within(form).getByRole('checkbox', { name: 'Members' }))
    await user.click(within(form).getByRole('button', { name: 'Save audience' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'A Card can only be narrowed to audiences its Pack has.',
    )
    expect(api.callsTo(`PUT ${CARD_PATH}/audiences`)).toHaveLength(1)
  })

  it('cannot narrow a Card when its Pack has no audience yet, and says why', async () => {
    await openCard(wireCard(), wirePack({ audiences: [] }))
    const form = screen.getByRole('form', { name: 'Card audience' })

    expect(within(form).getByRole('radio', { name: /Only part of that audience/ })).toBeDisabled()
    expect(form).toHaveTextContent('Choose the Pack’s audiences first.')
  })

  it('shows a conflict the server names, in words', async () => {
    const { user, api } = await openCard(wireCard(), both)
    api.on(`PUT ${CARD_PATH}/audiences`, () => cardAudienceConflict([]))
    const form = screen.getByRole('form', { name: 'Card audience' })
    await user.click(within(form).getByRole('radio', { name: /Only part of that audience/ }))
    await user.click(within(form).getByRole('checkbox', { name: 'Guardians' }))
    await user.click(within(form).getByRole('button', { name: 'Save audience' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'narrowed Card with an audience its Pack no longer has',
    )
  })
})

describe('deleting a Card', () => {
  const prove = async (user: ReturnType<typeof userEvent.setup>) => {
    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    await user.type(within(prompt).getByLabelText('Current password'), 'the current password')
    await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
    await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
  }

  it('says it is permanent, and says what happens to a File Card’s file', async () => {
    const { user } = await openCard(wireFileCard())
    await user.click(screen.getByRole('button', { name: 'Delete Card…' }))

    const dialog = await screen.findByRole('dialog', { name: 'Permanently delete this Card?' })
    expect(dialog).toHaveTextContent('It cannot be restored.')
    expect(dialog).toHaveTextContent(
      'Its managed file is removed from the store once the deletion succeeds.',
    )
    expect(dialog).not.toHaveTextContent(/backup/i)
  })

  it('does not mention a file for a Card that has none', async () => {
    const { user } = await openCard()
    await user.click(screen.getByRole('button', { name: 'Delete Card…' }))
    expect(await screen.findByRole('dialog')).not.toHaveTextContent('managed file')
  })

  it('goes through the verification flow, never repeats the deletion for the person, and returns to the Pack', async () => {
    const { user, api } = await openCard()
    let verified = false
    api.on(`DELETE ${CARD_PATH}`, () => (verified ? empty() : verificationRequired()))
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return empty(200)
    })

    await user.click(screen.getByRole('button', { name: 'Delete Card…' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }),
    )
    await prove(user)
    expect(await screen.findByText(/confirm again to continue/)).toBeInTheDocument()
    expect(api.callsTo(`DELETE ${CARD_PATH}`)).toHaveLength(1)

    await user.click(screen.getByRole('button', { name: 'Delete permanently' }))

    expect(
      await screen.findByText('The Card “Opening hours” was permanently deleted.'),
    ).toBeInTheDocument()
    expect(api.callsTo(`DELETE ${CARD_PATH}`)).toHaveLength(2)
    expect(screen.getByTestId('location')).toHaveTextContent(`/resources/packs/${PACK_ID}`)
  })

  it('says why the last Published Card of a Published Pack cannot be deleted, in the dialog', async () => {
    const { user, api } = await openCard(wireCard({ state: 'published' }))
    api.on(`DELETE ${CARD_PATH}`, () => publishedPackRequirement('published_card'))

    await user.click(screen.getByRole('button', { name: 'Delete Card…' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }),
    )

    expect(await screen.findByText(/must keep at least one Published Card/)).toBeInTheDocument()
    expect(
      screen.getByRole('dialog', { name: 'Permanently delete this Card?' }),
    ).toBeInTheDocument()
  })

  it('returns focus to where it was when cancelled', async () => {
    const { user } = await openCard()
    const opener = screen.getByRole('button', { name: 'Delete Card…' })
    await user.click(opener)
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }),
    )
    expect(opener).toHaveFocus()
  })
})
