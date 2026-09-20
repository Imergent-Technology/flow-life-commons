import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { accountFor, empty, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const EMAIL = 'guardian@example.org'
const PASSWORD = 'correct horse battery staple'
const NEW_SECRET = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U'
const NEW_URI = `otpauth://totp/Flow%20Life:${EMAIL}?secret=${NEW_SECRET}&issuer=Flow%20Life`
const NEW_CODES = Array.from({ length: 10 }, (_, i) => `WXYZ-ABCD-EFG${String(i)}-HJKM`)

let api: FakeApi
let remaining = 10

function serve(routes: (api: FakeApi) => void = () => undefined) {
  api = new FakeApi()
  api.on('GET /api/v1/me', () =>
    json(
      accountFor({
        mfa: { enrolled: true, recovery_codes_remaining: remaining, security_verified_until: null },
      }),
    ),
  )
  api.on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  routes(api)
  api.install()
}

async function openSecurity() {
  renderApp('/account/security')
  return screen.findByRole('heading', { level: 2, name: 'Two-step verification' })
}

const spyOnConsole = () =>
  (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
    vi.spyOn(console, level).mockImplementation(() => undefined),
  )

beforeEach(() => {
  remaining = 10
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('two-step verification on Account security', () => {
  it('shows that it is on and how many recovery codes are left, and nothing about the factor itself', async () => {
    serve()
    await openSecurity()

    expect(screen.getByText('Authenticator app')).toBeVisible()
    expect(screen.getByText('Recovery codes left').nextElementSibling).toHaveTextContent('10')
    expect(document.body.textContent).not.toMatch(/secret|otpauth|JBSW/i)
  })

  it.each([
    [3, 'running low'],
    [0, 'no recovery codes left'],
  ])('warns when %i codes are left', async (n, text) => {
    remaining = n
    serve()
    await openSecurity()

    expect(screen.getByRole('status')).toHaveTextContent(text)
  })

  it('offers no way to switch it off', async () => {
    serve()
    await openSecurity()

    expect(
      screen.queryByRole('button', { name: /disable|turn off|remove|switch off/i }),
    ).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/disable|turn off two/i)
  })

  it('does not offer management to an Account with no authenticator', async () => {
    api = new FakeApi()
    api.on(
      'GET /api/v1/me',
      json(
        accountFor({
          mfa: { enrolled: false, recovery_codes_remaining: 0, security_verified_until: null },
        }),
      ),
    )
    api.on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 's', checks: {} }))
    api.install()
    await openSecurity()

    expect(screen.getByText(/not set up/)).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Replace authenticator' })).not.toBeInTheDocument()
  })
})

describe('regenerating recovery codes', () => {
  it('asks for fresh proof, shows the new codes once, and re-reads the count', async () => {
    serve((a) =>
      a.on('POST /api/v1/mfa/recovery-codes', () => {
        remaining = 10
        return json({ recovery_codes: NEW_CODES })
      }),
    )
    remaining = 2
    const user = userEvent.setup()
    await openSecurity()

    await user.click(screen.getByRole('button', { name: 'Generate new recovery codes' }))
    const form = screen.getByRole('form', { name: 'Generate new recovery codes' })
    await user.type(within(form).getByLabelText('Current password'), PASSWORD)
    await user.type(within(form).getByLabelText('Authentication code'), '123456')
    await user.click(within(form).getByRole('button', { name: 'Generate codes' }))

    const list = await screen.findByRole('list', { name: 'Recovery codes' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(10)
    expect(screen.getByText(/They will not be shown again/)).toBeVisible()
    expect(api.callsTo('POST /api/v1/mfa/recovery-codes')[0]?.body).toEqual({
      current_password: PASSWORD,
      code: '123456',
    })
    // The Console re-read /me: the count is the server's.
    expect(api.callsTo('GET /api/v1/me').length).toBeGreaterThan(1)

    await user.click(screen.getByLabelText('I have saved these recovery codes somewhere safe.'))
    await user.click(screen.getByRole('button', { name: 'Done' }))

    for (const code of NEW_CODES) expect(document.body.textContent).not.toContain(code)
    expect(screen.getByText('Recovery codes left').nextElementSibling).toHaveTextContent('10')
  })

  it('can use a recovery code as the second proof', async () => {
    serve((a) => a.on('POST /api/v1/mfa/recovery-codes', json({ recovery_codes: NEW_CODES })))
    const user = userEvent.setup()
    await openSecurity()
    await user.click(screen.getByRole('button', { name: 'Generate new recovery codes' }))
    const form = screen.getByRole('form', { name: 'Generate new recovery codes' })

    await user.type(within(form).getByLabelText('Current password'), PASSWORD)
    await user.click(within(form).getByRole('button', { name: 'Use a recovery code instead' }))
    await user.type(within(form).getByLabelText('Recovery code'), 'ABCD-EFGH-JKMN-PQRS')
    await user.click(within(form).getByRole('button', { name: 'Generate codes' }))

    await screen.findByRole('list', { name: 'Recovery codes' })
    expect(api.callsTo('POST /api/v1/mfa/recovery-codes')[0]?.body).toEqual({
      current_password: PASSWORD,
      recovery_code: 'ABCD-EFGH-JKMN-PQRS',
    })
  })

  it('starts the next panel on the authenticator field, whatever the last one was left on', async () => {
    serve((a) => a.on('POST /api/v1/mfa/recovery-codes', json({ recovery_codes: NEW_CODES })))
    const user = userEvent.setup()
    await openSecurity()
    await user.click(screen.getByRole('button', { name: 'Generate new recovery codes' }))
    const regenerate = screen.getByRole('form', { name: 'Generate new recovery codes' })
    await user.click(
      within(regenerate).getByRole('button', { name: 'Use a recovery code instead' }),
    )
    await user.click(within(regenerate).getByRole('button', { name: 'Cancel' }))

    await user.click(screen.getByRole('button', { name: 'Replace authenticator' }))

    const replace = screen.getByRole('form', { name: 'Replace authenticator' })
    expect(within(replace).getByLabelText('Authentication code')).toBeVisible()
    expect(within(replace).queryByLabelText('Recovery code')).not.toBeInTheDocument()
  })

  it('reports a wrong password or code against the field, and changes nothing', async () => {
    serve((a) =>
      a.on(
        'POST /api/v1/mfa/recovery-codes',
        json(
          {
            message: 'The current password is incorrect.',
            errors: { current_password: ['The current password is incorrect.'] },
          },
          422,
        ),
      ),
    )
    const user = userEvent.setup()
    await openSecurity()
    await user.click(screen.getByRole('button', { name: 'Generate new recovery codes' }))
    const form = screen.getByRole('form', { name: 'Generate new recovery codes' })

    await user.type(within(form).getByLabelText('Current password'), 'nope')
    await user.type(within(form).getByLabelText('Authentication code'), '123456')
    await user.click(within(form).getByRole('button', { name: 'Generate codes' }))

    const field = await within(form).findByLabelText('Current password')
    expect(field).toHaveAccessibleDescription('The current password is incorrect.')
    expect(field).toHaveFocus()
    expect(screen.queryByRole('list', { name: 'Recovery codes' })).not.toBeInTheDocument()
  })

  it('sends the person to sign in again if the session has ended', async () => {
    serve((a) =>
      a.on('POST /api/v1/mfa/recovery-codes', json({ message: 'Unauthenticated.' }, 401)),
    )
    const user = userEvent.setup()
    await openSecurity()
    await user.click(screen.getByRole('button', { name: 'Generate new recovery codes' }))
    const form = screen.getByRole('form', { name: 'Generate new recovery codes' })
    await user.type(within(form).getByLabelText('Current password'), PASSWORD)
    await user.type(within(form).getByLabelText('Authentication code'), '123456')
    await user.click(within(form).getByRole('button', { name: 'Generate codes' }))

    // The session was rejected: the shared handling clears the Console and shows the login page.
    api.on('GET /api/v1/me', json({ message: 'Unauthenticated.' }, 401))
    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('Your session has ended')
  })
})

describe('replacing the authenticator', () => {
  const begin = () => json({ secret: NEW_SECRET, otpauth_uri: NEW_URI })

  async function startReplacement(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('button', { name: 'Replace authenticator' }))
    const form = screen.getByRole('form', { name: 'Replace authenticator' })
    await user.type(within(form).getByLabelText('Current password'), PASSWORD)
    await user.type(within(form).getByLabelText('Authentication code'), '123456')
    await user.click(within(form).getByRole('button', { name: 'Continue' }))
  }

  it('proves the password and the current factor first, then shows the new secret once', async () => {
    serve((a) => a.on('POST /api/v1/mfa/authenticator', begin()))
    const user = userEvent.setup()
    await openSecurity()

    await startReplacement(user)

    expect(
      await screen.findByRole('img', { name: 'QR code for your authenticator app' }),
    ).toBeVisible()
    expect(screen.getByText('MFRG GZDF MZTW Q2LK NNWG 23TP OBYX E43U')).toBeVisible()
    expect(api.callsTo('POST /api/v1/mfa/authenticator')[0]?.body).toEqual({
      current_password: PASSWORD,
      code: '123456',
    })
    // Reassures that nothing has changed yet.
    expect(api.callsTo('POST /api/v1/mfa/authenticator/confirm')).toHaveLength(0)
  })

  it('switches only after the new authenticator is proved, and says what happened', async () => {
    serve((a) =>
      a
        .on('POST /api/v1/mfa/authenticator', begin())
        .on('POST /api/v1/mfa/authenticator/confirm', empty()),
    )
    const user = userEvent.setup()
    await openSecurity()
    await startReplacement(user)

    await user.type(await screen.findByLabelText('Code from the new authenticator'), '654321')
    await user.click(screen.getByRole('button', { name: 'Switch to the new authenticator' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Your authenticator has been replaced',
    )
    expect(api.callsTo('POST /api/v1/mfa/authenticator/confirm')[0]?.body).toEqual({
      code: '654321',
    })
    // The new secret is gone from the page.
    expect(document.body.textContent).not.toContain(NEW_SECRET)
    expect(
      screen.queryByRole('img', { name: 'QR code for your authenticator app' }),
    ).not.toBeInTheDocument()
  })

  it('keeps the setup open on a wrong code, so a failed replacement strands no one', async () => {
    serve((a) =>
      a
        .on('POST /api/v1/mfa/authenticator', begin())
        .on(
          'POST /api/v1/mfa/authenticator/confirm',
          json(
            { message: 'The code is not valid.', errors: { code: ['The code is not valid.'] } },
            422,
          ),
        ),
    )
    const user = userEvent.setup()
    await openSecurity()
    await startReplacement(user)

    await user.type(await screen.findByLabelText('Code from the new authenticator'), '000000')
    await user.click(screen.getByRole('button', { name: 'Switch to the new authenticator' }))

    const field = await screen.findByLabelText('Code from the new authenticator')
    expect(field).toHaveAccessibleDescription(expect.stringContaining('The code is not valid.'))
    expect(field).toHaveFocus()
    expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible()
  })

  it('lets the person cancel, leaving everything as it was', async () => {
    serve((a) => a.on('POST /api/v1/mfa/authenticator', begin()))
    const user = userEvent.setup()
    await openSecurity()
    await startReplacement(user)
    await screen.findByRole('img', { name: 'QR code for your authenticator app' })

    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.getByRole('button', { name: 'Replace authenticator' })).toBeVisible()
    expect(document.body.textContent).not.toContain(NEW_SECRET)
  })

  it('keeps the new secret and codes out of storage, logs and every other request', async () => {
    const spies = spyOnConsole()
    serve((a) =>
      a
        .on('POST /api/v1/mfa/authenticator', begin())
        .on('POST /api/v1/mfa/authenticator/confirm', empty()),
    )
    const user = userEvent.setup()
    await openSecurity()
    await startReplacement(user)
    await user.type(await screen.findByLabelText('Code from the new authenticator'), '654321')
    await user.click(screen.getByRole('button', { name: 'Switch to the new authenticator' }))
    await screen.findByText(/has been replaced/)

    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    const output = spies
      .flatMap((spy) => (spy.mock.calls as unknown[][]).flat())
      .map(String)
      .join('\n')
    for (const secret of [NEW_SECRET, NEW_URI, PASSWORD, '654321'])
      expect(output).not.toContain(secret)
    for (const call of api.calls) expect(JSON.stringify(call.body)).not.toContain(NEW_SECRET)
  })
})
