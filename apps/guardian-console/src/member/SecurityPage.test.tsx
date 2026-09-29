import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { accountFor, empty, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const PASSWORD = 'correct horse battery staple'

function serve(mfaEnrolled: boolean) {
  const api = new FakeApi()
  api.on('GET /api/v1/me', () =>
    json(
      accountFor({
        capabilities: [], // an ordinary Member, not a Guardian
        mfa: {
          enrolled: mfaEnrolled,
          recovery_codes_remaining: mfaEnrolled ? 10 : 0,
          security_verified_until: null,
        },
      }),
    ),
  )
  api.on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  api.install()
  return api
}

async function openSecurity() {
  renderApp('/my/security')
  return screen.findByRole('heading', { level: 1, name: 'Security' })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('an Account with no authenticator', () => {
  it('sees password change and its session, but no two-step verification section and no way to enable one', async () => {
    serve(false)
    await openSecurity()

    expect(screen.getByRole('heading', { level: 2, name: 'Your session' })).toBeVisible()
    expect(screen.getByRole('heading', { level: 2, name: 'Change password' })).toBeVisible()
    expect(screen.queryByRole('heading', { name: 'Two-step verification' })).not.toBeInTheDocument()
    // The one thing this page must never invent: a first-time way in that exists nowhere else in the design.
    expect(screen.queryByRole('button', { name: /enable|set up|turn on/i })).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(
      /enable two-step|set up two-step|turn on two-step/i,
    )
  })
})

describe('an Account that already has an authenticator', () => {
  it('gets the same management operations the Console already permits: regenerate codes, replace it', async () => {
    serve(true)
    await openSecurity()

    expect(screen.getByRole('heading', { level: 2, name: 'Two-step verification' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Generate new recovery codes' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Replace authenticator' })).toBeVisible()
  })
})

describe('password change', () => {
  it('works the same self-service operation the Console already offers to any Account', async () => {
    const api = serve(false)
    api.on('POST /api/v1/password/change', () => {
      api.on('GET /api/v1/me', () =>
        json(
          accountFor({
            capabilities: [],
            mfa: { enrolled: false, recovery_codes_remaining: 0, security_verified_until: null },
          }),
        ),
      )
      return empty()
    })
    const user = userEvent.setup()
    await openSecurity()

    await user.type(screen.getByLabelText('Current password'), PASSWORD)
    await user.type(screen.getByLabelText('New password'), 'a long enough new passphrase')
    await user.type(screen.getByLabelText('Confirm new password'), 'a long enough new passphrase')
    await user.click(screen.getByRole('button', { name: 'Change password' }))

    expect(
      await screen.findByText(
        'Your password has been changed. Other devices have been signed out.',
      ),
    ).toBeVisible()
  })
})

describe("the Guardian Console's own Account security is unchanged", () => {
  it('remains Console-only, still shows two-step verification unconditionally, and its security link is /account/security', async () => {
    const api = new FakeApi()
    api.on('GET /api/v1/me', () => json(accountFor({ capabilities: ['console.access'] })))
    api.on(
      'GET /api/v1/health',
      json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }),
    )
    api.install()
    renderApp('/account/security')

    expect(await screen.findByRole('heading', { level: 1, name: 'Account security' })).toBeVisible()
    expect(screen.getByRole('heading', { level: 2, name: 'Two-step verification' })).toBeVisible()
  })
})
