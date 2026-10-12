import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { empty, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { dialog, prove, serveRecord, verificationRefused } from '../test/relationshipPages.ts'
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
const G = `/api/v1/admin/relationships/guardians/${PERSON}`

// The Console is told about a change of capabilities by the `/me` it already refreshes after a proof of who is asking (it never
// polls). The definitions follow that `/me`: when the capability set is different, they are read again, and what navigation and
// the pages offer is what the server now serves, with nothing kept from before.
describe('the served definitions follow a change of capabilities', () => {
  it('reads them again when /me reports a different set, and drops what the operator can no longer do', async () => {
    const user = userEvent.setup()
    const { api } = serveRecord({
      types: [wireGuardianType(), wireVolunteerType()],
      slug: 'guardians',
      view: () => json(wireRecord()),
      management: () => json(wireManagement()),
      account: operatorWith('crm.people.view', 'guardians.view', 'guardians.manage'),
    })
    api.on(`PUT ${G}/status`, () => verificationRefused())
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    // Before: both types are in the navigation, for viewing and for recording.
    expect(api.callsTo(TYPES)).toHaveLength(1)
    expect(screen.getAllByRole('link', { name: 'Record a Guardian' }).length).toBeGreaterThan(0)
    expect(screen.getAllByRole('link', { name: 'Record a Volunteer' }).length).toBeGreaterThan(0)

    // The operator's access is reduced behind their back: /me and the definitions now say less.
    api.on('GET /api/v1/me', () => json(operatorWith('crm.people.view')))
    api.on(TYPES, () =>
      json(
        typesOf(
          wireVolunteerType({
            actions: { view: true, manage: false, delete: false, provision_default_role: false },
          }),
        ),
      ),
    )
    api.on('POST /api/v1/security/verify', () => empty())

    // Nothing polls: the next time the Console refreshes /me is after a proof (here, one a Guardian change asks for).
    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
    )
    await prove(user)

    await waitFor(() => {
      expect(api.callsTo(TYPES)).toHaveLength(2)
    })
    // After: the Guardian entries are gone from the navigation, the Volunteer one can only view, and the Guardian page that
    // was open no longer exists for this operator.
    await waitFor(() => {
      expect(screen.queryAllByRole('link', { name: 'Record a Guardian' })).toHaveLength(0)
    })
    expect(await screen.findByRole('heading', { level: 1, name: /not found/i })).toBeVisible()
    expect(screen.queryByRole('button', { name: /Change to/ })).not.toBeInTheDocument()

    // On a page that has a drawer, the Relationships group is what the server now serves: Volunteers to view, nothing to record.
    await user.click(
      within(screen.getByRole('navigation', { name: 'Console' })).getByRole('link', {
        name: 'People',
      }),
    )
    await screen.findByRole('heading', { level: 1, name: 'People' })
    expect(screen.getAllByRole('link', { name: 'Volunteers' }).length).toBeGreaterThan(0)
    expect(screen.queryAllByRole('link', { name: 'Guardians' })).toHaveLength(0)
    expect(screen.queryAllByRole('link', { name: 'Record a Guardian' })).toHaveLength(0)
    expect(screen.queryAllByRole('link', { name: 'Record a Volunteer' })).toHaveLength(0)
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

    const meBefore = api.callsTo('GET /api/v1/me').length
    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
    )
    await prove(user)
    await waitFor(() => {
      expect(api.callsTo('GET /api/v1/me').length).toBeGreaterThan(meBefore)
    })
    expect(api.callsTo(TYPES)).toHaveLength(1)
  })
})
