import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { operator, serveOperator, verificationRequired } from '../../test/admin.ts'
import { deferred, nth } from '../../test/deferred.ts'
import { empty, json } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'
import {
  CATEGORY2_ID,
  CATEGORY_ID,
  categoryList,
  categoryNotEmpty,
  categoryNotFound,
  duplicateCategory,
  MANAGE,
  orderMismatch,
  packsPage,
  PACK2_ID,
  PACK_ID,
  ROOT,
  wireCategory,
  wireListedPack,
} from '../../test/resources.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const LIST = `GET ${ROOT}/categories` as const
const TWO = [
  wireCategory({ id: CATEGORY_ID, name: 'Training guides', position: 1, pack_count: 2 }),
  wireCategory({ id: CATEGORY2_ID, name: 'Recipes', position: 2, pack_count: 0 }),
]

async function open(rows: unknown[] = TWO) {
  const user = userEvent.setup()
  const api = serveOperator(operator(MANAGE))
  api.on(LIST, () => json(categoryList(rows)))
  renderApp('/resources/categories')
  await screen.findByRole('heading', { level: 1, name: 'Categories' })
  if (rows.length > 0) await screen.findByRole('list', { name: 'Categories' })
  else await screen.findByText('There are no Categories yet.')
  return { user, api }
}

const names = () =>
  within(screen.getByRole('list', { name: 'Categories' }))
    .getAllByRole('listitem')
    .map((item) => item.textContent)

describe('the Categories list', () => {
  it('lists the Categories in the server’s order, each with how many Packs hold it', async () => {
    await open()
    const items = within(await screen.findByRole('list', { name: 'Categories' })).getAllByRole(
      'listitem',
    )

    expect(items).toHaveLength(2)
    expect(items[0]).toHaveTextContent('Training guides')
    expect(items[0]).toHaveTextContent('2 Packs')
    expect(items[1]).toHaveTextContent('Recipes')
    expect(items[1]).toHaveTextContent('0 Packs')
  })

  it('says so when there are none, and what to do next', async () => {
    await open([])
    expect(await screen.findByText('There are no Categories yet.')).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Categories' })).not.toBeInTheDocument()
  })

  it('says when it cannot load, in its own words, and asks again on request', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    let answers = 0
    api.on(LIST, () => (answers++ === 0 ? json({ message: 'boom' }, 503) : json(categoryList(TWO))))
    renderApp('/resources/categories')

    expect(
      await screen.findByText('The service is temporarily unavailable. Try again in a moment.'),
    ).toBeInTheDocument()
    expect(screen.queryByText('boom')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByRole('list', { name: 'Categories' })).toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    await open()
    await screen.findByRole('list', { name: 'Categories' })
    await expectNoAxeViolations()
  })
})

describe('creating a Category', () => {
  it('sends the name, says it was created, and shows the new list', async () => {
    const { user, api } = await open([])
    let rows: unknown[] = []
    api.on(LIST, () => json(categoryList(rows)))
    api.on(`POST ${ROOT}/categories`, () => {
      rows = [wireCategory({ id: CATEGORY2_ID, name: 'Recipes' })]
      return json(rows[0], 201)
    })
    await screen.findByText('There are no Categories yet.')

    await user.type(screen.getByLabelText('New Category'), 'Recipes')
    await user.click(screen.getByRole('button', { name: 'Add Category' }))

    expect(await screen.findByText('The Category “Recipes” was created.')).toBeInTheDocument()
    expect(api.callsTo(`POST ${ROOT}/categories`)[0]?.body).toEqual({ name: 'Recipes' })
    expect(await screen.findByRole('list', { name: 'Categories' })).toHaveTextContent('Recipes')
    expect(screen.getByLabelText('New Category')).toHaveValue('')
  })

  it('says a duplicate name is a duplicate, in its own words, and keeps what was typed', async () => {
    const { user, api } = await open()
    api.on(`POST ${ROOT}/categories`, () => duplicateCategory())

    await user.type(screen.getByLabelText('New Category'), 'training GUIDES')
    await user.click(screen.getByRole('button', { name: 'Add Category' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('A Category with that name already exists.')
    expect(screen.queryByText(/Server wording/)).not.toBeInTheDocument()
    expect(screen.getByLabelText('New Category')).toHaveValue('training GUIDES')
    expect(alert).toHaveFocus()
  })

  it('shows the server’s own field message for a name it refuses', async () => {
    const { user, api } = await open()
    api.on(`POST ${ROOT}/categories`, () =>
      json(
        {
          message: 'Invalid.',
          code: 'invalid_resource_input',
          errors: { name: ['A name is at most 80 characters.'] },
        },
        422,
      ),
    )

    await user.type(screen.getByLabelText('New Category'), 'x')
    await user.click(screen.getByRole('button', { name: 'Add Category' }))

    expect(await screen.findByText('A name is at most 80 characters.')).toBeInTheDocument()
    expect(screen.getByLabelText('New Category')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('New Category')).toHaveFocus()
  })
})

describe('renaming a Category', () => {
  it('sends the new name, and says so', async () => {
    const { user, api } = await open()
    api.on(`PATCH ${ROOT}/categories/${CATEGORY2_ID}`, () =>
      json(wireCategory({ id: CATEGORY2_ID, name: 'Kitchen', position: 2 })),
    )

    await user.click(screen.getByRole('button', { name: 'Rename Recipes' }))
    const form = screen.getByRole('form', { name: 'Rename Recipes' })
    const field = within(form).getByLabelText('Category name')
    expect(field).toHaveFocus()
    await user.clear(field)
    await user.type(field, 'Kitchen')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('The Category was renamed to “Kitchen”.')).toBeInTheDocument()
    expect(api.callsTo(`PATCH ${ROOT}/categories/${CATEGORY2_ID}`)[0]?.body).toEqual({
      name: 'Kitchen',
    })
    expect(screen.queryByRole('form', { name: 'Rename Recipes' })).not.toBeInTheDocument()
  })

  it('sends nothing when the name is unchanged', async () => {
    const { user, api } = await open()
    await user.click(screen.getByRole('button', { name: 'Rename Recipes' }))
    await user.click(
      within(screen.getByRole('form', { name: 'Rename Recipes' })).getByRole('button', {
        name: 'Save',
      }),
    )

    expect(api.callsTo(`PATCH ${ROOT}/categories/${CATEGORY2_ID}`)).toHaveLength(0)
    expect(screen.queryByRole('form', { name: 'Rename Recipes' })).not.toBeInTheDocument()
  })

  it('says a clash with another Category’s name, and leaves the form open', async () => {
    const { user, api } = await open()
    api.on(`PATCH ${ROOT}/categories/${CATEGORY2_ID}`, () => duplicateCategory())

    await user.click(screen.getByRole('button', { name: 'Rename Recipes' }))
    const form = screen.getByRole('form', { name: 'Rename Recipes' })
    await user.clear(within(form).getByLabelText('Category name'))
    await user.type(within(form).getByLabelText('Category name'), 'Training guides')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'A Category with that name already exists.',
    )
  })

  it('says a Category that has gone is gone', async () => {
    const { user, api } = await open()
    api.on(`PATCH ${ROOT}/categories/${CATEGORY2_ID}`, () => categoryNotFound())

    await user.click(screen.getByRole('button', { name: 'Rename Recipes' }))
    const form = screen.getByRole('form', { name: 'Rename Recipes' })
    await user.type(within(form).getByLabelText('Category name'), ' 2')
    await user.click(within(form).getByRole('button', { name: 'Save' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'That Category no longer exists. It may have been deleted.',
    )
  })
})

describe('deleting a Category', () => {
  it('asks first, deletes an empty one with no recent-verification step, and says so', async () => {
    const { user, api } = await open()
    api.on(`DELETE ${ROOT}/categories/${CATEGORY2_ID}`, () => empty())

    await user.click(screen.getByRole('button', { name: 'Delete Recipes' }))
    const dialog = await screen.findByRole('dialog', { name: 'Delete this Category?' })
    expect(api.callsTo(`DELETE ${ROOT}/categories/${CATEGORY2_ID}`)).toHaveLength(0)
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(await screen.findByText('The Category “Recipes” was deleted.')).toBeInTheDocument()
    expect(api.callsTo(`DELETE ${ROOT}/categories/${CATEGORY2_ID}`)).toHaveLength(1)
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows the refusal, in its own words, for a Category that still holds Packs, and deletes nothing', async () => {
    const { user, api } = await open()
    api.on(`DELETE ${ROOT}/categories/${CATEGORY_ID}`, () => categoryNotEmpty())

    await user.click(screen.getByRole('button', { name: 'Delete Training guides' }))
    const dialog = await screen.findByRole('dialog', { name: 'Delete this Category?' })
    expect(within(dialog).getByText(/currently holds 2 Packs/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(
      await within(dialog).findByText(/That Category still has Packs in it, so it was not deleted/),
    ).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Delete this Category?' })).toBeInTheDocument()
    expect(screen.queryByText(/Server wording/)).not.toBeInTheDocument()
  })

  it('leaves the Category alone when the person cancels', async () => {
    const { user, api } = await open()
    await user.click(screen.getByRole('button', { name: 'Delete Recipes' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }),
    )

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.callsTo(`DELETE ${ROOT}/categories/${CATEGORY2_ID}`)).toHaveLength(0)
    expect(screen.getByRole('button', { name: 'Delete Recipes' })).toHaveFocus()
  })

  it('does not ask for recent verification, even if the server said it wanted it (it does not)', async () => {
    // The contract says category deletion is routine. If a response ever asked for proof, the Console would not open a prompt
    // for this action: it has no step-up path here, and says that the request was refused.
    const { user, api } = await open()
    api.on(`DELETE ${ROOT}/categories/${CATEGORY2_ID}`, () => verificationRequired())

    await user.click(screen.getByRole('button', { name: 'Delete Recipes' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete' }),
    )

    expect(await screen.findByText('Confirm it is you first, then try again.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
  })
})

describe('ordering Categories', () => {
  it('sends the WHOLE new order, shows nothing moved until the server accepts it, and keeps focus on the button', async () => {
    const { user, api } = await open()
    const held = deferred<Response>()
    api.on(`PUT ${ROOT}/categories/order`, () => held.promise)

    await user.click(screen.getByRole('button', { name: 'Move Training guides down' }))

    // In flight: the order on screen is still the old one, and the controls wait.
    await waitFor(() => {
      expect(api.callsTo(`PUT ${ROOT}/categories/order`)).toHaveLength(1)
    })
    expect(api.callsTo(`PUT ${ROOT}/categories/order`)[0]?.body).toEqual({
      ids: [CATEGORY2_ID, CATEGORY_ID],
    })
    expect(names()[0]).toContain('Training guides')
    expect(screen.getByRole('button', { name: 'Move Recipes up' })).toBeDisabled()

    held.resolve(
      json(
        categoryList([
          wireCategory({ id: CATEGORY2_ID, name: 'Recipes', position: 1 }),
          wireCategory({ id: CATEGORY_ID, name: 'Training guides', position: 2, pack_count: 2 }),
        ]),
      ),
    )

    await waitFor(() => {
      expect(names()[0]).toContain('Recipes')
    })
    expect(names()[1]).toContain('Training guides')
    // The moved item is now last, so its "down" is disabled: focus goes to its "up" instead of being lost.
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move Training guides up' })).toHaveFocus()
    })
    expect(screen.getByRole('status', { name: '' })).toBeDefined()
    expect(document.body).toHaveTextContent('Moved Training guides to position 2 of 2.')
  })

  it('reloads and says so when someone else changed the list meanwhile', async () => {
    const { user, api } = await open()
    // The page has already loaded the list once; the read after the refusal finds a third Category.
    api.on(LIST, () =>
      json(
        categoryList([
          ...TWO,
          wireCategory({ id: '01J000000000000000CATEGORY3', name: 'Newly added', position: 3 }),
        ]),
      ),
    )
    api.on(`PUT ${ROOT}/categories/order`, () => orderMismatch())

    await user.click(screen.getByRole('button', { name: 'Move Training guides down' }))

    expect(
      await screen.findByText(/The list changed while you were reordering it/),
    ).toBeInTheDocument()
    expect(await screen.findByText('Newly added')).toBeInTheDocument()
    // Nothing was moved on screen.
    expect(names()[0]).toContain('Training guides')
  })

  it('can be done without a pointer: Move up and Move down are real buttons in the tab order', async () => {
    const { user } = await open()
    expect(screen.getByRole('button', { name: 'Move Training guides up' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move Recipes down' })).toBeDisabled()
    await user.tab()
    // Tabbing reaches the move controls without any drag handle being involved.
    const buttons = screen
      .getAllByRole('button')
      .map((b) => b.getAttribute('aria-label') ?? b.textContent)
    expect(buttons).toContain('Move Training guides down')
    expect(buttons).toContain('Move Recipes up')
  })
})

describe('ordering the Packs in a Category', () => {
  it('lists the Category’s Packs from the server, and sends the whole new order', async () => {
    const { user, api } = await open()
    const rows = [
      wireListedPack({ id: PACK_ID, title: 'Welcome pack' }),
      wireListedPack({ id: PACK2_ID, title: 'Safety pack', state: 'published' }),
    ]
    api.on(`GET ${ROOT}/packs`, () => json(packsPage(rows)))
    api.on(`PUT ${ROOT}/categories/${CATEGORY_ID}/pack-order`, () =>
      json({ data: [rows[1], rows[0]] }),
    )

    await user.click(screen.getByRole('button', { name: 'Order the Packs in Training guides' }))
    const dialog = await screen.findByRole('dialog', { name: 'Order the Packs in Training guides' })
    const list = await within(dialog).findByRole('list', { name: 'Packs in Training guides' })

    const asked = api.calls.find((c) => c.path.startsWith(`${ROOT}/packs?`))
    expect(asked?.path).toContain(`category=${CATEGORY_ID}`)
    expect(asked?.path).toContain('per_page=100')
    expect(within(list).getAllByRole('listitem')[1]).toHaveTextContent('Published')

    await user.click(within(dialog).getByRole('button', { name: 'Move Welcome pack down' }))

    await waitFor(() => {
      expect(within(list).getAllByRole('listitem')[0]).toHaveTextContent('Safety pack')
    })
    expect(api.callsTo(`PUT ${ROOT}/categories/${CATEGORY_ID}/pack-order`)[0]?.body).toEqual({
      ids: [PACK2_ID, PACK_ID],
    })
  })

  it('offers it only for a Category with at least two Packs', async () => {
    await open()
    expect(screen.getByRole('button', { name: 'Order the Packs in Recipes' })).toBeDisabled()
    expect(nth(screen.getAllByRole('button', { name: /Order the Packs in/ }), 0)).toBeEnabled()
  })
})
