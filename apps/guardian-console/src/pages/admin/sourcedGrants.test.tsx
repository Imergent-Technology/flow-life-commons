import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { operator, serveOperator, TARGET_ID, wire } from '../../test/admin.ts'
import { pageBody } from '../../test/deferred.ts'
import { json } from '../../test/fakeApi.ts'
import { renderApp } from '../../test/renderApp.tsx'

afterEach(() => {
  vi.unstubAllGlobals()
})

const grant = (overrides: Record<string, unknown> = {}) => ({
  key: 'sourced_key_one',
  name: 'Sourced Access One',
  description: 'Whatever the server says this is.',
  source_type: 'relationship',
  source_label: 'Granted through a relationship',
  source_id: '01J000000000000000RELATION1',
  granted_at: '2026-09-22T08:00:00Z',
  granted_by_account_id: '01J0000000000000000GRANTER1',
  ...overrides,
})

async function open(account: Record<string, unknown>) {
  const api = serveOperator(operator())
  api.on(`GET /api/v1/admin/accounts/${TARGET_ID}`, json(account))
  renderApp(`/admin/accounts/${TARGET_ID}`)
  await screen.findByRole('heading', { level: 1, name: 'Tara Target' })
  return api
}

describe('access an Account holds through a source', () => {
  it('lists sourced grants read-only, apart from the independent assignments, with the source, the time and who granted it', async () => {
    await open(wire({ sourced_grants: [grant()] }))

    const sourced = screen.getByRole('region', { name: 'Access through a source' })
    expect(within(sourced).getByText('Sourced Access One')).toBeVisible()
    expect(within(sourced).getByText('Whatever the server says this is.')).toBeVisible()
    expect(within(sourced).getByText(/Granted through a relationship · granted/)).toBeVisible()
    expect(within(sourced).getByRole('link', { name: 'this account' })).toHaveAttribute(
      'href',
      '/admin/accounts/01J0000000000000000GRANTER1',
    )
    // No way to revoke or edit it from here.
    expect(within(sourced).queryByRole('button')).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /Remove Sourced Access One/ }),
    ).not.toBeInTheDocument()

    // The independent assignment is where it was, with its own Remove, and the sourced grant is not merged into it.
    const access = screen.getByRole('region', { name: 'Access' })
    expect(within(access).getByText('Sample Access One')).toBeVisible()
    expect(within(access).getByRole('button', { name: 'Remove Sample Access One' })).toBeVisible()
    expect(within(access).queryByText('Sourced Access One')).not.toBeInTheDocument()
    await expectNoAxeViolations(pageBody())
  })

  it('does not revoke a sourced grant when an independent assignment of the same role is removed, and offers no way to', async () => {
    // The same role as an independent assignment and as a sourced grant are two rows; this page only ever lists them.
    const api = await open(
      wire({ sourced_grants: [grant({ key: 'custom_key_one', name: 'Sample Access One' })] }),
    )
    expect(screen.getAllByText('Sample Access One')).toHaveLength(2)
    expect(api.calls.filter((c) => c.method === 'DELETE')).toHaveLength(0)
    const sourced = screen.getByRole('region', { name: 'Access through a source' })
    expect(within(sourced).queryByRole('button')).not.toBeInTheDocument()
  })

  describe('removing an independent assignment', () => {
    async function openRemoval(account: Record<string, unknown>) {
      const user = userEvent.setup()
      await open(account)
      await user.click(screen.getByRole('button', { name: 'Remove Sample Access One' }))
      return within(await screen.findByRole('dialog', { name: /Remove .Sample Access One/ }))
    }

    it('says it stops applying on the next request when nothing else grants the same access', async () => {
      const removal = await openRemoval(
        wire({ sourced_grants: [grant({ key: 'another_key', name: 'Another' })] }),
      )
      expect(removal.getByText(/It stops applying on their very next request/)).toBeVisible()
      expect(removal.queryByText(/also holds this access through a source/)).not.toBeInTheDocument()
    })

    it('goes by the role key, not its name: a sourced grant that only shares the name is a different role', async () => {
      const removal = await openRemoval(
        wire({ sourced_grants: [grant({ key: 'another_key', name: 'Sample Access One' })] }),
      )
      expect(removal.getByText(/It stops applying on their very next request/)).toBeVisible()
      expect(removal.queryByText(/also holds this access through a source/)).not.toBeInTheDocument()
      expect(removal.queryByText(/removes the independent assignment/)).not.toBeInTheDocument()
    })

    it('says the access may continue when the same role is also held through a source, and offers no way to remove that', async () => {
      const removal = await openRemoval(
        wire({ sourced_grants: [grant({ key: 'custom_key_one', name: 'Sample Access One' })] }),
      )
      expect(
        removal.getByText(/removes the independent assignment on their very next request/),
      ).toBeVisible()
      expect(removal.getByText(/also holds this access through a source/)).toBeVisible()
      expect(removal.getByText(/so the access may continue to apply/)).toBeVisible()
      expect(
        removal.queryByText(/It stops applying on their very next request/),
      ).not.toBeInTheDocument()
      // Still only this dialog's own two buttons: no control to remove the sourced access.
      expect(removal.getAllByRole('button').map((b) => b.textContent)).toEqual([
        'Cancel',
        'Remove access',
      ])
    })
  })

  it('says a grantor is not recorded by showing none, and still names the source', async () => {
    await open(wire({ sourced_grants: [grant({ granted_by_account_id: null })] }))
    const sourced = screen.getByRole('region', { name: 'Access through a source' })
    expect(within(sourced).queryByRole('link')).not.toBeInTheDocument()
    expect(within(sourced).getByText(/Granted through a relationship/)).toBeVisible()
  })

  it('explains an Account whose only access is sourced, rather than saying it has none', async () => {
    await open(wire({ assignments: [], sourced_grants: [grant()] }))
    expect(
      screen.queryByText('They hold no access. They cannot use the Console.'),
    ).not.toBeInTheDocument()
    expect(screen.getByText(/No access is assigned to them directly/)).toBeVisible()
  })

  it('shows no section, and the old words, when there are none', async () => {
    await open(wire({ assignments: [] }))
    expect(
      screen.queryByRole('region', { name: 'Access through a source' }),
    ).not.toBeInTheDocument()
    expect(screen.getByText('They hold no access. They cannot use the Console.')).toBeVisible()
  })

  it('reads the list from the Account endpoint alone: nothing of Relationships is asked for', async () => {
    const api = await open(wire({ sourced_grants: [grant()] }))
    expect(api.calls.some((c) => c.path.includes('/relationships'))).toBe(false)
  })

  it('refuses an Account response that carries no sourced_grants, rather than guessing there are none', async () => {
    const broken = wire()
    delete broken.sourced_grants
    const api = serveOperator(operator())
    api.on(`GET /api/v1/admin/accounts/${TARGET_ID}`, json(broken))
    renderApp(`/admin/accounts/${TARGET_ID}`)
    expect(await screen.findByRole('alert')).toBeVisible()
  })
})
