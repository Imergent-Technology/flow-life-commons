import { act, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { accountFor, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const EMAIL = 'guardian@example.org'
const PASSWORD = 'correct horse battery staple'
const account = accountFor()

let api: FakeApi

const where = () => screen.getByTestId('location').textContent

async function signInThroughTheForm(user: ReturnType<typeof userEvent.setup>, password = PASSWORD) {
  const email = await screen.findByLabelText('Email address')
  await user.clear(email)
  await user.type(email, EMAIL)
  await user.type(screen.getByLabelText('Password'), password)
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
}

beforeEach(() => {
  api = new FakeApi()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('resolving who is signed in', () => {
  it('asks GET /me first, and shows nothing of the Console until it answers', async () => {
    let answer: (response: Response) => void = () => undefined
    api
      .on('GET /api/v1/me', () => new Promise<Response>((resolve) => (answer = resolve)))
      .on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
      .install()

    renderApp('/')

    expect(screen.getByRole('status')).toHaveTextContent('Checking your session…')
    expect(screen.queryByText(account.person.display_name)).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Sign in' })).not.toBeInTheDocument()
    expect(api.calls.map((c) => c.path)).toContain('/api/v1/me')

    await act(async () => {
      answer(json(account))
      await Promise.resolve()
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Flow Life Guardian Console' }),
    ).toBeVisible()
  })

  it('shows the signed-in Account: name, address, session times, sign out, and the way to Account security', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.install()
    renderApp('/')

    expect(
      await screen.findByText(account.person.display_name, { selector: 'strong' }),
    ).toBeVisible()
    expect(screen.getByText(new RegExp(EMAIL))).toBeVisible()
    expect(screen.getByText('Session started')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeVisible()
    expect(screen.getByRole('link', { name: 'Account security' })).toHaveAttribute(
      'href',
      '/account/security',
    )
  })

  it('sends someone nobody knows to the login page, with no message guessing why', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    renderApp('/')

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(where()).toBe('/login')
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('does not call an unreachable API "signed out": it offers a retry', async () => {
    let up = false
    api
      .withSession(account, { email: EMAIL, password: PASSWORD })
      .on('GET /api/v1/me', () => (up ? json(account) : json({ message: 'down' }, 503)))
    api.install()
    const user = userEvent.setup()
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Service unavailable' })).toBeVisible()
    expect(where()).toBe('/') // not redirected to login

    up = true
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(
      await screen.findByText(account.person.display_name, { selector: 'strong' }),
    ).toBeVisible()
  })

  it('never polls: hours pass with no further requests', async () => {
    // Fake timers go in BEFORE the app renders, so any interval or timeout it creates is one we control.
    // (Installed afterwards they would never see a timer the app had already started.)
    vi.useFakeTimers({ shouldAdvanceTime: true })
    api.withSession(account, { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.install()
    renderApp('/')
    await screen.findByText(account.person.display_name, { selector: 'strong' })
    await screen.findByText('API ok')

    const before = api.calls.length
    await vi.advanceTimersByTimeAsync(3 * 60 * 60 * 1000)

    expect(api.calls).toHaveLength(before)
  })
})

describe('Console entry is a capability, not a role', () => {
  it('enters the Console for an Account that holds console.access', async () => {
    api.withSession(accountFor({ capabilities: ['access.roles.assign', 'console.access'] }), {
      email: EMAIL,
      password: PASSWORD,
    })
    api.signedIn = true
    api.install()
    renderApp('/')

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
  })

  it.each([[[]], [['access.roles.assign']]])(
    'shows access-denied, not the login page, when the capabilities are %j',
    async (capabilities: string[]) => {
      api.withSession(accountFor({ capabilities }), { email: EMAIL, password: PASSWORD })
      api.signedIn = true
      api.install()
      renderApp('/')

      expect(await screen.findByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
      expect(where()).toBe('/') // no redirect, so no loop back to login
      expect(screen.getByRole('button', { name: 'Sign out' })).toBeVisible()
      expect(screen.queryByRole('navigation', { name: 'Console' })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Account security' })).not.toBeInTheDocument()
      // Nothing about what grants access, or what this account lacks.
      expect(document.body.textContent).not.toMatch(/role|capabilit|console\.access|administrator/i)
    },
  )

  it('shows access-denied on every Console path, including Account security', async () => {
    api.withSession(accountFor({ capabilities: [] }), { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.install()
    renderApp('/account/security')

    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeVisible()
  })

  it('lets an Account without access sign out from the access-denied page', async () => {
    api.withSession(accountFor({ capabilities: [] }), { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.install()
    const user = userEvent.setup()
    renderApp('/')

    await user.click(await screen.findByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeVisible()
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(1)
  })
})

describe('signing in', () => {
  it('signs in, then trusts /me rather than the login reply, and enters the Console', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user)

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
    expect(where()).toBe('/')
    expect(api.callsTo('POST /api/v1/login')[0]?.body).toEqual({ email: EMAIL, password: PASSWORD })
    const order = api.calls.map((c) => `${c.method} ${c.path}`)
    expect(order.lastIndexOf('GET /api/v1/me')).toBeGreaterThan(order.indexOf('POST /api/v1/login'))
  })

  it('does not take the login reply as the answer: /me decides whether the Console opens', async () => {
    // The login reply claims the capability; /me (the canonical projection) says otherwise.
    api
      .withSession(accountFor({ capabilities: [] }), { email: EMAIL, password: PASSWORD })
      .on('POST /api/v1/login', () => {
        api.signedIn = true
        return json(accountFor({ capabilities: ['console.access'] }))
      })
      .install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user)

    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeVisible()
    expect(screen.queryByRole('navigation', { name: 'Console' })).not.toBeInTheDocument()
  })

  it('gives one message for every refusal, whatever the server says about it', async () => {
    const bodies = [
      { message: 'The provided credentials are incorrect.' },
      { message: 'This account is disabled.' },
      { message: 'No such user.' },
      {},
    ]
    const seen = new Set<string>()
    for (const body of bodies) {
      api = new FakeApi()
      api
        .withSession(account, { email: EMAIL, password: PASSWORD })
        .on('POST /api/v1/login', json(body, 401))
        .install()
      const user = userEvent.setup()
      const view = renderApp('/login')

      await signInThroughTheForm(user, 'wrong password entirely')
      const alert = await screen.findByRole('alert')
      seen.add(alert.textContent)
      view.unmount()
    }

    expect([...seen]).toEqual(['The email address or password is incorrect.'])
  })

  it('keeps the email, clears the refused password, and moves focus to the message', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user, 'not the password')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveFocus()
    expect(screen.getByLabelText('Email address')).toHaveValue(EMAIL)
    expect(screen.getByLabelText('Password')).toHaveValue('')
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled()
  })

  it('says how long to wait when rate limited, and asks nothing about the account', async () => {
    api
      .withSession(account, { email: EMAIL, password: PASSWORD })
      .on('POST /api/v1/login', json({ message: 'Too many.' }, 429, { 'Retry-After': '600' }))
      .install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user)

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many attempts. Try again in about 10 minutes.',
    )
  })

  it('reports a temporary failure as one', async () => {
    api
      .withSession(account, { email: EMAIL, password: PASSWORD })
      .on('POST /api/v1/login', json({ message: 'down' }, 503))
      .install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user)

    expect(await screen.findByRole('alert')).toHaveTextContent('temporarily unavailable')
  })

  it('ties a validation error to the email field for screen readers', async () => {
    api
      .withSession(account, { email: EMAIL, password: PASSWORD })
      .on(
        'POST /api/v1/login',
        json({ message: 'Invalid.', errors: { email: ['Enter a valid email address.'] } }, 422),
      )
      .install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user)

    const field = await screen.findByLabelText('Email address')
    expect(field).toBeInvalid()
    expect(field).toHaveAccessibleDescription('Enter a valid email address.')
    expect(field).toHaveFocus()
  })

  it('uses the right autocomplete hints for a password manager', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    renderApp('/login')

    expect(await screen.findByLabelText('Email address')).toHaveAttribute(
      'autocomplete',
      'username',
    )
    expect(screen.getByLabelText('Password')).toHaveAttribute('autocomplete', 'current-password')
  })

  it('submits from the keyboard', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/login')

    await user.type(await screen.findByLabelText('Email address'), EMAIL)
    await user.type(screen.getByLabelText('Password'), `${PASSWORD}{Enter}`)

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
  })

  it('sends a signed-in visitor on from the login page', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.install()
    renderApp('/login')

    await screen.findByRole('navigation', { name: 'Console' })
    expect(where()).toBe('/')
  })

  it('returns to the internal page the person was headed for, and only that', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/account/security')

    await signInThroughTheForm(user)

    expect(await screen.findByRole('heading', { name: 'Account security' })).toBeVisible()
    expect(where()).toBe('/account/security')
  })

  it('does not follow an external return target planted in router state', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    // A guard would only ever set an internal path; prove a hostile one is refused anyway.
    const view = renderApp('/login')
    view.unmount()
    const { MemoryRouter } = await import('react-router')
    const { default: App } = await import('../App.tsx')
    const { render } = await import('@testing-library/react')
    render(
      <MemoryRouter
        initialEntries={[{ pathname: '/login', state: { from: 'https://evil.example/' } }]}
      >
        <App />
      </MemoryRouter>,
    )

    await signInThroughTheForm(user)

    expect(await screen.findByRole('navigation', { name: 'Console' })).toBeVisible()
  })
})

describe('signing out', () => {
  async function signedInAt(path = '/') {
    api.withSession(account, { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.install()
    const view = renderApp(path)
    await screen.findByRole('button', { name: 'Sign out' })
    return view
  }

  it('ends the session, clears what the Console held, and shows the login page', async () => {
    const user = userEvent.setup()
    await signedInAt('/account/security')

    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('You have been signed out.')
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(1)
    expect(screen.queryByText(account.person.display_name)).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain(EMAIL)
    // Not sent back to the page just left: the next person at this browser should start fresh.
    expect(where()).toBe('/login')
  })

  it('does not send the next person to the page the last one left', async () => {
    const user = userEvent.setup()
    await signedInAt('/account/security')
    await user.click(screen.getByRole('button', { name: 'Sign out' }))
    await screen.findByRole('heading', { name: 'Sign in' })

    await signInThroughTheForm(user)

    expect(await screen.findByRole('heading', { name: 'Flow Life Guardian Console' })).toBeVisible()
    expect(where()).toBe('/')
  })

  it('still ends in a clean signed-out state when the session was already gone', async () => {
    const user = userEvent.setup()
    await signedInAt()
    // The server no longer knows the session; its token is stale too (419 twice), then /me says 401.
    api.on('POST /api/v1/logout', json({ message: 'CSRF token mismatch.' }, 419))
    api.signedIn = false

    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeVisible()
    expect(screen.queryByText(account.person.display_name)).not.toBeInTheDocument()
  })

  it('does NOT claim to be signed out when the server could not be told', async () => {
    const user = userEvent.setup()
    await signedInAt()
    api.on('POST /api/v1/logout', json({ message: 'down' }, 503))

    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('You could not be signed out')
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeEnabled()
    expect(screen.getByRole('navigation', { name: 'Console' })).toBeVisible()
  })
})

describe('an ended session', () => {
  it('sends the person to sign in again, says the session ended, and keeps a safe way back', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD })
    api.signedIn = true
    api.on('POST /api/v1/password/change', () => {
      api.signedIn = false // expired on the server: 30 minutes without a request, or 12 hours
      return json({ message: 'Unauthenticated.' }, 401)
    })
    api.install()
    const user = userEvent.setup()
    renderApp('/account/security')

    await user.type(await screen.findByLabelText('Current password'), 'old password value')
    await user.type(screen.getByLabelText('New password'), 'a brand new long passphrase')
    await user.type(screen.getByLabelText('Confirm new password'), 'a brand new long passphrase')
    await user.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent(
      'Your session has ended. Sign in again to continue.',
    )
    expect(screen.queryByText(account.person.display_name)).not.toBeInTheDocument()

    await signInThroughTheForm(user)
    expect(await screen.findByRole('heading', { name: 'Account security' })).toBeVisible()
    expect(where()).toBe('/account/security')
  })

  it('shows no message on a first visit, when it cannot know why nobody is signed in', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    renderApp('/account/security')

    await screen.findByRole('heading', { name: 'Sign in' })
    expect(within(document.body).queryByRole('status')).not.toBeInTheDocument()
  })
})

describe('what the Console keeps', () => {
  it('writes nothing to browser storage or cookies across sign-in and sign-out', async () => {
    const cookiesBefore = document.cookie
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user)
    await screen.findByRole('navigation', { name: 'Console' })
    await user.click(screen.getByRole('button', { name: 'Sign out' }))
    await screen.findByRole('heading', { name: 'Sign in' })

    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    expect(document.cookie).toBe(cookiesBefore)
  })

  it('never logs a password', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
      vi.spyOn(console, level).mockImplementation(() => undefined),
    )
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/login')

    await signInThroughTheForm(user, 'wrong wrong wrong wrong')
    await screen.findByRole('alert')
    await signInThroughTheForm(user)
    await screen.findByRole('navigation', { name: 'Console' })

    const logged = spies
      .flatMap((spy) => (spy.mock.calls as unknown[][]).flat())
      .map(String)
      .join('\n')
    expect(logged).not.toContain(PASSWORD)
    expect(logged).not.toContain('wrong wrong wrong wrong')
    for (const spy of spies) spy.mockRestore()
  })

  it('calls only same-origin API paths, and never asks the browser to include cross-origin credentials', async () => {
    api.withSession(account, { email: EMAIL, password: PASSWORD }).install()
    const user = userEvent.setup()
    renderApp('/login')
    await signInThroughTheForm(user)
    await screen.findByRole('navigation', { name: 'Console' })
    await user.click(screen.getByRole('button', { name: 'Sign out' }))
    await screen.findByRole('heading', { name: 'Sign in' })

    expect(api.calls.length).toBeGreaterThan(3)
    for (const call of api.calls) {
      expect(call.path).toMatch(/^\/api\/v1\//)
      expect(call.init.credentials).not.toBe('include')
    }
  })
})
