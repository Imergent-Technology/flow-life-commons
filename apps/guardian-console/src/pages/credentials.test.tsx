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

describe('change password', () => {
  const signedInAccount = accountFor()

  function signedIn() {
    let account = signedInAccount
    api
      .on('GET /api/v1/me', () => json(account))
      .on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
    return {
      rotateSession: () => {
        account = accountFor({
          session: {
            authenticated_at: '2026-09-20T09:45:00Z',
            absolute_expires_at: '2026-09-20T21:45:00Z',
          },
        })
      },
    }
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

  it('stays signed in on success, re-reads /me, and confirms', async () => {
    const server = signedIn()
    api
      .on('POST /api/v1/password/change', () => {
        server.rotateSession() // the server restarted the session's authentication time
        return empty()
      })
      .install()
    renderApp('/account/security')
    const u = user()
    await screen.findByLabelText('Current password')
    const meBefore = api.callsTo('GET /api/v1/me').length
    const startedBefore = screen.getByText('Session started').nextElementSibling?.textContent

    await fill(u)

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Your password has been changed. Other devices have been signed out.',
    )
    expect(screen.getByRole('status')).toHaveFocus()
    expect(where()).toBe('/account/security') // not sent back to sign in
    expect(api.callsTo('POST /api/v1/login')).toHaveLength(0)
    await waitFor(() => {
      expect(api.callsTo('GET /api/v1/me').length).toBeGreaterThan(meBefore)
      expect(screen.getByText('Session started').nextElementSibling?.textContent).not.toBe(
        startedBefore,
      )
    })
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeVisible()
    // What was typed does not linger.
    expect(screen.getByLabelText('Current password')).toHaveValue('')
    expect(screen.getByLabelText('New password')).toHaveValue('')
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
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeVisible()
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
    await screen.findByRole('status')

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
