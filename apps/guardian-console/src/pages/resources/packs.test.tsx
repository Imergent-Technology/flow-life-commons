import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { operator, serveOperator, verificationRequired } from '../../test/admin.ts'
import { deferred, nth, pageBody } from '../../test/deferred.ts'
import { empty, json } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'
import {
  CARD2_ID,
  CARD_ID,
  CATEGORY2_ID,
  CATEGORY_ID,
  cardAudienceConflict,
  categoryList,
  coded,
  MANAGE,
  orderMismatch,
  PACK2_ID,
  PACK_ID,
  PACK_PATH,
  packNotFound,
  packNotPublishable,
  packsPage,
  publishedPackRequirement,
  ROOT,
  staleRevision,
  wireCategory,
  wireListedPack,
  wireOutline,
  wirePack,
  wirePreview,
} from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const CATEGORIES = [
  wireCategory(),
  wireCategory({ id: CATEGORY2_ID, name: 'Recipes', position: 2 }),
]

function serve() {
  const api = serveOperator(operator(MANAGE))
  api.on(`GET ${ROOT}/categories`, () => json(categoryList(CATEGORIES)))
  return api
}

const previewCalls = (api: ReturnType<typeof serve>) =>
  api.calls.filter((c) => c.method === 'GET' && c.path.startsWith(`${PACK_PATH}/preview`))

const packRequests = (api: ReturnType<typeof serve>) =>
  api.calls.filter((c) => c.method === 'GET' && c.path.startsWith(`${ROOT}/packs?`))

const lastQuery = (api: ReturnType<typeof serve>) =>
  new URL(nth(packRequests(api), packRequests(api).length - 1).path, 'http://x').searchParams

// --- The management list -----------------------------------------------------------------------------------------------------

describe('the Resource Pack list', () => {
  async function openList(rows: unknown[], meta: Record<string, number> = {}) {
    const user = userEvent.setup()
    const api = serve()
    api.on(`GET ${ROOT}/packs`, () => json(packsPage(rows, meta)))
    renderApp('/resources')
    await screen.findByRole('heading', { level: 1, name: 'Resources' })
    return { user, api }
  }

  it('lists Packs in the SERVER’s order, saying Draft or Published in words, with audiences and Card counts', async () => {
    await openList([
      wireListedPack({
        id: PACK2_ID,
        title: 'Zebra rules',
        state: 'published',
        card_count: 3,
        published_card_count: 2,
        audiences: ['guardian', 'member'],
      }),
      wireListedPack({
        id: PACK_ID,
        title: 'Apple recipes',
        category: null,
        state: 'draft',
        card_count: 0,
        published_card_count: 0,
        audiences: [],
      }),
    ])

    const table = await screen.findByRole('table', { name: 'Resource Packs' })
    const rows = within(table).getAllByRole('row').slice(1)

    // Not re-sorted: Zebra stays first because the server said so.
    expect(rows[0]).toHaveTextContent('Zebra rules')
    expect(rows[0]).toHaveTextContent('Published')
    expect(rows[0]).toHaveTextContent('Guardians, Members')
    expect(rows[0]).toHaveTextContent('2 of 3 published')
    expect(rows[1]).toHaveTextContent('Apple recipes')
    expect(rows[1]).toHaveTextContent('Draft')
    expect(rows[1]).toHaveTextContent('No Category')
    expect(rows[1]).toHaveTextContent('No audience')
    expect(rows[1]).toHaveTextContent('No Cards')
    expect(within(rows[0] ?? table).getByRole('link', { name: 'Zebra rules' })).toHaveAttribute(
      'href',
      `/resources/packs/${PACK2_ID}`,
    )
  })

  it('says there are none yet, and offers the first', async () => {
    await openList([])
    expect(await screen.findByText('There are no Resource Packs yet.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Add the first Resource Pack' })).toHaveAttribute(
      'href',
      '/resources/new',
    )
  })

  it('asks the server to filter and search, returns to page 1, and says when nothing matches', async () => {
    const { user, api } = await openList([wireListedPack()], { last_page: 3, total: 60 })
    await screen.findByRole('table', { name: 'Resource Packs' })

    await user.click(screen.getByRole('button', { name: 'Next' }))
    await waitFor(() => {
      expect(lastQuery(api).get('page')).toBe('2')
    })

    await user.selectOptions(screen.getByLabelText('State'), 'published')
    await user.selectOptions(screen.getByLabelText('Audience'), 'member')
    await user.selectOptions(screen.getByLabelText('Has a Card of type'), 'file')
    await waitFor(() => {
      const query = lastQuery(api)
      expect(query.get('state')).toBe('published')
      expect(query.get('audience')).toBe('member')
      expect(query.get('card_type')).toBe('file')
      expect(query.get('page')).toBe('1')
    })

    const category = screen.getByLabelText('Category')
    await waitFor(() => {
      expect(category).toBeEnabled()
    })
    await user.selectOptions(category, CATEGORY2_ID)
    await user.type(screen.getByLabelText('Search titles'), '  welcome  ')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await waitFor(() => {
      expect(lastQuery(api).get('q')).toBe('welcome')
      expect(lastQuery(api).get('category')).toBe(CATEGORY2_ID)
    })

    api.on(`GET ${ROOT}/packs`, () => json(packsPage([])))
    await user.selectOptions(screen.getByLabelText('State'), 'draft')
    expect(await screen.findByText('No Resource Packs match.')).toBeInTheDocument()
    expect(screen.queryByText('There are no Resource Packs yet.')).not.toBeInTheDocument()
  })

  it('says when it cannot load, and asks again on request', async () => {
    const user = userEvent.setup()
    const api = serve()
    let answers = 0
    api.on(`GET ${ROOT}/packs`, () =>
      answers++ === 0 ? json({}, 500) : json(packsPage([wireListedPack()])),
    )
    renderApp('/resources')

    expect(
      await screen.findByText('The service is temporarily unavailable. Try again in a moment.'),
    ).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByRole('table', { name: 'Resource Packs' })).toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    await openList([wireListedPack(), wireListedPack({ id: PACK2_ID, title: 'Second' })])
    await screen.findByRole('table', { name: 'Resource Packs' })
    await expectNoAxeViolations()
  })
})

// --- Creating a Pack ---------------------------------------------------------------------------------------------------------

describe('adding a Resource Pack', () => {
  async function openNew() {
    const user = userEvent.setup()
    const api = serve()
    renderApp('/resources/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a Resource Pack' })
    await waitFor(() => {
      expect(screen.getByLabelText('Category')).toBeEnabled()
    })
    return { user, api }
  }

  it('sends the title, summary, Series flag and Category, and opens the new Draft Pack', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${ROOT}/packs`, () => json(wirePack({ id: PACK2_ID, title: 'Safety' }), 201))
    api.on(`GET ${ROOT}/packs/${PACK2_ID}`, () => json(wirePack({ id: PACK2_ID, title: 'Safety' })))

    await user.type(screen.getByLabelText('Title'), 'Safety')
    await user.type(screen.getByLabelText('Summary'), 'Fire and first aid.')
    await user.selectOptions(screen.getByLabelText('Category'), CATEGORY2_ID)
    await user.click(screen.getByLabelText(/This Pack is a Series/))
    await user.click(screen.getByRole('button', { name: 'Create Resource Pack' }))

    expect(api.callsTo(`POST ${ROOT}/packs`)[0]?.body).toEqual({
      title: 'Safety',
      summary: 'Fire and first aid.',
      is_series: true,
      category_id: CATEGORY2_ID,
    })
    expect(await screen.findByRole('heading', { level: 1, name: 'Safety' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(`/resources/packs/${PACK2_ID}`)
    expect(screen.getByText(/created as a Draft/)).toBeInTheDocument()
  })

  it('sends no summary and no Category when none was given', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${ROOT}/packs`, () => json(wirePack({ id: PACK2_ID }), 201))
    api.on(`GET ${ROOT}/packs/${PACK2_ID}`, () => json(wirePack({ id: PACK2_ID })))

    await user.type(screen.getByLabelText('Title'), 'Bare')
    await user.click(screen.getByRole('button', { name: 'Create Resource Pack' }))

    await waitFor(() => {
      expect(api.callsTo(`POST ${ROOT}/packs`)).toHaveLength(1)
    })
    expect(api.callsTo(`POST ${ROOT}/packs`)[0]?.body).toEqual({
      title: 'Bare',
      summary: null,
      is_series: false,
      category_id: null,
    })
  })

  it('shows the server’s message beside the field it concerns, and keeps the form as typed', async () => {
    const { user, api } = await openNew()
    api.on(`POST ${ROOT}/packs`, () =>
      json(
        {
          message: 'Invalid.',
          code: 'invalid_resource_input',
          errors: { title: ['A title is required.'] },
        },
        422,
      ),
    )

    await user.type(screen.getByLabelText('Title'), '   ')
    await user.type(screen.getByLabelText('Summary'), 'kept')
    await user.click(screen.getByRole('button', { name: 'Create Resource Pack' }))

    expect(await screen.findByText('A title is required.')).toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toHaveAttribute('aria-invalid', 'true')
    // Keyboard focus is taken to the field to fix, not left on the button that stopped being pressable.
    expect(screen.getByLabelText('Title')).toHaveFocus()
    expect(screen.getByLabelText('Summary')).toHaveValue('kept')
  })

  it('has no accessibility violations', async () => {
    await openNew()
    await expectNoAxeViolations()
  })
})

// --- One Pack ----------------------------------------------------------------------------------------------------------------

async function openPack(pack: Record<string, unknown> = wirePack()) {
  const user = userEvent.setup()
  const api = serve()
  api.on(`GET ${PACK_PATH}`, () => json(pack))
  renderApp(`/resources/packs/${PACK_ID}`)
  await screen.findByRole('heading', { level: 1, name: String(pack.title) })
  await waitFor(() => {
    expect(screen.getByLabelText('Category')).toBeEnabled()
  })
  return { user, api }
}

describe('a Pack’s page', () => {
  it('shows its details, state, audiences, Cards and the facts publishing depends on', async () => {
    await openPack()

    expect(screen.getByLabelText('Title')).toHaveValue('Welcome pack')
    expect(screen.getByLabelText('Summary')).toHaveValue('Everything a new Guardian needs.')
    expect(screen.getByLabelText('Category')).toHaveValue(CATEGORY_ID)
    expect(screen.getByRole('checkbox', { name: 'Guardians' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Members' })).not.toBeChecked()
    const publication = screen.getByRole('region', { name: 'Publication' })
    expect(within(publication).getByText('Draft')).toBeInTheDocument()
    expect(publication).toHaveTextContent('A Category: Training guides')
    expect(publication).toHaveTextContent('At least one audience: chosen')
    expect(publication).toHaveTextContent('At least one Published Card: 0 of 1 published')
    expect(
      within(screen.getByRole('list', { name: 'Cards' })).getByRole('link', {
        name: 'Opening hours',
      }),
    ).toHaveAttribute('href', `/resources/packs/${PACK_ID}/cards/${CARD_ID}`)
  })

  it('says a Pack that does not exist does not exist, and leads back', async () => {
    const api = serve()
    api.on(`GET ${PACK_PATH}`, () => packNotFound())
    renderApp(`/resources/packs/${PACK_ID}`)

    expect(
      await screen.findByText('That Resource Pack no longer exists. It may have been deleted.'),
    ).toBeInTheDocument()
    expect(within(pageBody()).getByRole('link', { name: 'All Resource Packs' })).toHaveAttribute(
      'href',
      '/resources',
    )
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    await openPack()
    await expectNoAxeViolations()
  })
})

describe('editing a Pack’s details', () => {
  it('sends only what changed, with the revision it was based on, and says it saved', async () => {
    const { user, api } = await openPack(wirePack({ revision: 4 }))
    api.on(`PATCH ${PACK_PATH}`, () => json(wirePack({ revision: 5, title: 'Welcome pack 2' })))

    await user.clear(screen.getByLabelText('Title'))
    await user.type(screen.getByLabelText('Title'), 'Welcome pack 2')
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    expect(await screen.findByText('The Pack’s details were saved.')).toBeInTheDocument()
    expect(api.callsTo(`PATCH ${PACK_PATH}`)[0]?.body).toEqual({
      revision: 4,
      title: 'Welcome pack 2',
    })
    expect(document.activeElement).toHaveTextContent('The Pack’s details were saved.')

    // The next edit is based on the revision the server returned.
    api.on(`PATCH ${PACK_PATH}`, () => json(wirePack({ revision: 6, summary: null })))
    await user.clear(screen.getByLabelText('Summary'))
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await waitFor(() => {
      expect(api.callsTo(`PATCH ${PACK_PATH}`)).toHaveLength(2)
    })
    expect(api.callsTo(`PATCH ${PACK_PATH}`)[1]?.body).toEqual({ revision: 5, summary: null })
  })

  it('moves a Pack to another Category, or clears it, by sending that one field', async () => {
    const { user, api } = await openPack()
    api.on(`PATCH ${PACK_PATH}`, () =>
      json(wirePack({ revision: 2, category: { id: CATEGORY2_ID, name: 'Recipes' } })),
    )

    await user.selectOptions(screen.getByLabelText('Category'), CATEGORY2_ID)
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await screen.findByText('The Pack’s details were saved.')
    expect(api.callsTo(`PATCH ${PACK_PATH}`)[0]?.body).toEqual({
      revision: 1,
      category_id: CATEGORY2_ID,
    })

    api.on(`PATCH ${PACK_PATH}`, () => json(wirePack({ revision: 3, category: null })))
    await user.selectOptions(screen.getByLabelText('Category'), '')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await waitFor(() => {
      expect(api.callsTo(`PATCH ${PACK_PATH}`)).toHaveLength(2)
    })
    expect(api.callsTo(`PATCH ${PACK_PATH}`)[1]?.body).toEqual({ revision: 2, category_id: null })
  })

  it('sends nothing, and says so, when nothing changed', async () => {
    const { user, api } = await openPack()
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    expect(
      await screen.findByText('There is nothing to save: no field has changed.'),
    ).toBeInTheDocument()
    expect(api.callsTo(`PATCH ${PACK_PATH}`)).toHaveLength(0)
  })

  it('does not overwrite silently when someone else saved first: it reads the Pack again and keeps the person’s edits', async () => {
    const { user, api } = await openPack(wirePack({ revision: 1 }))
    const theirs = wirePack({
      revision: 2,
      title: 'Their title',
      summary: 'Their summary',
      updated_by: { id: '01J000000000000000000OTHER1', display_name: 'Hone Guardian' },
    })
    api.on(`PATCH ${PACK_PATH}`, () => staleRevision(theirs))
    api.on(`GET ${PACK_PATH}`, () => json(theirs))

    await user.clear(screen.getByLabelText('Title'))
    await user.type(screen.getByLabelText('Title'), 'My title')
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    const conflict = await screen.findByText('Someone else saved changes to this Pack first.')
    const alert = conflict.closest('[role="status"]')
    expect(alert).toHaveFocus()
    expect(alert).toHaveTextContent('revision 2')
    expect(alert).toHaveTextContent('Hone Guardian')
    expect(alert).toHaveTextContent('Their title')
    expect(alert).toHaveTextContent('Their summary')
    // Their words are shown beside mine; mine are still in the form, unsaved.
    expect(screen.getByLabelText('Title')).toHaveValue('My title')
    expect(api.callsTo(`PATCH ${PACK_PATH}`)).toHaveLength(1)

    // Saving again is a deliberate act, based on the CURRENT revision.
    api.on(`PATCH ${PACK_PATH}`, () => json(wirePack({ revision: 3, title: 'My title' })))
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    expect(await screen.findByText('The Pack’s details were saved.')).toBeInTheDocument()
    expect(api.callsTo(`PATCH ${PACK_PATH}`)[1]?.body).toMatchObject({
      revision: 2,
      title: 'My title',
    })
    expect(
      screen.queryByText('Someone else saved changes to this Pack first.'),
    ).not.toBeInTheDocument()
  })

  it('lets the person take the saved version instead', async () => {
    const { user, api } = await openPack(wirePack({ revision: 1 }))
    const theirs = wirePack({ revision: 2, title: 'Their title' })
    api.on(`PATCH ${PACK_PATH}`, () => staleRevision(theirs))
    api.on(`GET ${PACK_PATH}`, () => json(theirs))

    await user.clear(screen.getByLabelText('Title'))
    await user.type(screen.getByLabelText('Title'), 'My title')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await screen.findByText('Someone else saved changes to this Pack first.')
    await user.click(screen.getByRole('button', { name: 'Use the saved version' }))

    expect(screen.getByLabelText('Title')).toHaveValue('Their title')
    expect(
      screen.queryByText('Someone else saved changes to this Pack first.'),
    ).not.toBeInTheDocument()
    expect(document.activeElement).toHaveTextContent('The form now shows the saved version.')
  })

  it('says a Category that has gone is gone, beside the Category field', async () => {
    const { user, api } = await openPack()
    api.on(`PATCH ${PACK_PATH}`, () =>
      json(
        {
          message: 'x',
          code: 'unknown_category',
          errors: { category_id: ['That Category does not exist.'] },
        },
        422,
      ),
    )
    await user.selectOptions(screen.getByLabelText('Category'), CATEGORY2_ID)
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    expect(await screen.findByText('Choose a Category from the list.')).toBeInTheDocument()
    expect(screen.getByLabelText('Category')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Category')).toHaveFocus()
  })

  it('says why a Published Pack cannot lose its Category, and changes nothing', async () => {
    const { user, api } = await openPack(wirePack({ state: 'published' }))
    api.on(`PATCH ${PACK_PATH}`, () => publishedPackRequirement('category'))

    await user.selectOptions(screen.getByLabelText('Category'), '')
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('A Published Pack must keep its Category')
    expect(screen.getByLabelText('Category')).toHaveValue('')
  })
})

describe('publishing a Pack', () => {
  it('publishes it, says so, and shows the new state, asking for no verification', async () => {
    const { user, api } = await openPack()
    api.on(`POST ${PACK_PATH}/publish`, () =>
      json(wirePack({ state: 'published', published_card_count: 1 })),
    )

    await user.click(screen.getByRole('button', { name: 'Publish Pack' }))

    expect(await screen.findByText('The Pack is now Published.')).toBeInTheDocument()
    expect(
      within(screen.getByRole('region', { name: 'Publication' })).getByText('Published'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Unpublish Pack' })).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
    // The button that was pressed went away; focus is on the outcome, not at the top of the page.
    expect(document.activeElement).toHaveTextContent('The Pack is now Published.')
  })

  it('names everything the Pack still needs, and does not fix any of it for the person', async () => {
    const { user, api } = await openPack(wirePack({ category: null, audiences: [] }))
    api.on(`POST ${PACK_PATH}/publish`, () =>
      packNotPublishable(['category', 'audience', 'published_card']),
    )

    await user.click(screen.getByRole('button', { name: 'Publish Pack' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The Pack cannot be published yet. It still needs:')
    expect(
      within(alert)
        .getAllByRole('listitem')
        .map((li) => li.textContent),
    ).toEqual(['Choose a Category.', 'Choose at least one audience.', 'Publish at least one Card.'])
    expect(screen.getByLabelText('Category')).toHaveValue('')
    expect(screen.getByRole('checkbox', { name: 'Guardians' })).not.toBeChecked()
    expect(api.calls.filter((c) => c.method !== 'GET')).toHaveLength(1)
  })

  it('unpublishes it back to a Draft', async () => {
    const { user, api } = await openPack(wirePack({ state: 'published', published_card_count: 1 }))
    api.on(`POST ${PACK_PATH}/unpublish`, () => json(wirePack({ state: 'draft' })))

    await user.click(screen.getByRole('button', { name: 'Unpublish Pack' }))

    expect(await screen.findByText('The Pack is now a Draft again.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publish Pack' })).toBeInTheDocument()
  })
})

describe('a Pack’s audiences', () => {
  it('replaces the whole set, and says plainly that nothing delivers to Members yet', async () => {
    const { user, api } = await openPack()
    const form = screen.getByRole('form', { name: 'Pack audiences' })
    expect(within(form).getByText(/Nothing delivers it to Members yet/)).toBeInTheDocument()
    api.on(`PUT ${PACK_PATH}/audiences`, () =>
      json(wirePack({ audiences: ['guardian', 'member'] })),
    )

    await user.click(within(form).getByRole('checkbox', { name: 'Members' }))
    await user.click(within(form).getByRole('button', { name: 'Save audiences' }))

    expect(await screen.findByText('The Pack’s audiences were saved.')).toBeInTheDocument()
    expect(api.callsTo(`PUT ${PACK_PATH}/audiences`)[0]?.body).toEqual({
      audiences: ['guardian', 'member'],
    })
  })

  it('names the Cards that block removing an audience, by title, and keeps the choice as it was', async () => {
    const { user, api } = await openPack(
      wirePack({
        audiences: ['guardian', 'member'],
        cards: [
          wireOutline({
            id: CARD_ID,
            title: 'Members only',
            audience_mode: 'narrowed',
            audiences: ['member'],
          }),
        ],
      }),
    )
    api.on(`PUT ${PACK_PATH}/audiences`, () => cardAudienceConflict([CARD_ID]))
    const form = screen.getByRole('form', { name: 'Pack audiences' })

    await user.click(within(form).getByRole('checkbox', { name: 'Members' }))
    await user.click(within(form).getByRole('button', { name: 'Save audiences' }))

    const alert = await within(form).findByRole('alert')
    expect(alert).toHaveTextContent(
      'That would leave a narrowed Card with an audience its Pack no longer has',
    )
    expect(within(alert).getByRole('listitem')).toHaveTextContent('Members only')
    expect(within(form).getByRole('checkbox', { name: 'Members' })).not.toBeChecked()
  })

  it('says why a Published Pack must keep an audience', async () => {
    const { user, api } = await openPack(wirePack({ state: 'published' }))
    api.on(`PUT ${PACK_PATH}/audiences`, () => publishedPackRequirement('audience'))
    const form = screen.getByRole('form', { name: 'Pack audiences' })

    await user.click(within(form).getByRole('checkbox', { name: 'Guardians' }))
    await user.click(within(form).getByRole('button', { name: 'Save audiences' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'must keep at least one audience',
    )
  })

  it('sends nothing when the audiences have not changed', async () => {
    const { user, api } = await openPack()
    await user.click(screen.getByRole('button', { name: 'Save audiences' }))

    expect(
      await screen.findByText('There is nothing to save: the audiences have not changed.'),
    ).toBeInTheDocument()
    expect(api.callsTo(`PUT ${PACK_PATH}/audiences`)).toHaveLength(0)
  })
})

describe('a Pack’s Cards', () => {
  const THREE = [
    wireOutline({ id: CARD_ID, title: 'First', state: 'published' }),
    wireOutline({
      id: CARD2_ID,
      title: 'Second',
      type: 'file',
      file: { name: 'Map.pdf', media_type: 'application/pdf', byte_size: 2048 },
      audience_mode: 'narrowed',
      audiences: ['guardian'],
    }),
    wireOutline({
      id: '01J00000000000000000CARD003',
      title: 'Third',
      type: 'external_link',
      uri: 'https://example.org/x',
    }),
  ]

  const titles = () =>
    within(screen.getByRole('list', { name: 'Cards' }))
      .getAllByRole('listitem')
      .map((li) => li.querySelector('a')?.textContent)

  it('shows each Card’s Type and state in words, in the server’s order, with what matters about it', async () => {
    await openPack(wirePack({ cards: THREE, card_count: 3 }))
    const items = within(screen.getByRole('list', { name: 'Cards' })).getAllByRole('listitem')

    expect(titles()).toEqual(['First', 'Second', 'Third'])
    expect(items[0]).toHaveTextContent('Basic')
    expect(items[0]).toHaveTextContent('Published')
    expect(items[0]).toHaveTextContent('Inherits the Pack’s audiences')
    expect(items[1]).toHaveTextContent('File')
    expect(items[1]).toHaveTextContent('Narrowed to Guardians')
    expect(items[1]).toHaveTextContent('Map.pdf (PDF, 2.0 KB)')
    expect(items[2]).toHaveTextContent('External link')
    expect(items[2]).toHaveTextContent('https://example.org/x')
    expect(screen.getByRole('link', { name: 'Add a Card' })).toHaveAttribute(
      'href',
      `/resources/packs/${PACK_ID}/cards/new`,
    )
  })

  it('says a Pack with no Cards has none, and what it needs', async () => {
    await openPack(wirePack({ cards: [], card_count: 0 }))
    expect(screen.getByText(/This Pack has no Cards yet/)).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Cards' })).not.toBeInTheDocument()
  })

  it('reorders with the whole new list, and shows the new order only once the server has accepted it', async () => {
    const { user, api } = await openPack(wirePack({ cards: THREE, card_count: 3 }))
    const held = deferred<Response>()
    api.on(`PUT ${PACK_PATH}/card-order`, () => held.promise)

    await user.click(screen.getByRole('button', { name: 'Move First down' }))
    await waitFor(() => {
      expect(api.callsTo(`PUT ${PACK_PATH}/card-order`)).toHaveLength(1)
    })
    expect(api.callsTo(`PUT ${PACK_PATH}/card-order`)[0]?.body).toEqual({
      ids: [CARD2_ID, CARD_ID, '01J00000000000000000CARD003'],
    })
    expect(titles()).toEqual(['First', 'Second', 'Third'])

    held.resolve(json(wirePack({ cards: [THREE[1], THREE[0], THREE[2]], card_count: 3 })))
    await waitFor(() => {
      expect(titles()).toEqual(['Second', 'First', 'Third'])
    })
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move First down' })).toHaveFocus()
    })
  })

  it('reads the Pack again, without losing unsaved input, when the order is stale', async () => {
    const { user, api } = await openPack(wirePack({ cards: THREE, card_count: 3 }))
    api.on(`PUT ${PACK_PATH}/card-order`, () => orderMismatch())
    api.on(`GET ${PACK_PATH}`, () =>
      json(
        wirePack({
          cards: [...THREE, wireOutline({ id: '01J00000000000000000CARD004', title: 'Fourth' })],
          card_count: 4,
        }),
      ),
    )
    // Something typed in the details form must survive the refresh.
    await user.type(screen.getByLabelText('Summary'), ' (draft edit)')

    await user.click(screen.getByRole('button', { name: 'Move First down' }))

    expect(
      await screen.findByText(/The list changed while you were reordering it/),
    ).toBeInTheDocument()
    await waitFor(() => {
      expect(titles()).toEqual(['First', 'Second', 'Third', 'Fourth'])
    })
    expect(screen.getByLabelText('Summary')).toHaveValue(
      'Everything a new Guardian needs. (draft edit)',
    )
  })
})

describe('previewing a Pack', () => {
  it('asks the server what an audience would receive, and shows it', async () => {
    const { user, api } = await openPack()
    api.on(`GET ${PACK_PATH}/preview`, () => json(wirePreview()))
    const form = screen.getByRole('form', { name: 'Preview the Pack' })

    await user.click(within(form).getByRole('button', { name: 'Preview' }))

    const result = await screen.findByTestId('pack-preview')
    expect(previewCalls(api)[0]?.path).toBe(`${PACK_PATH}/preview?audience=guardian`)
    expect(result).toHaveTextContent('Guardians would see 1 Card in Training guides')
    expect(result).toHaveTextContent('Opening hours')
    expect(result).toHaveTextContent('The Pack is a Draft, so Guardians cannot see it yet')
  })

  it('previews Members, with the plain note that nothing delivers to them yet, and says when they would see nothing', async () => {
    const { user, api } = await openPack()
    api.on(`GET ${PACK_PATH}/preview`, () =>
      json(
        wirePreview({ audience: 'member', audience_targeted: false, visible: false, pack: null }),
      ),
    )
    const form = screen.getByRole('form', { name: 'Preview the Pack' })

    await user.selectOptions(within(form).getByLabelText('Preview as'), 'member')
    expect(within(form).getByText(/Nothing delivers it to Members yet/)).toBeInTheDocument()
    await user.click(within(form).getByRole('button', { name: 'Preview' }))

    const result = await screen.findByTestId('pack-preview')
    expect(previewCalls(api)[0]?.path).toContain('audience=member')
    expect(result).toHaveTextContent('The Pack is not aimed at Members.')
    expect(result).toHaveTextContent('Members would see nothing from this Pack.')
  })
})

describe('deleting a Pack', () => {
  const prove = async (user: ReturnType<typeof userEvent.setup>) => {
    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    await user.type(within(prompt).getByLabelText('Current password'), 'the current password')
    await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
    await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
  }

  it('says exactly what goes, and does nothing until confirmed', async () => {
    const { user, api } = await openPack(
      wirePack({
        card_count: 3,
        cards: [
          wireOutline(),
          wireOutline({
            id: CARD2_ID,
            type: 'file',
            file: { name: 'a.pdf', media_type: 'application/pdf', byte_size: 1 },
          }),
          wireOutline({
            id: '01J00000000000000000CARD003',
            type: 'file',
            file: { name: 'b.pdf', media_type: 'application/pdf', byte_size: 1 },
          }),
        ],
      }),
    )
    await user.click(screen.getByRole('button', { name: 'Delete Resource Pack…' }))

    const dialog = await screen.findByRole('dialog', { name: 'Permanently delete this Pack?' })
    expect(dialog).toHaveTextContent('all 3 Cards in it')
    expect(dialog).toHaveTextContent('It cannot be restored.')
    expect(dialog).toHaveTextContent(
      'The managed files of its 2 File Cards are removed from the store once the deletion succeeds.',
    )
    expect(dialog).toHaveTextContent('recent check of who you are')
    expect(dialog).not.toHaveTextContent(/restore it|backup/i)
    expect(api.callsTo(`DELETE ${PACK_PATH}`)).toHaveLength(0)
  })

  it('goes through the established verification flow, never repeats the deletion for the person, and deletes on the second press', async () => {
    const { user, api } = await openPack()
    let verified = false
    api.on(`DELETE ${PACK_PATH}`, () => (verified ? empty() : verificationRequired()))
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return empty(200)
    })
    api.on(`GET ${ROOT}/packs`, () => json(packsPage([])))

    await user.click(screen.getByRole('button', { name: 'Delete Resource Pack…' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }),
    )

    await prove(user)
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
    })
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(1)
    expect(api.callsTo(`DELETE ${PACK_PATH}`)).toHaveLength(1)
    expect(await screen.findByText(/confirm again to continue/)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Delete permanently' }))

    expect(
      await screen.findByText('The Resource Pack “Welcome pack” was permanently deleted.'),
    ).toBeInTheDocument()
    expect(api.callsTo(`DELETE ${PACK_PATH}`)).toHaveLength(2)
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/resources$/)
  })

  it('says nothing was changed when the person cancels the verification', async () => {
    const { user, api } = await openPack()
    api.on(`DELETE ${PACK_PATH}`, () => verificationRequired())

    await user.click(screen.getByRole('button', { name: 'Delete Resource Pack…' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }),
    )
    await user.click(
      within(await screen.findByRole('dialog', { name: 'Confirm it is you' })).getByRole('button', {
        name: 'Cancel',
      }),
    )

    expect(
      await screen.findByText(/Verification was cancelled. Nothing has been changed./),
    ).toBeInTheDocument()
    expect(api.callsTo(`DELETE ${PACK_PATH}`)).toHaveLength(1)
  })

  it('returns focus to the button that opened the dialog when it is cancelled, and on Escape', async () => {
    const { user } = await openPack()
    const opener = screen.getByRole('button', { name: 'Delete Resource Pack…' })

    await user.click(opener)
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }),
    )
    expect(opener).toHaveFocus()
  })

  it('shows a refusal in Resources’ own words, in the dialog', async () => {
    const { user, api } = await openPack()
    api.on(`DELETE ${PACK_PATH}`, () => packNotFound())

    await user.click(screen.getByRole('button', { name: 'Delete Resource Pack…' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }),
    )

    expect(
      await screen.findByText('That Resource Pack no longer exists. It may have been deleted.'),
    ).toBeInTheDocument()
    expect(screen.queryByText(/account/i)).not.toBeInTheDocument()
  })

  it('shows a refusal that is not a Pack problem, as such', async () => {
    const { user, api } = await openPack()
    api.on(`DELETE ${PACK_PATH}`, () => coded(409, 'something_new'))

    await user.click(screen.getByRole('button', { name: 'Delete Resource Pack…' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete permanently' }),
    )

    expect(
      await screen.findByText('That could not be done in the current state. Reload and check.'),
    ).toBeInTheDocument()
  })
})
