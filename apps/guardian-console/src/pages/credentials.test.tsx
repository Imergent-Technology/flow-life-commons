import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BrowserRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '../App.tsx'
import { accountFor, empty, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const TOKEN = 'reset-token-0123456789-abcdefghijklmnopqrstuv'
const INVITATION = 'invitation-token-0123456789-abcdefghijklmnopqrs'
const EMAIL = 'ada+ops@example.org'
const NEW_PASSWORD = 'a long and entirely new passphrase'
const OLD_PASSWORD = 'the old passphrase, correctly typed'

let api: FakeApi
const user = () => userEvent.setup()
const where = () => screen.getByTestId('location').textContent

const spyOnConsole = () =>
  (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
    vi.spyOn(console, level).mockImplementation(() => undefined),
  )

function loggedText(spies: ReturnType<typeof spyOnConsole>): string {
  return spies
    .flatMap((spy) => (spy.mock.calls as unknown[][]).flat())
    .map(String)
    .join('\n')
}

beforeEach(() => {
  api = new FakeApi()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  window.history.replaceState(null, '', '/')
})

describe('the passwords screens tell people the real policy', () => {
  it.each([
    ['accept an invitation', `/accept-invitation#token=${INVITATION}`],
    ['reset a password', `/reset-password#token=${TOKEN}&email=${encodeURIComponent(EMAIL)}`],
  ])('to %s', async (_name, route) => {
    api.signedOut().install()
    renderApp(route)

    const field = await screen.findByLabelText('New password')
    expect(field).toHaveAttribute('autocomplete', 'new-password')
    expect(screen.getByLabelText('Confirm new password')).toHaveAttribute(
      'autocomplete',
      'new-password',
    )
    const guidance = field.getAttribute('aria-describedby')
    const text = document.getElementById(guidance ?? '')?.textContent ?? ''
    expect(text).toContain('at least 15 characters')
    expect(text).toContain('Spaces are allowed')
    expect(text).toContain('no required mix')
    expect(text).toContain('72-byte limit')
    // "72 characters" would be false for Unicode: the limit is stated in bytes only.
    expect(text).not.toMatch(/72 characters/i)
  })
})

describe('forgot password', () => {
  it('shows the same words for every answer, whatever the server says about the address', async () => {
    const shown = new Set<string>()
    for (const body of [{ message: 'Sent.' }, { message: 'No such account.' }, {}]) {
      api = new FakeApi()
      api.signedOut().on('POST /api/v1/password/forgot', json(body, 202)).install()
      const view = renderApp('/forgot-password')
      const u = user()

      await u.type(await screen.findByLabelText('Email address'), 'someone@example.org')
      await u.click(screen.getByRole('button', { name: 'Send reset link' }))
      const status = await screen.findByRole('status')
      shown.add(status.textContent)
      expect(document.body.textContent).not.toContain('someone@example.org')
      view.unmount()
    }

    expect([...shown]).toEqual([
      'If an eligible account exists for that address, password reset instructions have been sent.',
    ])
  })

  it('sends only the address, to the intended endpoint', async () => {
    api
      .signedOut()
      .on('POST /api/v1/password/forgot', json({ message: 'ok' }, 202))
      .install()
    renderApp('/forgot-password')
    const u = user()

    await u.type(await screen.findByLabelText('Email address'), EMAIL)
    await u.click(screen.getByRole('button', { name: 'Send reset link' }))
    await screen.findByRole('status')

    expect(api.callsTo('POST /api/v1/password/forgot')).toHaveLength(1)
    expect(api.callsTo('POST /api/v1/password/forgot')[0]?.body).toEqual({ email: EMAIL })
  })

  it('does not reveal an account through rate limiting or an outage', async () => {
    const messages: string[] = []
    for (const response of [
      json({ message: 'Too many.' }, 429, { 'Retry-After': '900' }),
      json({ message: 'down' }, 503),
    ]) {
      api = new FakeApi()
      api.signedOut().on('POST /api/v1/password/forgot', response).install()
      const view = renderApp('/forgot-password')
      const u = user()
      await u.type(await screen.findByLabelText('Email address'), 'someone@example.org')
      await u.click(screen.getByRole('button', { name: 'Send reset link' }))
      messages.push((await screen.findByRole('alert')).textContent)
      view.unmount()
    }

    expect(messages).toEqual([
      'Too many attempts. Try again in about 15 minutes.',
      'The service is temporarily unavailable. Try again in a moment.',
    ])
  })

  it('shows a validation error against the field', async () => {
    api
      .signedOut()
      .on(
        'POST /api/v1/password/forgot',
        json({ message: 'Invalid.', errors: { email: ['Enter a valid email address.'] } }, 422),
      )
      .install()
    renderApp('/forgot-password')
    const u = user()

    await u.type(await screen.findByLabelText('Email address'), 'bad@address')
    await u.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByLabelText('Email address')).toHaveAccessibleDescription(
      'Enter a valid email address.',
    )
  })

  it('uses the email autocomplete hint and can be submitted from the keyboard', async () => {
    api
      .signedOut()
      .on('POST /api/v1/password/forgot', json({ message: 'ok' }, 202))
      .install()
    renderApp('/forgot-password')
    const u = user()

    const field = await screen.findByLabelText('Email address')
    expect(field).toHaveAttribute('autocomplete', 'email')
    await u.type(field, `${EMAIL}{Enter}`)
    expect(await screen.findByRole('status')).toBeVisible()
  })
})

describe('reset password', () => {
  const link = `/reset-password#token=${TOKEN}&email=${encodeURIComponent(EMAIL)}`

  async function fill(
    u: ReturnType<typeof user>,
    password = NEW_PASSWORD,
    confirmation = password,
  ) {
    await u.type(await screen.findByLabelText('New password'), password)
    await u.type(screen.getByLabelText('Confirm new password'), confirmation)
    await u.click(screen.getByRole('button', { name: 'Set new password' }))
  }

  it('reads the token and address from the link, scrubs the address bar, and still uses them', async () => {
    api.signedOut().on('POST /api/v1/password/reset', empty()).install()
    renderApp(link)
    const u = user()

    await screen.findByLabelText('New password')
    expect(where()).toBe('/reset-password') // no fragment left to see
    await fill(u)

    expect(api.callsTo('POST /api/v1/password/reset')[0]?.body).toEqual({
      email: EMAIL, // "+" survived: the link is percent-encoded
      token: TOKEN,
      password: NEW_PASSWORD,
      password_confirmation: NEW_PASSWORD,
    })
  })

  it('confirms, does not sign anyone in, and points to the login page', async () => {
    api.signedOut().on('POST /api/v1/password/reset', empty()).install()
    renderApp(link)
    const u = user()

    await fill(u)

    expect(await screen.findByRole('heading', { name: 'Password changed' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('signed out everywhere')
    expect(screen.getByRole('link', { name: 'Continue to sign in' })).toHaveAttribute(
      'href',
      '/login',
    )
    expect(api.callsTo('POST /api/v1/login')).toHaveLength(0)
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain(NEW_PASSWORD)
  })

  it('re-reads /me afterwards, because the reset ended any session this browser had', async () => {
    let signedIn = true
    api
      .on('GET /api/v1/me', () => (signedIn ? json(accountFor()) : json({ message: 'no' }, 401)))
      .on('POST /api/v1/password/reset', () => {
        signedIn = false
        return empty()
      })
      .install()
    renderApp(link)
    const u = user()
    await screen.findByLabelText('New password')
    const before = api.callsTo('GET /api/v1/me').length

    await fill(u)
    await screen.findByRole('heading', { name: 'Password changed' })

    await waitFor(() => {
      expect(api.callsTo('GET /api/v1/me').length).toBeGreaterThan(before)
    })
  })

  it.each([
    ['no fragment at all', '/reset-password'],
    ['a token without an address', `/reset-password#token=${TOKEN}`],
    ['an address without a token', `/reset-password#email=${encodeURIComponent(EMAIL)}`],
  ])('does not offer a form for %s', async (_name, route) => {
    api.signedOut().install()
    renderApp(route)

    expect(await screen.findByRole('heading', { name: 'Reset link not usable' })).toBeVisible()
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Request a new reset link' })).toHaveAttribute(
      'href',
      '/forgot-password',
    )
  })

  it('treats every refusal of the token alike: invalid, used and expired read the same', async () => {
    api
      .signedOut()
      .on(
        'POST /api/v1/password/reset',
        json(
          {
            message: 'x',
            errors: { token: ['The password reset link is invalid or has expired.'] },
          },
          422,
        ),
      )
      .install()
    renderApp(link)
    const u = user()

    await fill(u)

    expect(await screen.findByRole('heading', { name: 'Reset link not usable' })).toBeVisible()
    expect(screen.getByRole('alert')).toHaveTextContent('invalid, incomplete or has expired')
  })

  it('keeps the form for a refused password, shows the reason, and lets it be corrected', async () => {
    let attempts = 0
    api
      .signedOut()
      .on('POST /api/v1/password/reset', () =>
        ++attempts === 1
          ? json(
              {
                message: 'The password does not meet the requirements.',
                errors: {
                  password: [
                    'This password appears in known data breaches or is too common. Choose a different one.',
                  ],
                },
              },
              422,
            )
          : empty(),
      )
      .install()
    renderApp(link)
    const u = user()

    await fill(u, 'password password password')
    const field = await screen.findByLabelText('New password')
    expect(field).toHaveAccessibleDescription(
      expect.stringContaining('appears in known data breaches'),
    )
    expect(field).toHaveFocus()

    await u.clear(field)
    await u.clear(screen.getByLabelText('Confirm new password'))
    await fill(u)
    expect(await screen.findByRole('heading', { name: 'Password changed' })).toBeVisible()
    // The same token was used both times: a refused password does not spend it.
    expect(
      api.callsTo('POST /api/v1/password/reset').map((c) => (c.body as { token: string }).token),
    ).toEqual([TOKEN, TOKEN])
  })

  it('offers a retry after a temporary failure, with the token still held', async () => {
    let attempts = 0
    api
      .signedOut()
      .on('POST /api/v1/password/reset', () =>
        ++attempts === 1 ? json({ message: 'down' }, 503, { 'Retry-After': '30' }) : empty(),
      )
      .install()
    renderApp(link)
    const u = user()

    await fill(u)
    expect(await screen.findByRole('alert')).toHaveTextContent('temporarily unavailable')
    await u.click(screen.getByRole('button', { name: 'Set new password' }))

    expect(await screen.findByRole('heading', { name: 'Password changed' })).toBeVisible()
  })

  it('keeps the secrets out of storage and logs', async () => {
    const spies = spyOnConsole()
    api.signedOut().on('POST /api/v1/password/reset', empty()).install()
    renderApp(link)
    const u = user()

    await fill(u)
    await screen.findByRole('heading', { name: 'Password changed' })

    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    const logged = loggedText(spies)
    for (const secret of [TOKEN, NEW_PASSWORD, EMAIL]) expect(logged).not.toContain(secret)
    for (const call of api.calls) expect(call.path).not.toMatch(/token|password=|email=/)
  })
})

describe('secret-bearing fragments and the real browser history', () => {
  function openInBrowser(path: string) {
    window.history.pushState(null, '', path)
    return render(
      <BrowserRouter>
        <App />
      </BrowserRouter>,
    )
  }

  it.each([
    ['reset', `/reset-password#token=${TOKEN}&email=${encodeURIComponent(EMAIL)}`, 'New password'],
    ['invitation', `/accept-invitation#token=${INVITATION}`, 'New password'],
  ])(
    'a %s link is scrubbed from the address bar by REPLACING the entry, adding none',
    async (_name, path, label) => {
      api.signedOut().install()
      const push = vi.spyOn(window.history, 'pushState')
      const replace = vi.spyOn(window.history, 'replaceState')
      window.history.pushState(null, '', path) // how the browser arrived: not the app
      push.mockClear()
      const entries = window.history.length

      render(
        <BrowserRouter>
          <App />
        </BrowserRouter>,
      )
      await screen.findByLabelText(label)

      expect(window.location.hash).toBe('')
      expect(window.location.href).not.toContain(TOKEN)
      expect(window.location.href).not.toContain(INVITATION)
      expect(window.location.href).not.toContain('token')
      expect(window.location.search).toBe('')
      expect(window.history.length).toBe(entries) // no additional entry holds the secret
      expect(push).not.toHaveBeenCalled()
      expect(replace).toHaveBeenCalled()
      for (const [, , url] of replace.mock.calls) {
        expect(String(url)).not.toContain('token')
        expect(String(url)).not.toContain('#')
      }
      expect(JSON.stringify(window.history.state)).not.toContain(TOKEN)
      expect(JSON.stringify(window.history.state)).not.toContain(INVITATION)
    },
  )

  it('keeps the values in memory after scrubbing, and loses them on reload', async () => {
    api.signedOut().on('POST /api/v1/password/reset', empty()).install()
    const first = openInBrowser(`/reset-password#token=${TOKEN}&email=${encodeURIComponent(EMAIL)}`)
    const u = user()
    await u.type(await screen.findByLabelText('New password'), NEW_PASSWORD)
    await u.type(screen.getByLabelText('Confirm new password'), NEW_PASSWORD)
    await u.click(screen.getByRole('button', { name: 'Set new password' }))
    await screen.findByRole('heading', { name: 'Password changed' })
    expect((api.callsTo('POST /api/v1/password/reset')[0]?.body as { token: string }).token).toBe(
      TOKEN,
    )
    first.unmount()

    // A reload re-opens the scrubbed URL: there is nothing left to use, and it says so.
    render(
      <BrowserRouter>
        <App />
      </BrowserRouter>,
    )
    expect(await screen.findByRole('heading', { name: 'Reset link not usable' })).toBeVisible()
  })

  it('does not put the secret anywhere script-readable but its own state', async () => {
    api.signedOut().install()
    openInBrowser(`/accept-invitation#token=${INVITATION}`)
    await screen.findByLabelText('New password')

    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    expect(document.cookie).not.toContain(INVITATION)
    expect(document.title).not.toContain(INVITATION)
    expect(document.documentElement.outerHTML).not.toContain(INVITATION)
  })
})

describe('accept an invitation', () => {
  async function fill(u: ReturnType<typeof user>, token: string | null, password = NEW_PASSWORD) {
    if (token !== null) await u.type(await screen.findByLabelText('Invitation token'), token)
    await u.type(await screen.findByLabelText('New password'), password)
    await u.type(screen.getByLabelText('Confirm new password'), password)
    await u.click(screen.getByRole('button', { name: 'Set password and activate' }))
  }

  it('takes a token typed in by hand (the bootstrap prints one; nothing mails a link)', async () => {
    api.signedOut().on('POST /api/v1/invitations/accept', empty()).install()
    renderApp('/accept-invitation')
    const u = user()

    const field = await screen.findByLabelText('Invitation token')
    expect(field).toHaveAttribute('type', 'text')
    expect(field).toHaveAttribute('autocomplete', 'off')
    await fill(u, INVITATION)

    expect(api.callsTo('POST /api/v1/invitations/accept')[0]?.body).toEqual({
      token: INVITATION,
      password: NEW_PASSWORD,
      password_confirmation: NEW_PASSWORD,
    })
  })

  it('takes the token from the link instead, and shows no token field', async () => {
    api.signedOut().on('POST /api/v1/invitations/accept', empty()).install()
    renderApp(`/accept-invitation#token=${INVITATION}`)
    const u = user()

    await screen.findByText('Your invitation link was recognised.')
    expect(screen.queryByLabelText('Invitation token')).not.toBeInTheDocument()
    expect(where()).toBe('/accept-invitation')
    await fill(u, null)

    expect(
      (api.callsTo('POST /api/v1/invitations/accept')[0]?.body as { token: string }).token,
    ).toBe(INVITATION)
  })

  it('succeeds without signing anyone in, and claims nothing about the mailbox', async () => {
    api.signedOut().on('POST /api/v1/invitations/accept', empty()).install()
    renderApp('/accept-invitation')
    const u = user()

    await fill(u, INVITATION)

    expect(await screen.findByRole('heading', { name: 'Invitation accepted' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('You are not signed in yet')
    expect(screen.getByRole('link', { name: 'Continue to sign in' })).toHaveAttribute(
      'href',
      '/login',
    )
    expect(api.callsTo('POST /api/v1/login')).toHaveLength(0)
    expect(document.body.textContent).not.toMatch(/verif|confirmed your email/i)
    // The form (and what was typed into it) is gone.
    expect(screen.queryByLabelText('Invitation token')).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain(INVITATION)
  })

  it('gives one message for every unusable invitation and focuses the token field', async () => {
    api
      .signedOut()
      .on(
        'POST /api/v1/invitations/accept',
        json(
          {
            message: 'The invitation is invalid or has expired.',
            errors: { token: ['The invitation is invalid or has expired.'] },
          },
          422,
        ),
      )
      .install()
    renderApp('/accept-invitation')
    const u = user()

    await fill(u, 'garbage')

    const field = await screen.findByLabelText('Invitation token')
    expect(field).toHaveAccessibleDescription(
      expect.stringContaining('The invitation is invalid or has expired.'),
    )
    expect(field).toHaveFocus()
  })

  it('shows the server’s reason for a refused password, including a breached one', async () => {
    api
      .signedOut()
      .on(
        'POST /api/v1/invitations/accept',
        json(
          {
            message: 'The password does not meet the requirements.',
            errors: {
              password: [
                'This password appears in known data breaches or is too common. Choose a different one.',
              ],
            },
          },
          422,
        ),
      )
      .install()
    renderApp('/accept-invitation')
    const u = user()

    await fill(u, INVITATION, 'password password password')

    const field = await screen.findByLabelText('New password')
    expect(field).toBeInvalid()
    expect(field).toHaveAccessibleDescription(expect.stringContaining('known data breaches'))
    expect(field).toHaveFocus()
  })

  it('reports a mismatched confirmation on the confirmation field', async () => {
    api
      .signedOut()
      .on(
        'POST /api/v1/invitations/accept',
        json(
          {
            message: 'x',
            errors: { password_confirmation: ['The password confirmation does not match.'] },
          },
          422,
        ),
      )
      .install()
    renderApp(`/accept-invitation#token=${INVITATION}`)
    const u = user()

    await u.type(await screen.findByLabelText('New password'), NEW_PASSWORD)
    await u.type(screen.getByLabelText('Confirm new password'), 'something else entirely')
    await u.click(screen.getByRole('button', { name: 'Set password and activate' }))

    expect(await screen.findByLabelText('Confirm new password')).toHaveAccessibleDescription(
      'The password confirmation does not match.',
    )
  })

  it('does not log or store the token or the password', async () => {
    const spies = spyOnConsole()
    api.signedOut().on('POST /api/v1/invitations/accept', empty()).install()
    renderApp('/accept-invitation')
    const u = user()

    await fill(u, INVITATION)
    await screen.findByRole('heading', { name: 'Invitation accepted' })

    const logged = loggedText(spies)
    expect(logged).not.toContain(INVITATION)
    expect(logged).not.toContain(NEW_PASSWORD)
    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
  })
})

/** The success notice. (The Console's Account security page can show another status of its own, about recovery codes.) */
async function passwordChangedNotice(): Promise<HTMLElement> {
  const notice = (await screen.findByText(/Your password has been changed/)).closest(
    '[role="status"]',
  )
  if (!(notice instanceof HTMLElement)) throw new Error('The success notice is not a status.')
  return notice
}

describe('change password', () => {
  // Most of these are an Account WITHOUT an authenticator: it is never asked for a second factor.
  const unenrolled = {
    enrolled: false,
    recovery_codes_remaining: 0,
    security_verified_until: null,
  }
  const signedInAccount = accountFor({ mfa: unenrolled })

  function signedIn(account = signedInAccount) {
    api
      .on('GET /api/v1/me', () => json(account))
      .on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  }

  async function fill(u: ReturnType<typeof user>, current = OLD_PASSWORD, password = NEW_PASSWORD) {
    await u.type(await screen.findByLabelText('Current password'), current)
    await u.type(screen.getByLabelText('New password'), password)
    await u.type(screen.getByLabelText('Confirm new password'), password)
    await u.click(screen.getByRole('button', { name: 'Change password' }))
  }

  it('asks for the current password, and uses the right autocomplete hints', async () => {
    signedIn()
    api.install()
    renderApp('/account/security')

    expect(await screen.findByLabelText('Current password')).toHaveAttribute(
      'autocomplete',
      'current-password',
    )
    expect(screen.getByLabelText('New password')).toHaveAttribute('autocomplete', 'new-password')
    expect(screen.getByLabelText('Confirm new password')).toHaveAttribute(
      'autocomplete',
      'new-password',
    )
    // The account's address is offered to a password manager, hidden from people and assistive tech.
    const hint = document.querySelector('input[autocomplete="username"]')
    expect(hint).toHaveValue(signedInAccount.account.email)
    expect(hint).toHaveAttribute('aria-hidden', 'true')
  })

  it('stays signed in on success, re-reads /me, and confirms without claiming a new session', async () => {
    signedIn()
    api.on('POST /api/v1/password/change', empty()).install()
    renderApp('/account/security')
    const u = user()
    await screen.findByLabelText('Current password')
    const meBefore = api.callsTo('GET /api/v1/me').length
    const expiresBefore = screen.getByText('Ends no later than').nextElementSibling?.textContent

    await fill(u)

    const status = await passwordChangedNotice()
    expect(status).toHaveTextContent(
      'Your password has been changed. Other devices have been signed out.',
    )
    // It does not suggest the session was renewed.
    expect(status).toHaveTextContent('time limit is unchanged')
    expect(status.textContent).not.toMatch(/new session|renewed|extended|restart/i)
    expect(status).toHaveFocus()
    expect(where()).toBe('/account/security') // not sent back to sign in
    expect(api.callsTo('POST /api/v1/login')).toHaveLength(0)
    await waitFor(() => {
      expect(api.callsTo('GET /api/v1/me').length).toBeGreaterThan(meBefore)
    })
    // The server's account of the session is shown, and it is the SAME one: nothing was re-dated.
    expect(screen.getByText('Ends no later than').nextElementSibling?.textContent).toBe(
      expiresBefore,
    )
    expect(screen.getByRole('button', { name: /account menu/i })).toBeVisible()
    // What was typed does not linger.
    expect(screen.getByLabelText('Current password')).toHaveValue('')
    expect(screen.getByLabelText('New password')).toHaveValue('')
    // An Account with no authenticator is not asked for a code, and none is sent.
    expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Recovery code')).not.toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/password/change')[0]?.body).toEqual({
      current_password: OLD_PASSWORD,
      password: NEW_PASSWORD,
      password_confirmation: NEW_PASSWORD,
    })
  })

  it('shows a wrong current password against that field and keeps the session', async () => {
    signedIn()
    api
      .on(
        'POST /api/v1/password/change',
        json(
          {
            message: 'The current password is incorrect.',
            errors: { current_password: ['The current password is incorrect.'] },
          },
          422,
        ),
      )
      .install()
    renderApp('/account/security')
    const u = user()

    await fill(u, 'definitely not it')

    const field = await screen.findByLabelText('Current password')
    expect(field).toHaveAccessibleDescription('The current password is incorrect.')
    expect(field).toHaveFocus()
    expect(screen.getByRole('button', { name: /account menu/i })).toBeVisible()
    expect(where()).toBe('/account/security')
  })

  it('shows the reason a new password is refused, without ending anything', async () => {
    signedIn()
    api
      .on(
        'POST /api/v1/password/change',
        json(
          {
            message: 'The password does not meet the requirements.',
            errors: {
              password: [
                'Use at least 15 characters. A few unrelated words make a strong passphrase.',
              ],
            },
          },
          422,
        ),
      )
      .install()
    renderApp('/account/security')
    const u = user()

    await fill(u, OLD_PASSWORD, 'short')

    const field = await screen.findByLabelText('New password')
    expect(field).toHaveAccessibleDescription(expect.stringContaining('Use at least 15 characters'))
    expect(field).toHaveFocus()
    expect(screen.queryByText(/has been changed/)).not.toBeInTheDocument()
  })

  it.each([
    [
      json({ message: 'Too many.' }, 429, { 'Retry-After': '300' }),
      'Too many attempts. Try again in about 5 minutes.',
    ],
    [
      json({ message: 'down' }, 503),
      'The service is temporarily unavailable. Try again in a moment.',
    ],
  ])('reports %#: rate limiting and a temporary failure', async (response, message) => {
    signedIn()
    api.on('POST /api/v1/password/change', response).install()
    renderApp('/account/security')
    const u = user()

    await fill(u)

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(where()).toBe('/account/security')
  })

  it('does not display or log the passwords, or keep them in storage', async () => {
    const spies = spyOnConsole()
    signedIn()
    api.on('POST /api/v1/password/change', empty()).install()
    renderApp('/account/security')
    const u = user()

    await fill(u)
    await passwordChangedNotice()

    const logged = loggedText(spies)
    expect(logged).not.toContain(OLD_PASSWORD)
    expect(logged).not.toContain(NEW_PASSWORD)
    expect(document.body.textContent).not.toContain(NEW_PASSWORD)
    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    // No session identifier is ever shown: only the times.
    expect(document.body.textContent).not.toMatch(/session id|__Host|flowlife-session/i)
  })
})

describe('change password for an Account with an authenticator', () => {
  const CODE = '123456'
  const RECOVERY = 'ABCDE-FGHIJ-KLMNO-PQRST'

  function serve(account = accountFor()) {
    api
      .on('GET /api/v1/me', () => json(account))
      .on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  }

  async function fillPasswords(u: ReturnType<typeof user>) {
    await u.type(await screen.findByLabelText('Current password'), OLD_PASSWORD)
    await u.type(screen.getByLabelText('New password'), NEW_PASSWORD)
    await u.type(screen.getByLabelText('Confirm new password'), NEW_PASSWORD)
  }

  it('asks for an authenticator code, between the current password and the new one', async () => {
    serve()
    api.install()
    renderApp('/account/security')

    const field = await screen.findByLabelText('Authentication code')
    expect(field).toHaveAttribute('autocomplete', 'one-time-code')
    expect(field).toHaveAttribute('inputmode', 'numeric')
    const [currentField, codeField, newField] = [
      'Current password',
      'Authentication code',
      'New password',
    ].map((label) => screen.getByLabelText(label))
    const follows = (a: HTMLElement | undefined, b: HTMLElement | undefined) =>
      a !== undefined &&
      b !== undefined &&
      (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
    expect(follows(currentField, codeField)).toBe(true)
    expect(follows(codeField, newField)).toBe(true)
  })

  it('sends the code with the passwords, then clears every field and re-reads /me', async () => {
    serve()
    api.on('POST /api/v1/password/change', empty()).install()
    renderApp('/account/security')
    const u = user()
    const meBefore = api.callsTo('GET /api/v1/me').length

    await fillPasswords(u)
    await u.type(screen.getByLabelText('Authentication code'), CODE)
    await u.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await passwordChangedNotice()).toHaveTextContent('Your password has been changed.')
    expect(api.callsTo('POST /api/v1/password/change')[0]?.body).toEqual({
      current_password: OLD_PASSWORD,
      password: NEW_PASSWORD,
      password_confirmation: NEW_PASSWORD,
      code: CODE,
    })
    await waitFor(() => {
      expect(api.callsTo('GET /api/v1/me').length).toBeGreaterThan(meBefore)
    })
    for (const label of [
      'Current password',
      'Authentication code',
      'New password',
      'Confirm new password',
    ]) {
      expect(screen.getByLabelText(label)).toHaveValue('')
    }
  })

  it('switches to a recovery code on request, and sends that INSTEAD of the code', async () => {
    serve()
    api.on('POST /api/v1/password/change', empty()).install()
    renderApp('/account/security')
    const u = user()

    await fillPasswords(u)
    await u.type(screen.getByLabelText('Authentication code'), CODE)
    await u.click(screen.getByRole('button', { name: 'Use a recovery code instead' }))
    // The switch drops what was typed: a code is not carried across to a field it does not belong in.
    expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Recovery code')).toHaveValue('')
    await u.type(screen.getByLabelText('Recovery code'), RECOVERY)
    await u.click(screen.getByRole('button', { name: 'Change password' }))

    await passwordChangedNotice()
    const body = api.callsTo('POST /api/v1/password/change')[0]?.body
    expect(body).toEqual({
      current_password: OLD_PASSWORD,
      password: NEW_PASSWORD,
      password_confirmation: NEW_PASSWORD,
      recovery_code: RECOVERY,
    })
    expect(body).not.toHaveProperty('code')
  })

  it('shows a refused code against its field, focuses it, and does not keep the code', async () => {
    serve()
    api
      .on(
        'POST /api/v1/password/change',
        json(
          { message: 'The code is not valid.', errors: { code: ['The code is not valid.'] } },
          422,
        ),
      )
      .install()
    renderApp('/account/security')
    const u = user()

    await fillPasswords(u)
    await u.type(screen.getByLabelText('Authentication code'), '000000')
    await u.click(screen.getByRole('button', { name: 'Change password' }))

    const field = await screen.findByLabelText('Authentication code')
    await waitFor(() => {
      expect(field).toHaveAccessibleDescription(expect.stringContaining('The code is not valid.'))
      expect(field).toHaveFocus()
    })
    // A code is single use, so the wrong one is dropped; the passwords the person typed stay to retry.
    expect(field).toHaveValue('')
    expect(screen.getByLabelText('Current password')).toHaveValue(OLD_PASSWORD)
    expect(screen.getByLabelText('New password')).toHaveValue(NEW_PASSWORD)
    expect(screen.queryByText(/has been changed/)).not.toBeInTheDocument()
    expect(where()).toBe('/account/security')
  })

  it('shows a refused recovery code on the recovery field', async () => {
    serve()
    api
      .on(
        'POST /api/v1/password/change',
        json(
          {
            message: 'The code is not valid.',
            errors: { recovery_code: ['The code is not valid.'] },
          },
          422,
        ),
      )
      .install()
    renderApp('/account/security')
    const u = user()

    await fillPasswords(u)
    await u.click(screen.getByRole('button', { name: 'Use a recovery code instead' }))
    await u.type(screen.getByLabelText('Recovery code'), RECOVERY)
    await u.click(screen.getByRole('button', { name: 'Change password' }))

    const field = await screen.findByLabelText('Recovery code')
    await waitFor(() => {
      expect(field).toHaveAccessibleDescription(expect.stringContaining('The code is not valid.'))
      expect(field).toHaveFocus()
    })
  })

  it('puts a wrong current password on its own field even when a code was sent', async () => {
    serve()
    api
      .on(
        'POST /api/v1/password/change',
        json(
          {
            message: 'The current password is incorrect.',
            errors: { current_password: ['The current password is incorrect.'] },
          },
          422,
        ),
      )
      .install()
    renderApp('/account/security')
    const u = user()

    await fillPasswords(u)
    await u.type(screen.getByLabelText('Authentication code'), CODE)
    await u.click(screen.getByRole('button', { name: 'Change password' }))

    const field = await screen.findByLabelText('Current password')
    await waitFor(() => {
      expect(field).toHaveFocus()
    })
    expect(field).toHaveAccessibleDescription('The current password is incorrect.')
  })

  it('disables the button while the change is in flight, so one click is one attempt', async () => {
    serve()
    let finish: (response: Response) => void = () => undefined
    api
      .on('POST /api/v1/password/change', () => new Promise<Response>((r) => (finish = r)))
      .install()
    renderApp('/account/security')
    const u = user()

    await fillPasswords(u)
    await u.type(screen.getByLabelText('Authentication code'), CODE)
    await u.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await screen.findByRole('button', { name: 'Changing password…' })).toBeDisabled()
    finish(empty())
    await passwordChangedNotice()
    expect(api.callsTo('POST /api/v1/password/change')).toHaveLength(1)
  })

  it('believes the server over /me: a code asked for of an Account thought to have none appears, and is then sent', async () => {
    // /me says no authenticator (it was enrolled in another tab since). The server decides what is required.
    serve(
      accountFor({
        mfa: { enrolled: false, recovery_codes_remaining: 0, security_verified_until: null },
      }),
    )
    let calls = 0
    api
      .on('POST /api/v1/password/change', () => {
        calls += 1
        return calls === 1
          ? json(
              {
                message: 'Enter a code from your authenticator app, or a recovery code.',
                errors: { code: ['Enter a code from your authenticator app, or a recovery code.'] },
              },
              422,
            )
          : empty()
      })
      .install()
    renderApp('/account/security')
    const u = user()
    await fillPasswords(u)
    expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument()

    await u.click(screen.getByRole('button', { name: 'Change password' }))

    const field = await screen.findByLabelText('Authentication code')
    await waitFor(() => {
      expect(field).toHaveFocus()
    })
    expect(field).toHaveAccessibleDescription(
      expect.stringContaining('Enter a code from your authenticator app, or a recovery code.'),
    )
    expect(api.callsTo('POST /api/v1/password/change')[0]?.body).not.toHaveProperty('code')

    await u.type(field, CODE)
    await u.click(screen.getByRole('button', { name: 'Change password' }))
    await passwordChangedNotice()
    expect(api.callsTo('POST /api/v1/password/change')[1]?.body).toMatchObject({ code: CODE })
  })

  it('does not display, log or store the code', async () => {
    const spies = spyOnConsole()
    serve()
    api.on('POST /api/v1/password/change', empty()).install()
    renderApp('/account/security')
    const u = user()

    await fillPasswords(u)
    await u.type(screen.getByLabelText('Authentication code'), CODE)
    await u.click(screen.getByRole('button', { name: 'Change password' }))
    await passwordChangedNotice()

    expect(loggedText(spies)).not.toContain(CODE)
    expect(document.body.textContent).not.toContain(CODE)
    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
  })
})
