import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { operator } from '../test/admin.ts'
import { accountFor, empty, FakeApi, json } from '../test/fakeApi.ts'
import { openAccountMenu, signOutViaMenu } from '../test/shell.ts'
import { renderApp } from '../test/renderApp.tsx'
import { clearNavPreference } from '../ui/preferences.ts'

const MEMBERSHIP = { active: false, current_access_ends_at: null, open_ended: false, grants: [] }

function memberApi(overrides: Record<string, unknown> = {}) {
  const account = accountFor({ capabilities: [], ...overrides })
  const api = new FakeApi()
  api.on('GET /api/v1/me', () => json(account))
  api.on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  api.on('GET /api/v1/my/membership', json(MEMBERSHIP))
  return { api, account }
}

beforeEach(() => {
  localStorage.clear()
  clearNavPreference()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.documentElement.removeAttribute('data-theme')
})

describe('the Member frame', () => {
  it('shows Flow Life Commons alone: no Guardian Console subtitle, no rail, no drawer', async () => {
    const { api } = memberApi()
    api.install()
    renderApp('/my')

    expect(await screen.findByRole('heading', { level: 1, name: 'Home' })).toBeVisible()
    expect(screen.getByText('Flow Life Commons')).toBeVisible()
    expect(screen.queryByText('Guardian Console')).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Console' })).not.toBeInTheDocument()
    // No rail/drawer controls: the mobile navigation-sheet button the Console shell has.
    expect(screen.queryByRole('button', { name: 'Navigation menu' })).not.toBeInTheDocument()
  })

  it('offers Home, Membership and Security, in that order, with current-page indication', async () => {
    const { api } = memberApi()
    api.install()
    renderApp('/my/membership')

    await screen.findByRole('heading', { level: 1, name: 'Membership' })
    const nav = screen.getByRole('navigation', { name: 'Member' })
    const links = Array.from(nav.querySelectorAll('a')).map((a) => a.textContent)
    expect(links).toEqual(['Home', 'Membership', 'Security'])
    expect(screen.getByRole('link', { name: 'Membership' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current')
  })

  it('still marks Home current at /my/, the trailing-slash form of its own destination', async () => {
    const { api } = memberApi()
    api.install()
    renderApp('/my/')

    await screen.findByRole('heading', { level: 1, name: 'Home' })
    expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Membership' })).not.toHaveAttribute('aria-current')
  })

  it('moves between destinations by clicking the nav', async () => {
    const { api } = memberApi()
    api.install()
    const user = userEvent.setup()
    renderApp('/my')
    await screen.findByRole('heading', { level: 1, name: 'Home' })

    await user.click(screen.getByRole('link', { name: 'Security' }))
    expect(await screen.findByRole('heading', { level: 1, name: 'Security' })).toBeVisible()
  })

  it("the account menu's security link is /my/security, not /account/security", async () => {
    const { api } = memberApi()
    api.install()
    const user = userEvent.setup()
    renderApp('/my')
    await screen.findByRole('heading', { level: 1, name: 'Home' })

    await openAccountMenu(user)
    expect(screen.getByRole('menuitem', { name: 'Account security' })).toHaveAttribute(
      'href',
      '/my/security',
    )
  })

  it('lets a Member sign out from /my/, the same way the Console does', async () => {
    const { api } = memberApi()
    api.on('POST /api/v1/logout', () => empty())
    api.install()
    const user = userEvent.setup()
    renderApp('/my')
    await screen.findByRole('heading', { level: 1, name: 'Home' })
    // findBy resolves as the DOM appears, before passive effects (heading focus) have run.
    await act(() => Promise.resolve())

    await signOutViaMenu(user)

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(1)
  })
})

describe('Guardian access to /my/ (ADR 0032)', () => {
  it('lets a Guardian visit /my/ directly: holding console.access refuses nothing here', async () => {
    const account = operator()
    const api = new FakeApi()
    api.on('GET /api/v1/me', () => json(account))
    api.on(
      'GET /api/v1/health',
      json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }),
    )
    api.on('GET /api/v1/my/membership', json(MEMBERSHIP))
    api.install()
    renderApp('/my')

    expect(await screen.findByRole('heading', { level: 1, name: 'Home' })).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Access denied' })).not.toBeInTheDocument()
  })

  it("a Guardian's own membership page may truthfully show no membership on record", async () => {
    const account = operator()
    const api = new FakeApi()
    api.on('GET /api/v1/me', () => json(account))
    api.on(
      'GET /api/v1/health',
      json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }),
    )
    api.on('GET /api/v1/my/membership', json(MEMBERSHIP))
    api.install()
    renderApp('/my/membership')

    await screen.findByRole('heading', { level: 1, name: 'Membership' })
    expect(screen.getByText('Inactive')).toBeVisible()
    expect(screen.getByText('No membership history yet')).toBeVisible()
  })
})

describe('reaching /my/ unauthenticated', () => {
  it('is sent to sign in, remembering the path, exactly as the Console is', async () => {
    const api = new FakeApi()
    api.on('GET /api/v1/me', () => json({ message: 'Unauthenticated.' }, 401))
    api.on(
      'GET /api/v1/health',
      json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }),
    )
    api.install()
    renderApp('/my/membership')

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
  })

  it('returns there once signed in, since it is a safe internal path for a non-Console Account', async () => {
    const account = accountFor({ capabilities: [] })
    const api = new FakeApi()
    api.withSession(account, {
      email: 'member@example.org',
      password: 'correct horse battery staple',
    })
    api.on('GET /api/v1/my/membership', json(MEMBERSHIP))
    api.install()
    const user = userEvent.setup()
    renderApp('/my/security')

    await screen.findByRole('heading', { level: 1, name: 'Sign in' })
    await user.type(screen.getByLabelText('Email address'), 'member@example.org')
    await user.type(screen.getByLabelText('Password'), 'correct horse battery staple')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => {
      expect(screen.getByTestId('location').textContent).toBe('/my/security')
    })
    expect(await screen.findByRole('heading', { level: 1, name: 'Security' })).toBeVisible()
  })
})
