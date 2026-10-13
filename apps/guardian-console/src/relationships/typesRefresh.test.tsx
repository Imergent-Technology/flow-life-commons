import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { empty, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { dialog, serveRecord, verificationRefused } from '../test/relationshipPages.ts'
import {
  operatorWith,
  PERSON,
  typesOf,
  wireGuardianType,
  wireManagement,
  wireRecord,
  wireVolunteerType,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const TYPES = 'GET /api/v1/admin/relationship-types'
const ME = 'GET /api/v1/me'
const G = `/api/v1/admin/relationships/guardians/${PERSON}`
const V = `/api/v1/admin/relationships/volunteers/${PERSON}`

// The Console is told about a change of capabilities by the `/me` it already refreshes after a proof of who is asking (it never
// polls). The definitions follow that `/me`: when the capability set is different they are read again, and what navigation and
// the pages offer is what the server now serves, with nothing kept from before. The server remains the authority throughout.
//
// Each test starts the one refresh the Console has: a mutation that needs a proof. The proof is pasted, not typed key by key,
// and each test stays on one page, so none does more than it must to reach the refresh.

const Volunteers = wireVolunteerType({
  actions: { view: true, manage: false, delete: false, provision_default_role: false },
})

/** The text of every link on the page, read once: a role query over the whole page is the slow way to ask the same thing. */
const linkNames = (): string[] =>
  [...document.querySelectorAll('a')].map((link) => link.textContent.trim())

async function proveByPaste(user: ReturnType<typeof userEvent.setup>) {
  const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
  await user.click(within(prompt).getByLabelText('Current password'))
  await user.paste('the current password')
  await user.click(within(prompt).getByLabelText('Authentication code'))
  await user.paste('123456')
  await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
}

describe('the served definitions follow a change of capabilities', () => {
  it('reads them again when /me reports a different set, and the navigation drops what the operator can no longer do', async () => {
    const user = userEvent.setup()
    const { api } = serveRecord({
      types: [wireGuardianType(), wireVolunteerType()],
      slug: 'volunteers',
      view: () => json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })),
      management: () =>
        json(wireManagement({ type: 'volunteer', fields: {}, default_role: null, basics: null })),
      account: operatorWith('guardians.view', 'guardians.manage', 'volunteers.manage'),
    })
    api.on(`DELETE ${V}`, () => verificationRefused())
    renderApp(`/relationships/volunteers/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    // Before: both types are in the navigation, to view and to record.
    expect(api.callsTo(TYPES)).toHaveLength(1)
    expect(linkNames()).toEqual(
      expect.arrayContaining([
        'Guardians',
        'Volunteers',
        'Record a Guardian',
        'Record a Volunteer',
      ]),
    )

    // The operator's access is reduced behind their back: /me and the definitions now say less.
    api.on(ME, () => json(operatorWith('volunteers.view')))
    api.on(TYPES, () => json(typesOf(Volunteers)))
    api.on('POST /api/v1/security/verify', () => empty())
    await user.click(screen.getByRole('button', { name: /Delete this volunteer/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )
    await proveByPaste(user)

    // After: the definitions were read again, and only Volunteers to view is left. The page stays, as the type is still theirs.
    await waitFor(() => {
      expect(api.callsTo(TYPES)).toHaveLength(2)
    })
    await waitFor(() => {
      expect(linkNames()).not.toContain('Record a Volunteer')
    })
    expect(linkNames()).not.toContain('Guardians')
    expect(linkNames()).not.toContain('Record a Guardian')
    expect(linkNames()).toContain('Volunteers')
    expect(screen.queryByRole('button', { name: /Delete this volunteer/ })).not.toBeInTheDocument()
  })

  it('makes an open page of a type the operator has lost unavailable', async () => {
    const user = userEvent.setup()
    const { api } = serveRecord({
      types: [wireGuardianType(), wireVolunteerType()],
      slug: 'guardians',
      view: () => json(wireRecord()),
      management: () => json(wireManagement()),
      account: operatorWith('guardians.view', 'guardians.manage'),
    })
    api.on(`PUT ${G}/status`, () => verificationRefused())
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    api.on(ME, () => json(operatorWith('volunteers.view')))
    api.on(TYPES, () => json(typesOf(Volunteers)))
    api.on('POST /api/v1/security/verify', () => empty())
    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
    )
    await proveByPaste(user)

    expect(await screen.findByRole('heading', { level: 1, name: /not found/i })).toBeVisible()
    expect(screen.queryByRole('button', { name: /Change to/ })).not.toBeInTheDocument()
    expect(screen.queryByText('Ada Lovelace')).not.toBeInTheDocument()
  })

  it('does not read them again when /me reports the same capabilities', async () => {
    const user = userEvent.setup()
    const { api } = serveRecord({
      types: [wireGuardianType()],
      slug: 'guardians',
      view: () => json(wireRecord()),
      management: () => json(wireManagement()),
      account: operatorWith('guardians.view', 'guardians.manage'),
    })
    api.on(`PUT ${G}/status`, () => verificationRefused())
    api.on('POST /api/v1/security/verify', () => empty())
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    const meBefore = api.callsTo(ME).length
    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
    )
    await proveByPaste(user)
    await waitFor(() => {
      expect(api.callsTo(ME).length).toBeGreaterThan(meBefore)
    })
    expect(api.callsTo(TYPES)).toHaveLength(1)
  })
})
