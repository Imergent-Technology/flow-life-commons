import { fireEvent, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { pageBody } from '../test/deferred.ts'
import { json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { bodyOf, guardianBoth, serveRecord, volunteerBoth } from '../test/relationshipPages.ts'
import {
  PERSON,
  REL_ID,
  stale,
  wireApprenticeType,
  wireManagement,
  wireRecord,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const G = `/api/v1/admin/relationships/guardians/${PERSON}`
const V = `/api/v1/admin/relationships/volunteers/${PERSON}`
const A = `/api/v1/admin/relationships/apprentices/${PERSON}`

const invalidField = (key: string, message: string, code = 'invalid_relationship_field') =>
  json({ message, code, errors: { [key]: [message] } }, 422)

describe("editing a relationship's fields", () => {
  it('sends only the field that changed, with the instance and revision shown, and nothing else happens', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth({
      management: { fields: { stewardship: 'The garden', recognized_on: '2026-01-02' } },
    })
    api.on(
      `PATCH ${G}/fields`,
      json(
        wireManagement({
          revision: 4,
          fields: { stewardship: 'The orchard', recognized_on: '2026-01-02' },
        }),
      ),
    )
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    const stewardship = screen.getByRole('textbox', { name: 'Area of stewardship' })
    expect(stewardship).toHaveAttribute('maxlength', '200')
    await user.clear(stewardship)
    await user.type(stewardship, 'The orchard')
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    await screen.findByText('Saved.')
    expect(bodyOf(api, `PATCH ${G}/fields`)).toEqual({
      relationship_id: REL_ID,
      revision: 3,
      fields: { stewardship: 'The orchard' },
    })
    expect(screen.getByText('The orchard')).toBeVisible()
    // A field edit is only a field edit: no status, no default role, no verification.
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0)
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
  })

  describe('when the relationship moves on while the editor is open', () => {
    const moved = { revision: 4, fields: { interests: 'Gardening', availability: 'Weekends' } }

    it('closes the editor on the current values, and does not send what the operator only had prefilled', async () => {
      const user = userEvent.setup()
      const { api } = volunteerBoth({
        management: { fields: { interests: 'Gardening', availability: 'Evenings' } },
      })
      renderApp(`/relationships/volunteers/${PERSON}`)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
      await user.click(screen.getByRole('button', { name: 'Edit details' }))
      expect(screen.getByRole('textbox', { name: 'Availability' })).toHaveValue('Evenings')
      await user.type(screen.getByRole('textbox', { name: 'Interests and skills' }), ' and baking')

      // Elsewhere on the page the operator tries a status change that meets someone else's edit: the page re-reads.
      const current = {
        type: 'volunteer',
        transitions: ['active', 'inactive'],
        status: 'pending',
        default_role: null,
        basics: { primary_email: 'ada@example.org', primary_phone: '+1 555 010 0100' },
        ...moved,
      }
      api.on(`PUT ${V}/status`, () => stale(wireManagement(current)))
      api.on(`GET ${V}/management`, json(wireManagement(current)))
      api.on(`GET ${V}`, json(wireRecord({ type: 'volunteer', default_role: null, ...moved })))
      await user.click(screen.getByRole('button', { name: 'Change to Active' }))
      await user.click(
        within(await screen.findByRole('dialog')).getByRole('button', { name: 'Change to Active' }),
      )
      await screen.findByText('This volunteer was changed while you were working.')

      // The editor is gone and the current values are what is shown: no stale draft is waiting to be saved.
      expect(screen.queryByRole('button', { name: 'Save details' })).not.toBeInTheDocument()
      expect(screen.queryByRole('textbox', { name: 'Availability' })).not.toBeInTheDocument()
      expect(screen.getByText('Weekends')).toBeVisible()
      expect(api.callsTo(`PATCH ${V}/fields`)).toHaveLength(0)

      // Editing again starts from the current state, and sends only what is changed there, at the current revision.
      api.on(`PATCH ${V}/fields`, json(wireManagement({ ...current, revision: 5 })))
      await user.click(screen.getByRole('button', { name: 'Edit details' }))
      expect(screen.getByRole('textbox', { name: 'Availability' })).toHaveValue('Weekends')
      await user.type(screen.getByRole('textbox', { name: 'Interests and skills' }), ' and baking')
      await user.click(screen.getByRole('button', { name: 'Save details' }))
      await screen.findByText('Saved.')
      expect(bodyOf(api, `PATCH ${V}/fields`)).toEqual({
        relationship_id: REL_ID,
        revision: 4,
        fields: { interests: 'Gardening and baking' },
      })
    })
  })

  it('sends nothing, and says nothing was saved, when nothing changed', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth()
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    expect(api.callsTo(`PATCH ${G}/fields`)).toHaveLength(0)
    expect(screen.getByRole('button', { name: 'Edit details' })).toBeVisible()
  })

  it('distinguishes an omitted field, a changed one and a field cleared on purpose', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth({
      management: { fields: { stewardship: 'The garden', recognized_on: '2026-01-02' } },
    })
    api.on(
      `PATCH ${G}/fields`,
      json(wireManagement({ revision: 4, fields: { recognized_on: '2026-02-03' } })),
    )
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))

    await user.clear(screen.getByRole('textbox', { name: 'Area of stewardship' }))
    fireDate(screen.getByLabelText('Guardian since'), '2026-02-03')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await screen.findByText('Saved.')
    expect((bodyOf(api, `PATCH ${G}/fields`) as { fields: unknown }).fields).toEqual({
      stewardship: null, // cleared on purpose
      recognized_on: '2026-02-03', // changed
    })
  })

  it("puts the server's word for a field on that field, and keeps the form open and unsaved", async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth()
    api.on(`PATCH ${G}/fields`, invalidField('stewardship', 'Use at most 200 characters.'))
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    await user.type(screen.getByRole('textbox', { name: 'Area of stewardship' }), '!')
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    const field = await screen.findByRole('textbox', { name: 'Area of stewardship' })
    expect(field).toHaveAttribute('aria-invalid', 'true')
    expect(field).toHaveAccessibleDescription('Use at most 200 characters.')
    expect(screen.queryByText('Saved.')).not.toBeInTheDocument()
    expect(screen.getByRole('form', { name: 'Edit Guardian details' })).toBeVisible()
    await expectNoAxeViolations(pageBody())
  })

  it("renders a different type's fields through the same components, with each type's own controls", async () => {
    const user = userEvent.setup()
    const volunteer = volunteerBoth({ management: { fields: { interests: 'Gardening' } } })
    volunteer.api.on(
      `PATCH ${V}/fields`,
      json(
        wireManagement({
          type: 'volunteer',
          revision: 4,
          fields: { interests: 'Gardening', availability: 'Weekends' },
        }),
      ),
    )
    const first = renderApp(`/relationships/volunteers/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    // long_text: a textarea with the definition's limit.
    const interests = screen.getByRole('textbox', { name: 'Interests and skills' })
    expect(interests.tagName).toBe('TEXTAREA')
    expect(interests).toHaveAttribute('maxlength', '500')
    expect(screen.queryByLabelText('Guardian since')).not.toBeInTheDocument()
    await user.type(screen.getByRole('textbox', { name: 'Availability' }), 'Weekends')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await screen.findByText('Saved.')
    expect((bodyOf(volunteer.api, `PATCH ${V}/fields`) as { fields: unknown }).fields).toEqual({
      availability: 'Weekends',
    })
    first.unmount()

    // And a third type, whose controls are a choice, a boolean and a date.
    const apprentice = serveRecord({
      types: [wireApprenticeType()],
      slug: 'apprentices',
      management: () =>
        json(
          wireManagement({
            type: 'apprentice',
            status: 'applied',
            transitions: ['training'],
            fields: { track: 'wood' },
            default_role: null,
            basics: null,
          }),
        ),
      view: () =>
        json(
          wireRecord({
            type: 'apprentice',
            status: {
              key: 'applied',
              since: '2026-09-01T10:00:00Z',
              by: { id: 'p', display_name: 'G' },
            },
            fields: {},
            default_role: null,
            basics: null,
          }),
        ),
    })
    apprentice.api.on(
      `PATCH ${A}/fields`,
      json(
        wireManagement({
          type: 'apprentice',
          revision: 4,
          fields: { track: 'clay', own_tools: true },
        }),
      ),
    )
    renderApp(`/relationships/apprentices/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    const track = screen.getByRole('combobox', { name: 'Track (required)' })
    expect(
      within(track)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Choose…', 'wood', 'metal', 'clay'])
    await user.selectOptions(track, 'clay')
    await user.click(screen.getByRole('checkbox', { name: 'Brings their own tools' }))
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    await screen.findByText('Saved.')
    expect((bodyOf(apprentice.api, `PATCH ${A}/fields`) as { fields: unknown }).fields).toEqual({
      track: 'clay',
      own_tools: true,
    })
  })

  it('will not send a required field emptied, and says why on the field', async () => {
    const user = userEvent.setup()
    const { api } = serveRecord({
      types: [
        wireApprenticeType({
          actions: { view: false, manage: true, delete: true, provision_default_role: false },
        }),
      ],
      slug: 'apprentices',
      management: () =>
        json(
          wireManagement({
            type: 'apprentice',
            status: 'applied',
            transitions: [],
            fields: { track: 'wood' },
            default_role: null,
            basics: null,
          }),
        ),
    })
    renderApp(`/relationships/apprentices/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    await user.selectOptions(screen.getByRole('combobox', { name: 'Track (required)' }), '')
    await user.click(screen.getByRole('button', { name: 'Save details' }))
    expect(await screen.findByText('This field is required.')).toBeVisible()
    expect(api.callsTo(`PATCH ${A}/fields`)).toHaveLength(0)
  })

  it('offers no editor to an operator who may only view', async () => {
    serveRecord({
      types: [
        wireApprenticeType({
          actions: { view: true, manage: false, delete: false, provision_default_role: false },
        }),
      ],
      slug: 'apprentices',
      view: () =>
        json(
          wireRecord({
            type: 'apprentice',
            status: {
              key: 'applied',
              since: '2026-09-01T10:00:00Z',
              by: { id: 'p', display_name: 'G' },
            },
            fields: { mentor: 'Ola' },
            default_role: null,
            basics: null,
          }),
        ),
    })
    renderApp(`/relationships/apprentices/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.getByText('Ola')).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Edit details' })).not.toBeInTheDocument()
  })
})

/** A date input: set by `change`, since typing into one is the browser's business. */
const fireDate = (element: HTMLElement, value: string) =>
  fireEvent.change(element, { target: { value } })
