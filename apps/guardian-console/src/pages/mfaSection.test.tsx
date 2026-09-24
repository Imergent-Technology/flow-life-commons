import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { accountFor, empty, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const EMAIL = 'guardian@example.org'
const PASSWORD = 'correct horse battery staple'
const NEW_SECRET = 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U'
const NEW_URI = `otpauth://totp/Flow%20Life:${EMAIL}?secret=${NEW_SECRET}&issuer=Flow%20Life`
const EXPIRES_AT = '2026-09-20T16:12:00Z'
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

  it('manages an authenticator that exists and never offers first-time setup: that is part of signing in', async () => {
    serve()
    await openSecurity()

    // Exactly the two things that can be done to an existing authenticator...
    expect(screen.getByRole('button', { name: 'Generate new recovery codes' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Replace authenticator' })).toBeVisible()
    // ...and no way to start from nothing, from here.
    expect(
      screen.queryByRole('button', { name: /set up|enrol|enroll|add an authenticator/i }),
    ).not.toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/not set up/i)
    expect(api.callsTo('POST /api/v1/mfa/enrollment')).toHaveLength(0)
    expect(api.callsTo('POST /api/v1/mfa/enrollment/confirm')).toHaveLength(0)
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
  const begin = () => json({ secret: NEW_SECRET, otpauth_uri: NEW_URI, expires_at: EXPIRES_AT })

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

  it('shows the setup key with a copy button, and when the server says it expires', async () => {
    serve((a) => a.on('POST /api/v1/mfa/authenticator', begin()))
    const user = userEvent.setup()
    await openSecurity()

    await startReplacement(user)

    expect(await screen.findByRole('button', { name: 'Copy setup key' })).toBeVisible()
    const time = new Date(EXPIRES_AT).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
    expect(screen.getByText(new RegExp(`This setup key expires at ${time}`))).toBeVisible()
  })

  it('copies the new key without a request, and the copied key is the one on the screen', async () => {
    serve((a) => a.on('POST /api/v1/mfa/authenticator', begin()))
    const user = userEvent.setup()
    const writeText = vi.fn(() => Promise.resolve())
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    await openSecurity()
    await startReplacement(user)
    const calls = api.calls.length

    await user.click(await screen.findByRole('button', { name: 'Copy setup key' }))

    expect(writeText).toHaveBeenCalledExactlyOnceWith(NEW_SECRET)
    expect(api.calls).toHaveLength(calls) // the copy asked the server nothing
    expect(screen.getByText('MFRG GZDF MZTW Q2LK NNWG 23TP OBYX E43U')).toBeVisible()
  })

  it('takes the new authenticator code as digits only, and does not submit by itself', async () => {
    serve((a) => a.on('POST /api/v1/mfa/authenticator', begin()))
    const user = userEvent.setup()
    await openSecurity()
    await startReplacement(user)

    const field = await screen.findByLabelText('Code from the new authenticator')
    await user.click(field)
    await user.paste('654 321')

    expect(field).toHaveValue('654321')
    await user.type(field, '9')
    expect(field).toHaveValue('654321')
    expect(api.callsTo('POST /api/v1/mfa/authenticator/confirm')).toHaveLength(0) // only the button sends it
  })

  describe('when the server says there is no pending setup to prove', () => {
    const gone = () =>
      json(
        {
          message: 'There is no authenticator setup in progress. Start again.',
          errors: { authenticator: ['There is no authenticator setup in progress. Start again.'] },
        },
        422,
      )

    it('takes the dead QR code and key off the page, and says what to do', async () => {
      serve((a) =>
        a
          .on('POST /api/v1/mfa/authenticator', begin())
          .on('POST /api/v1/mfa/authenticator/confirm', gone()),
      )
      const user = userEvent.setup()
      await openSecurity()
      await startReplacement(user)
      await user.type(await screen.findByLabelText('Code from the new authenticator'), '654321')

      await user.click(screen.getByRole('button', { name: 'Switch to the new authenticator' }))

      const alert = await screen.findByRole('alert')
      expect(alert).toHaveTextContent('That setup is no longer valid')
      expect(alert).toHaveFocus()
      // Nothing of the expired setup is left to scan, read or copy.
      expect(
        screen.queryByRole('img', { name: 'QR code for your authenticator app' }),
      ).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Copy setup key' })).not.toBeInTheDocument()
      expect(screen.queryByLabelText('Code from the new authenticator')).not.toBeInTheDocument()
      expect(document.body.textContent).not.toContain(NEW_SECRET)
      expect(document.body.textContent).not.toContain('MFRG GZDF')
      // And the way to begin again is right there: the proof step, not a dead end.
      expect(screen.getByRole('form', { name: 'Replace authenticator' })).toBeVisible()
    })

    it('lets the person begin again and get a fresh setup', async () => {
      const second = 'KRSXG5CTMVRXEZLUKN2XGZLSMVZG65DI'
      let starts = 0
      serve((a) =>
        a
          .on('POST /api/v1/mfa/authenticator', () =>
            starts++ === 0
              ? begin()
              : json({
                  secret: second,
                  otpauth_uri: `otpauth://totp/Flow%20Life:${EMAIL}?secret=${second}&issuer=Flow%20Life`,
                  expires_at: '2026-09-20T16:40:00Z',
                }),
          )
          .on('POST /api/v1/mfa/authenticator/confirm', gone()),
      )
      const user = userEvent.setup()
      await openSecurity()
      await startReplacement(user)
      await user.type(await screen.findByLabelText('Code from the new authenticator'), '654321')
      await user.click(screen.getByRole('button', { name: 'Switch to the new authenticator' }))
      await screen.findByRole('alert')

      // The proof fields start empty (nothing is kept), and asking again works as it did the first time.
      const form = screen.getByRole('form', { name: 'Replace authenticator' })
      expect(within(form).getByLabelText('Current password')).toHaveValue('')
      await user.type(within(form).getByLabelText('Current password'), PASSWORD)
      await user.type(within(form).getByLabelText('Authentication code'), '123456')
      await user.click(within(form).getByRole('button', { name: 'Continue' }))

      expect(await screen.findByText('KRSX G5CT MVRX EZLU KN2X GZLS MVZG 65DI')).toBeVisible()
      expect(screen.queryByText('MFRG GZDF MZTW Q2LK NNWG 23TP OBYX E43U')).not.toBeInTheDocument()
      expect(api.callsTo('POST /api/v1/mfa/authenticator')).toHaveLength(2)
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })

    it('does not treat a wrong code as an expired setup: that keeps the setup open', async () => {
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

      // One error, announced once (not one above the form and another inside it).
      expect(await screen.findByRole('alert')).toHaveTextContent('The code is not valid.')
      expect(screen.getAllByRole('alert')).toHaveLength(1)
      expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible()
      expect(screen.getByText('MFRG GZDF MZTW Q2LK NNWG 23TP OBYX E43U')).toBeVisible()
    })
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
