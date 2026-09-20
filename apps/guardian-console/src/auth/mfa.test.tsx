import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { accountFor, empty, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const EMAIL = 'guardian@example.org'
const PASSWORD = 'correct horse battery staple'
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
const URI = `otpauth://totp/Flow%20Life:${EMAIL}?secret=${SECRET}&issuer=Flow%20Life`
const CODES = Array.from({ length: 10 }, (_, i) => `ABCD-EFGH-JKM${String(i)}-PQRS`)
const account = accountFor()

let api: FakeApi

const where = () => screen.getByTestId('location').textContent
const pending = (next: 'challenge' | 'enrollment') =>
  json({ next, expires_at: '2026-09-20T09:10:00Z' }, 202)

/** A server where the password is right and a second factor is due; finishing it starts the session. */
function twoStepServer(next: 'challenge' | 'enrollment') {
  api.withSession(account, { email: EMAIL, password: PASSWORD })
  api.on('POST /api/v1/login', (call) => {
    const body = call.body as { email?: string; password?: string } | null
    return body?.email === EMAIL && body.password === PASSWORD
      ? pending(next)
      : json({ message: 'The provided credentials are incorrect.' }, 401)
  })
  return api
}

async function passwordStep(user: ReturnType<typeof userEvent.setup>) {
  await user.type(await screen.findByLabelText('Email address'), EMAIL)
  await user.type(screen.getByLabelText('Password'), PASSWORD)
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
}

const spyOnConsole = () =>
  (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
    vi.spyOn(console, level).mockImplementation(() => undefined),
  )
const logged = (spies: ReturnType<typeof spyOnConsole>) =>
  spies
    .flatMap((spy) => (spy.mock.calls as unknown[][]).flat())
    .map(String)
    .join('\n')

beforeEach(() => {
  api = new FakeApi()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('the second step of signing in', () => {
  it('shows the code step, and NOT the Console, after a correct password', async () => {
    twoStepServer('challenge').install()
    const user = userEvent.setup()
    renderApp('/login')

    await passwordStep(user)

    expect(await screen.findByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible()
    expect(screen.queryByRole('navigation', { name: 'Console' })).not.toBeInTheDocument()
    // Not authenticated: /me was asked once (at start) and says nobody is signed in.
    expect(api.callsTo('GET /api/v1/me')).toHaveLength(1)
    expect(api.signedIn).toBe(false)
  })

  it('does not keep the password once it has done its job, even if the person starts over', async () => {
    twoStepServer('challenge').install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await screen.findByRole('heading', { name: 'Enter your code' })

    expect(document.body.innerHTML).not.toContain(PASSWORD)
    await user.click(screen.getByRole('button', { name: 'Start over' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(screen.getByLabelText('Password')).toHaveValue('')
  })

  it('finishes with a code, re-reads /me, and only then enters the Console', async () => {
    twoStepServer('challenge').on('POST /api/v1/mfa/challenge', () => {
      api.signedIn = true
      return json(account)
    })
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    const field = await screen.findByLabelText('Authentication code')
    expect(field).toHaveAttribute('autocomplete', 'one-time-code')
    expect(field).toHaveAttribute('inputmode', 'numeric')
    await user.type(field, '123456')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
    expect(api.callsTo('POST /api/v1/mfa/challenge')[0]?.body).toEqual({ code: '123456' })
    const order = api.calls.map((c) => `${c.method} ${c.path}`)
    expect(order.lastIndexOf('GET /api/v1/me')).toBeGreaterThan(
      order.indexOf('POST /api/v1/mfa/challenge'),
    )
  })

  it('returns to the page the person was headed for once the second step is done', async () => {
    twoStepServer('challenge').on('POST /api/v1/mfa/challenge', () => {
      api.signedIn = true
      return json(account)
    })
    api.install()
    const user = userEvent.setup()
    renderApp('/account/security')
    await passwordStep(user)

    await user.type(await screen.findByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { name: 'Account security' })).toBeVisible()
    expect(where()).toBe('/account/security')
  })

  it('takes a recovery code only by a deliberate switch, and sends it as one', async () => {
    twoStepServer('challenge').on('POST /api/v1/mfa/challenge', () => {
      api.signedIn = true
      return json(account)
    })
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    await user.type(await screen.findByLabelText('Authentication code'), '12')
    await user.click(screen.getByRole('button', { name: 'Use a recovery code instead' }))
    const recovery = screen.getByLabelText('Recovery code')
    expect(recovery).toHaveValue('') // switching does not carry a half-typed code across
    expect(recovery).toHaveAttribute('autocomplete', 'off')
    await user.type(recovery, CODES[0] ?? '')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
    expect(api.callsTo('POST /api/v1/mfa/challenge')[0]?.body).toEqual({ recovery_code: CODES[0] })
  })

  it('answers a wrong code in one sentence on the field, clears it, and stays on this step', async () => {
    twoStepServer('challenge').on(
      'POST /api/v1/mfa/challenge',
      json(
        { message: 'The code is not valid.', errors: { code: ['The code is not valid.'] } },
        422,
      ),
    )
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    await user.type(await screen.findByLabelText('Authentication code'), '000000')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    const field = await screen.findByLabelText('Authentication code')
    expect(field).toHaveAccessibleDescription(expect.stringContaining('The code is not valid.'))
    expect(field).toBeInvalid()
    expect(field).toHaveValue('')
    expect(field).toHaveFocus()
    expect(screen.getByRole('heading', { name: 'Enter your code' })).toBeVisible()
  })

  it('sends the person back to the password, with a message, when the sign-in has expired', async () => {
    twoStepServer('challenge').on(
      'POST /api/v1/mfa/challenge',
      json({ message: 'This sign-in has expired. Sign in again.' }, 401),
    )
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    await user.type(await screen.findByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent(
      'Your sign-in timed out. Enter your password again.',
    )
  })

  it('says how long to wait when rate limited', async () => {
    twoStepServer('challenge').on(
      'POST /api/v1/mfa/challenge',
      json({ message: 'Too many.' }, 429, { 'Retry-After': '600' }),
    )
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    await user.type(await screen.findByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many attempts. Try again in about 10 minutes.',
    )
  })

  it('keeps codes and the password out of storage, logs and every request but its own', async () => {
    const spies = spyOnConsole()
    twoStepServer('challenge').on('POST /api/v1/mfa/challenge', () => {
      api.signedIn = true
      return json(account)
    })
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await user.type(await screen.findByLabelText('Authentication code'), '654321')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))
    await screen.findByRole('navigation', { name: 'Console' })

    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    expect(logged(spies)).not.toContain('654321')
    expect(logged(spies)).not.toContain(PASSWORD)
    for (const call of api.calls) {
      expect(call.path).toMatch(/^\/api\/v1\//)
      if (call.path !== '/api/v1/mfa/challenge')
        expect(JSON.stringify(call.body)).not.toContain('654321')
    }
  })
})

describe('enrolling an authenticator on first sign-in', () => {
  function enrolmentServer() {
    twoStepServer('enrollment')
      .on(
        'POST /api/v1/mfa/enrollment',
        json({ secret: SECRET, otpauth_uri: URI, expires_at: '2026-09-20T09:10:00Z' }),
      )
      .on('POST /api/v1/mfa/enrollment/confirm', () => {
        api.signedIn = true
        return json({ recovery_codes: CODES })
      })
    return api
  }

  it('explains why, and generates nothing until the person asks', async () => {
    enrolmentServer().install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Set up two-step verification' }),
    ).toBeVisible()
    expect(screen.getByText(/stolen password is not enough/)).toBeVisible()
    expect(api.callsTo('POST /api/v1/mfa/enrollment')).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Set up authenticator' }))

    expect(
      await screen.findByRole('img', { name: 'QR code for your authenticator app' }),
    ).toBeVisible()
    expect(api.callsTo('POST /api/v1/mfa/enrollment')).toHaveLength(1)
  })

  it('shows a QR code drawn in the browser, and the same secret as a manual key', async () => {
    enrolmentServer().install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))

    const qr = await screen.findByRole('img', { name: 'QR code for your authenticator app' })
    expect(qr.tagName.toLowerCase()).toBe('svg')
    expect(qr.querySelector('path')?.getAttribute('d')?.length).toBeGreaterThan(100)
    // The manual key, in groups of four, for people who cannot scan.
    expect(screen.getByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')).toBeVisible()
    // No image, no request for one: every request the Console made went to the platform API.
    expect(document.querySelector('img')).toBeNull()
    for (const call of api.calls) expect(call.path).toMatch(/^\/api\/v1\//)
    expect(screen.getByLabelText('Authentication code')).toHaveAttribute(
      'autocomplete',
      'one-time-code',
    )
  })

  it('does not enrol on a wrong code: the setup stays for another try', async () => {
    enrolmentServer().on(
      'POST /api/v1/mfa/enrollment/confirm',
      json(
        { message: 'The code is not valid.', errors: { code: ['The code is not valid.'] } },
        422,
      ),
    )
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))

    await user.type(await screen.findByLabelText('Authentication code'), '000000')
    await user.click(screen.getByRole('button', { name: 'Verify and continue' }))

    const field = await screen.findByLabelText('Authentication code')
    expect(field).toHaveAccessibleDescription(expect.stringContaining('The code is not valid.'))
    expect(field).toHaveFocus()
    expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible()
    expect(screen.queryByRole('list', { name: 'Recovery codes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Console' })).not.toBeInTheDocument()
  })

  it('shows the recovery codes once, holds "Continue" until they are acknowledged, and forgets them after', async () => {
    enrolmentServer().install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))
    await user.type(await screen.findByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Verify and continue' }))

    const list = await screen.findByRole('list', { name: 'Recovery codes' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(10)
    expect(screen.getByText(/They will not be shown again/)).toBeVisible()
    // The secret and its QR code are gone from the page; only the codes are on it.
    expect(
      screen.queryByRole('img', { name: 'QR code for your authenticator app' }),
    ).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain(SECRET)
    const proceed = screen.getByRole('button', { name: 'Continue to the Console' })
    expect(proceed).toBeDisabled()
    // Signed in on the server already, but the Console does not open until they say they have saved the codes.
    expect(screen.queryByRole('navigation', { name: 'Console' })).not.toBeInTheDocument()

    await user.click(screen.getByLabelText('I have saved these recovery codes somewhere safe.'))
    await user.click(proceed)

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
    for (const code of CODES) expect(document.body.textContent).not.toContain(code)
  })

  it('copies or downloads the codes ONLY when asked, and never sends them anywhere', async () => {
    enrolmentServer().install()
    const createObjectURL = vi.fn(() => 'blob:codes')
    const revokeObjectURL = vi.fn()
    Object.assign(URL, { createObjectURL, revokeObjectURL })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    const user = userEvent.setup()
    // After setup(): user-event installs its own clipboard stub, which this replaces.
    const writeText = vi.fn(() => Promise.resolve())
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    renderApp('/login')
    await passwordStep(user)
    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))
    await user.type(await screen.findByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Verify and continue' }))
    await screen.findByRole('list', { name: 'Recovery codes' })

    // Showing them copies and downloads nothing.
    expect(writeText).not.toHaveBeenCalled()
    expect(createObjectURL).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Copy codes' }))
    expect(writeText).toHaveBeenCalledWith(CODES.join('\n'))

    await user.click(screen.getByRole('button', { name: 'Download as a file' }))
    expect(createObjectURL).toHaveBeenCalledTimes(1)
    const file = (createObjectURL.mock.calls[0] as unknown as [Blob])[0]
    expect(await file.text()).toContain(CODES[3] ?? '')
    expect(click).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:codes')
    // Client-side only: no request carried them, and nothing was stored.
    for (const call of api.calls) expect(JSON.stringify(call.body)).not.toContain(CODES[0] ?? '')
    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
  })

  it('lets an abandoned setup be restarted with a new key', async () => {
    let n = 0
    enrolmentServer().on('POST /api/v1/mfa/enrollment', () =>
      json({ secret: n++ === 0 ? SECRET : 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U', otpauth_uri: URI }),
    )
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))
    await screen.findByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')

    await user.click(screen.getByRole('button', { name: 'Start over with a new key' }))

    expect(await screen.findByText('MFRG GZDF MZTW Q2LK NNWG 23TP OBYX E43U')).toBeVisible()
    expect(screen.queryByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')).not.toBeInTheDocument()
  })

  it('goes back to the password, with a message, if the sign-in expired before setup', async () => {
    enrolmentServer().on(
      'POST /api/v1/mfa/enrollment',
      json({ message: 'This sign-in has expired. Sign in again.' }, 401),
    )
    api.install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)

    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('Your sign-in timed out')
  })

  it('never stores, logs or resends the secret or the codes', async () => {
    const spies = spyOnConsole()
    enrolmentServer().install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await user.click(await screen.findByRole('button', { name: 'Set up authenticator' }))
    await user.type(await screen.findByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Verify and continue' }))
    await screen.findByRole('list', { name: 'Recovery codes' })

    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    const output = logged(spies)
    for (const secret of [SECRET, URI, ...CODES]) expect(output).not.toContain(secret)
    for (const call of api.calls) {
      expect(JSON.stringify(call.body)).not.toContain(SECRET)
      expect(call.path).not.toContain(SECRET)
    }
  })
})

describe('the guard', () => {
  it('keeps every Console page closed while only a second factor is pending', async () => {
    twoStepServer('challenge').install()
    const user = userEvent.setup()
    renderApp('/login')
    await passwordStep(user)
    await screen.findByRole('heading', { name: 'Enter your code' })

    // Someone (or something) navigates to a Console path in the meantime.
    expect(screen.queryByText(account.person.display_name)).not.toBeInTheDocument()
    expect(empty().status).toBe(204)
    expect(where()).toBe('/login')
  })
})
