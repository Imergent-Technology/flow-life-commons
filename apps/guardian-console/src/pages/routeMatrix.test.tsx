import { screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { PageWidth } from '../ui/Page.tsx'
import { expectNoAxeViolations } from '../test/a11y.ts'
import { accountFor, FakeApi, json } from '../test/fakeApi.ts'
import { ADMIN_CAPABILITIES, page, serveOperator, TARGET_ID, wire } from '../test/admin.ts'
import { membersPage, PERSON_ID, wireMember } from '../test/membership.ts'
import { renderApp } from '../test/renderApp.tsx'

// Every Console route and full-page screen, rendered for real: one h1, the document named for it, the width
// the task calls for, and no structural accessibility violation. The colours themselves are proved in a
// real browser, in both themes (e2e/accessibility.spec.ts): jsdom has no styles to judge.

afterEach(() => {
  vi.unstubAllGlobals()
})

beforeEach(() => {
  document.title = ''
})

const EVERYTHING = [...ADMIN_CAPABILITIES, 'membership.records.view', 'membership.records.manage']

function serveAll() {
  const api = serveOperator(accountFor({ capabilities: EVERYTHING }))
  api.on('GET /api/v1/admin/accounts', json(page([wire()])))
  api.on(`GET /api/v1/admin/accounts/${TARGET_ID}`, json(wire()))
  api.on('GET /api/v1/admin/members', json(membersPage([wireMember()])))
  api.on(`GET /api/v1/admin/members/${PERSON_ID}`, json(wireMember()))
  api.on('GET /api/v1/admin/members/01J000000000000000TARGETPRS', json(wireMember()))
  return api
}

const consoleRoutes: { path: string; h1: string; width: PageWidth }[] = [
  { path: '/', h1: 'Overview', width: 'detail' },
  { path: '/account/security', h1: 'Account security', width: 'form' },
  { path: '/admin/accounts', h1: 'Accounts', width: 'wide' },
  { path: '/admin/accounts/invite', h1: 'Invite an operator', width: 'form' },
  { path: `/admin/accounts/${TARGET_ID}`, h1: 'Tara Target', width: 'detail' },
  { path: '/admin/members', h1: 'Members', width: 'wide' },
  { path: '/admin/members/new', h1: 'Add a new member', width: 'form' },
  { path: `/admin/members/${PERSON_ID}`, h1: 'Mia Member', width: 'detail' },
  { path: '/no/such/page', h1: 'Page not found', width: 'prose' },
]

describe('every signed-in Console route', () => {
  it.each(consoleRoutes)('$path: $h1, in a $width page', async ({ path, h1, width }) => {
    serveAll()
    renderApp(path)

    expect(await screen.findByRole('heading', { level: 1, name: h1 })).toBeVisible()
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    // The title is set in an effect, and document.title outlives a test: wait for THIS page's, never read the last one's.
    await waitFor(() => {
      expect(document.title).toBe(`${h1} · Flow Life Commons`)
    })
    await waitFor(() => {
      expect(document.querySelector('[data-page-width]')).toHaveAttribute('data-page-width', width)
    })
    expect(document.querySelectorAll('[data-page-width]')).toHaveLength(1)
    await expectNoAxeViolations()
  })

  it('a section the operator may not use is a prose page, not a redirect', async () => {
    serveOperator(accountFor({ capabilities: ['console.access'] }))
    renderApp('/admin/accounts')

    expect(await screen.findByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    expect(document.querySelector('[data-page-width]')).toHaveAttribute('data-page-width', 'prose')
    await expectNoAxeViolations()
  })

  it('never names the product with the retired title', async () => {
    serveAll()
    for (const { path, h1 } of consoleRoutes.slice(0, 3)) {
      const { unmount } = renderApp(path)
      await screen.findByRole('heading', { level: 1, name: h1 })
      expect(document.title).not.toContain('Guardian Console')
      unmount()
    }
  })
})

describe('every full-page screen outside the shell', () => {
  const outside: { path: string; h1: string; server: (api: FakeApi) => void }[] = [
    { path: '/login', h1: 'Sign in', server: (api) => api.signedOut() },
    { path: '/forgot-password', h1: 'Forgot your password?', server: (api) => api.signedOut() },
    { path: '/reset-password', h1: 'Reset link not usable', server: (api) => api.signedOut() },
    { path: '/accept-invitation', h1: 'Accept your invitation', server: (api) => api.signedOut() },
    {
      path: '/',
      h1: 'Access denied',
      server: (api) => api.signedInAs(accountFor({ capabilities: [] })),
    },
  ]

  it.each(outside)('$path: $h1', async ({ path, h1, server }) => {
    const api = new FakeApi()
    server(api)
    api.install()
    renderApp(path)

    expect(await screen.findByRole('heading', { level: 1, name: h1 })).toBeVisible()
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    // The title is set in an effect, and document.title outlives a test: wait for THIS page's, never read the last one's.
    await waitFor(() => {
      expect(document.title).toBe(`${h1} · Flow Life Commons`)
    })
    expect(screen.getByRole('main')).toHaveClass('max-w-[25rem]')
    expect(screen.getByText('Flow Life Commons')).toBeVisible()
    await expectNoAxeViolations()
  })

  it('never tells a shared credential surface it is the Guardian Console (ADR 0032): a Member reaches these too', async () => {
    // "Access denied" is deliberately excluded: it is shown only once a signed-in Account has already
    // been refused the Guardian Console specifically (RequireConsoleAccess), so naming that surface
    // there is not the false universal assumption this proves the absence of.
    for (const { path, server } of outside.filter(({ h1 }) => h1 !== 'Access denied')) {
      const api = new FakeApi()
      server(api)
      api.install()
      const { unmount } = renderApp(path)

      await waitFor(() => {
        expect(document.title).not.toBe('')
      })
      // A substring check, not queryByText: the phrase might sit inside a longer sentence, where an
      // exact-text query would miss it.
      expect(document.body.textContent).not.toContain('Guardian Console')
      unmount()
    }
  })

  it('an unreachable platform is the same frame, with a retry', async () => {
    const api = new FakeApi()
    api.on('GET /api/v1/me', () => Promise.reject(new TypeError('Failed to fetch')))
    api.install()
    renderApp('/')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Service unavailable' }),
    ).toBeVisible()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeVisible()
    expect(screen.getByRole('main')).toHaveClass('max-w-[25rem]')
    await expectNoAxeViolations()
  })
})
