import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { pageBody } from '../test/deferred.ts'
import { json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { bodyOf, guardianBoth, volunteerBoth } from '../test/relationshipPages.ts'
import { PERSON, REL_ID, REL_ID_2, stale, wireManagement } from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const V = `/api/v1/admin/relationships/volunteers/${PERSON}`
const PAGE = `/relationships/volunteers/${PERSON}`

const managedBasics = (overrides: Record<string, unknown> = {}) =>
  wireManagement({
    type: 'volunteer',
    status: 'pending',
    transitions: ['active', 'inactive'],
    fields: {},
    default_role: null,
    basics: { primary_email: 'Ana@Example.org', primary_phone: '+1 (555) 010-0100' },
    ...overrides,
  })

const stalePersonBasics = (current: Record<string, unknown>) =>
  json({ message: 'Changed.', code: 'stale_person_basics', current }, 409)

async function open(overrides: Record<string, unknown> = {}) {
  const served = volunteerBoth({
    management: {
      basics: { primary_email: 'Ana@Example.org', primary_phone: '+1 (555) 010-0100' },
      ...overrides,
    },
  })
  renderApp(PAGE)
  await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
  return served
}

describe("keeping a Volunteer's basic details up", () => {
  it('shows the three details and an editor, only where the definition enables it', async () => {
    await open({ basics: { primary_email: 'Ana@Example.org', primary_phone: null } })
    const panel = screen.getByRole('region', { name: 'Contact details' })
    expect(within(panel).getByText('Ana@Example.org')).toBeVisible()
    expect(within(panel).getByText('Ada Lovelace')).toBeVisible()
    expect(within(panel).getByRole('button', { name: 'Edit contact details' })).toBeVisible()
  })

  it('offers no editor for a type without the feature, even to a manager', async () => {
    guardianBoth()
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByRole('region', { name: 'Contact details' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit contact details' })).not.toBeInTheDocument()
  })

  it('sends the instance, no revision, and {from, to} for exactly the details that changed, from what was shown', async () => {
    const user = userEvent.setup()
    const { api } = await open()
    api.on(
      `PATCH ${V}/basics`,
      json(
        managedBasics({
          basics: { primary_email: 'ana@example.org', primary_phone: '+1 (555) 010-0100' },
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
    const email = screen.getByRole('textbox', { name: 'Primary email' })
    expect(email).toHaveValue('Ana@Example.org')
    await user.clear(email)
    await user.type(email, 'ana@example.org')
    await user.click(screen.getByRole('button', { name: 'Save contact details' }))

    await screen.findByText('Contact details saved.')
    // Exactly the casing the operator was shown is `from`; the two details left alone are not sent; there is no revision.
    expect(bodyOf(api, `PATCH ${V}/basics`)).toEqual({
      relationship_id: REL_ID,
      primary_email: { from: 'Ana@Example.org', to: 'ana@example.org' },
    })
    // Nothing about login, contact-method ids, profile, tags or roles can be in it.
    expect(Object.keys(bodyOf(api, `PATCH ${V}/basics`) as object)).toEqual([
      'relationship_id',
      'primary_email',
    ])
  })

  it('sends null as from for a detail the person never had, and the exact name for a rename', async () => {
    const user = userEvent.setup()
    const { api } = await open({ basics: { primary_email: null, primary_phone: null } })
    api.on(`PATCH ${V}/basics`, json(managedBasics()))
    await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
    await user.type(screen.getByRole('textbox', { name: 'Primary phone' }), '555 010 0100')
    const name = screen.getByRole('textbox', { name: 'Display name' })
    await user.clear(name)
    await user.type(name, 'Ada King')
    await user.click(screen.getByRole('button', { name: 'Save contact details' }))
    await screen.findByText('Contact details saved.')
    expect(bodyOf(api, `PATCH ${V}/basics`)).toEqual({
      relationship_id: REL_ID,
      display_name: { from: 'Ada Lovelace', to: 'Ada King' },
      primary_phone: { from: null, to: '555 010 0100' },
    })
  })

  it('does not offer to clear a detail, and sends nothing for it', async () => {
    const user = userEvent.setup()
    const { api } = await open()
    await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
    await user.clear(screen.getByRole('textbox', { name: 'Primary email' }))
    await user.click(screen.getByRole('button', { name: 'Save contact details' }))
    expect(await screen.findByText('This cannot be cleared here.')).toBeVisible()
    expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(0)
  })

  it('sends nothing when nothing changed', async () => {
    const user = userEvent.setup()
    const { api } = await open()
    await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
    await user.click(screen.getByRole('button', { name: 'Save contact details' }))
    expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(0)
  })

  it('puts a validation message on the detail it is about', async () => {
    const user = userEvent.setup()
    const { api } = await open()
    api.on(
      `PATCH ${V}/basics`,
      json(
        {
          message: 'Not an email.',
          code: 'invalid_person_basics',
          errors: { primary_email: ['Enter a valid email address.'] },
        },
        422,
      ),
    )
    await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
    const email = screen.getByRole('textbox', { name: 'Primary email' })
    await user.clear(email)
    await user.type(email, 'nope')
    await user.click(screen.getByRole('button', { name: 'Save contact details' }))
    await screen.findByText('Enter a valid email address.')
    expect(screen.getByRole('textbox', { name: 'Primary email' })).toHaveAccessibleDescription(
      'Enter a valid email address.',
    )
  })

  describe('when the details changed since they were shown (409 stale_person_basics)', () => {
    it('keeps what the operator typed, shows the current values beside it, and sends nothing until they decide again', async () => {
      const user = userEvent.setup()
      const { api } = await open()
      api.on(
        `PATCH ${V}/basics`,
        stalePersonBasics({
          display_name: 'Ada Lovelace',
          primary_email: 'ada.new@example.org',
          primary_phone: '+1 (555) 010-0100',
        }),
      )
      await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
      const email = screen.getByRole('textbox', { name: 'Primary email' })
      await user.clear(email)
      await user.type(email, 'ana@example.org')
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))

      await screen.findByText(/changed by someone else while you were editing/)
      const table = screen.getByRole('table', { name: 'Your values beside the current values' })
      const row = within(table).getByRole('row', { name: /Primary email/ })
      expect(within(row).getByText('ana@example.org')).toBeVisible()
      expect(within(row).getByText('ada.new@example.org')).toBeVisible()
      // What the operator typed is still in the form, and nothing was retried.
      expect(screen.getByRole('textbox', { name: 'Primary email' })).toHaveValue('ana@example.org')
      expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(1)
      await expectNoAxeViolations(pageBody())

      // A deliberate second submission compares against what is current now.
      api.on(
        `PATCH ${V}/basics`,
        json(
          managedBasics({
            basics: { primary_email: 'ana@example.org', primary_phone: '+1 (555) 010-0100' },
          }),
        ),
      )
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await screen.findByText('Contact details saved.')
      expect(bodyOf(api, `PATCH ${V}/basics`, 1)).toEqual({
        relationship_id: REL_ID,
        primary_email: { from: 'ada.new@example.org', to: 'ana@example.org' },
      })
    })

    // The values below are chosen so that an old prefilled value sent back would be visible in the body: every detail
    // the other person changed differs from what the editor opened with.
    const savedAfterwards = () =>
      json(
        managedBasics({
          basics: { primary_email: 'ana@example.org', primary_phone: '+1 (555) 010-0100' },
        }),
      )

    async function editThenConflict(
      user: ReturnType<typeof userEvent.setup>,
      edits: Record<string, string>,
      current: { display_name: string; primary_email: string; primary_phone: string },
    ) {
      const { api } = await open()
      api.on(`PATCH ${V}/basics`, stalePersonBasics(current))
      await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
      for (const [label, value] of Object.entries(edits)) {
        const input = screen.getByRole('textbox', { name: label })
        await user.clear(input)
        await user.type(input, value)
      }
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await screen.findByText(/changed by someone else while you were editing/)
      return api
    }

    it("does not turn a detail the operator never touched into their own change: an email edit meets someone else's rename", async () => {
      const user = userEvent.setup()
      const api = await editThenConflict(
        user,
        { 'Primary email': 'ana@example.org' },
        {
          display_name: 'Ada King',
          primary_email: 'Ana@Example.org',
          primary_phone: '+1 (555) 010-0100',
        },
      )
      // The form now shows the rename as current; the operator's email is still theirs.
      expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada King')
      expect(screen.getByRole('textbox', { name: 'Primary email' })).toHaveValue('ana@example.org')
      expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(1) // nothing was retried

      api.on(`PATCH ${V}/basics`, savedAfterwards())
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await screen.findByText('Contact details saved.')
      expect(bodyOf(api, `PATCH ${V}/basics`, 1)).toEqual({
        relationship_id: REL_ID,
        primary_email: { from: 'Ana@Example.org', to: 'ana@example.org' },
      })
    })

    it("does not turn a detail the operator never touched into their own change: a rename meets someone else's phone change", async () => {
      const user = userEvent.setup()
      const api = await editThenConflict(
        user,
        { 'Display name': 'Ada King' },
        {
          display_name: 'Ada Lovelace',
          primary_email: 'Ana@Example.org',
          primary_phone: '+1 555 999 0000',
        },
      )
      expect(screen.getByRole('textbox', { name: 'Primary phone' })).toHaveValue('+1 555 999 0000')
      expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada King')

      api.on(`PATCH ${V}/basics`, savedAfterwards())
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await screen.findByText('Contact details saved.')
      expect(bodyOf(api, `PATCH ${V}/basics`, 1)).toEqual({
        relationship_id: REL_ID,
        display_name: { from: 'Ada Lovelace', to: 'Ada King' },
      })
    })

    it('keeps every detail the operator really edited, and only those, when someone else changed a third', async () => {
      const user = userEvent.setup()
      const api = await editThenConflict(
        user,
        { 'Display name': 'Ada King', 'Primary email': 'ana@example.org' },
        {
          display_name: 'Ada Lovelace',
          primary_email: 'Ana@Example.org',
          primary_phone: '+1 555 999 0000',
        },
      )
      expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada King')
      expect(screen.getByRole('textbox', { name: 'Primary email' })).toHaveValue('ana@example.org')
      expect(screen.getByRole('textbox', { name: 'Primary phone' })).toHaveValue('+1 555 999 0000')

      api.on(`PATCH ${V}/basics`, savedAfterwards())
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await screen.findByText('Contact details saved.')
      expect(bodyOf(api, `PATCH ${V}/basics`, 1)).toEqual({
        relationship_id: REL_ID,
        display_name: { from: 'Ada Lovelace', to: 'Ada King' },
        primary_email: { from: 'Ana@Example.org', to: 'ana@example.org' },
      })
    })

    it("stays safe through a second conflict: the details taken from the first one are still not the operator's", async () => {
      const user = userEvent.setup()
      const api = await editThenConflict(
        user,
        { 'Primary email': 'ana@example.org' },
        {
          display_name: 'Ada King',
          primary_email: 'Ana@Example.org',
          primary_phone: '+1 (555) 010-0100',
        },
      )
      api.on(
        `PATCH ${V}/basics`,
        stalePersonBasics({
          display_name: 'Ada Byron',
          primary_email: 'Ana@Example.org',
          primary_phone: '+1 (555) 010-0100',
        }),
      )
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await waitFor(() => {
        expect(screen.getByRole('textbox', { name: 'Display name' })).toHaveValue('Ada Byron')
      })
      expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(2)

      api.on(`PATCH ${V}/basics`, savedAfterwards())
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      await screen.findByText('Contact details saved.')
      expect(bodyOf(api, `PATCH ${V}/basics`, 2)).toEqual({
        relationship_id: REL_ID,
        primary_email: { from: 'Ana@Example.org', to: 'ana@example.org' },
      })
    })

    it('still offers no clearing after a conflict, even for a detail that was re-based', async () => {
      const user = userEvent.setup()
      const api = await editThenConflict(
        user,
        { 'Primary email': 'ana@example.org' },
        {
          display_name: 'Ada King',
          primary_email: 'Ana@Example.org',
          primary_phone: '+1 (555) 010-0100',
        },
      )
      await user.clear(screen.getByRole('textbox', { name: 'Display name' }))
      await user.click(screen.getByRole('button', { name: 'Save contact details' }))
      expect(await screen.findByText('This cannot be cleared here.')).toBeVisible()
      expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(1)
    })
  })

  it('treats a relationship recorded again as a conflict of the instance, not of the details', async () => {
    const user = userEvent.setup()
    const { api } = await open()
    api.on(`PATCH ${V}/basics`, () =>
      stale(managedBasics({ relationship_id: REL_ID_2, revision: 1 })),
    )
    api.on(`GET ${V}/management`, json(managedBasics({ relationship_id: REL_ID_2, revision: 1 })))
    await user.click(screen.getByRole('button', { name: 'Edit contact details' }))
    const email = screen.getByRole('textbox', { name: 'Primary email' })
    await user.clear(email)
    await user.type(email, 'ana@example.org')
    await user.click(screen.getByRole('button', { name: 'Save contact details' }))
    expect(await screen.findByText('This volunteer was deleted and recorded again.')).toBeVisible()
    expect(api.callsTo(`PATCH ${V}/basics`)).toHaveLength(1)
  })

  it('does not depend on the person being a Guardian or having an Account: it is the Volunteer relationship that scopes it', async () => {
    const { api } = await open()
    // Everything it asked for was the Volunteer routes. Nothing mentions a Guardian, an Account or a role.
    expect(
      api.calls
        .filter((c) => c.path.startsWith('/api/v1/admin/'))
        .every((c) => /relationship|roles/.test(c.path)),
    ).toBe(true)
    expect(
      api.calls.some((c) => c.path.includes('/guardians') || c.path.includes('/accounts')),
    ).toBe(false)
  })
})
