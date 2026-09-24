import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { AuthenticatorSetup } from '../api/auth.ts'
import { expectNoAxeViolations } from '../test/a11y.ts'
import { AuthenticatorSetupDetails } from './AuthenticatorSetup.tsx'

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
const GROUPED = 'JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP'
const setup = (over: Partial<AuthenticatorSetup> = {}): AuthenticatorSetup => ({
  secret: SECRET,
  otpauthUri: `otpauth://totp/Flow%20Life:ada@example.org?secret=${SECRET}&issuer=Flow%20Life`,
  expiresAt: '2026-09-20T16:12:00Z',
  ...over,
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

/** After `userEvent.setup()`, which installs its own clipboard stub that this replaces. */
function clipboard(writeText: (text: string) => Promise<void>) {
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
}

describe('AuthenticatorSetupDetails', () => {
  it('offers the QR code and the manual key together, so the QR is never the only way', () => {
    render(<AuthenticatorSetupDetails setup={setup()} />)

    expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible()
    expect(screen.getByText(GROUPED)).toBeVisible()
    expect(screen.getByRole('button', { name: 'Copy setup key' })).toBeVisible()
  })

  it('copies the canonical, UNGROUPED secret, and says so without moving focus', async () => {
    const user = userEvent.setup()
    const writeText = vi.fn(() => Promise.resolve())
    clipboard(writeText)
    render(<AuthenticatorSetupDetails setup={setup()} />)
    const button = screen.getByRole('button', { name: 'Copy setup key' })

    await user.click(button)

    // Not the spaced form on the screen: what an app's "enter a setup key" field wants.
    expect(writeText).toHaveBeenCalledExactlyOnceWith(SECRET)
    expect(screen.getByRole('status')).toHaveTextContent('Setup key copied.')
    expect(button).toHaveFocus()
  })

  it('copies only when asked', () => {
    const writeText = vi.fn(() => Promise.resolve())
    clipboard(writeText)
    render(<AuthenticatorSetupDetails setup={setup()} />)

    expect(writeText).not.toHaveBeenCalled()
    expect(screen.getByRole('status')).toBeEmptyDOMElement()
  })

  it('is operable from the keyboard', async () => {
    const user = userEvent.setup()
    const writeText = vi.fn(() => Promise.resolve())
    clipboard(writeText)
    render(<AuthenticatorSetupDetails setup={setup()} />)

    await user.tab()
    expect(screen.getByRole('button', { name: 'Copy setup key' })).toHaveFocus()
    await user.keyboard('{Enter}')

    expect(writeText).toHaveBeenCalledExactlyOnceWith(SECRET)
  })

  it('makes no request, stores nothing and logs nothing when it copies', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    const logs = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
      vi.spyOn(console, level).mockImplementation(() => undefined),
    )
    const user = userEvent.setup()
    clipboard(() => Promise.resolve())
    render(<AuthenticatorSetupDetails setup={setup()} />)

    await user.click(screen.getByRole('button', { name: 'Copy setup key' }))

    expect(fetchSpy).not.toHaveBeenCalled()
    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
    const output = logs.flatMap((spy) => (spy.mock.calls as unknown[][]).flat()).map(String)
    expect(output.join('\n')).not.toContain(SECRET)
  })

  it('says so, and leaves the key on the page to select, when the clipboard is refused', async () => {
    const user = userEvent.setup()
    clipboard(() => Promise.reject(new Error('denied')))
    render(<AuthenticatorSetupDetails setup={setup()} />)

    await user.click(screen.getByRole('button', { name: 'Copy setup key' }))

    expect(screen.getByRole('status')).toHaveTextContent('Could not copy')
    expect(screen.getByText(GROUPED)).toBeVisible()
  })

  it('says so when there is no clipboard at all', async () => {
    const user = userEvent.setup()
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })
    render(<AuthenticatorSetupDetails setup={setup()} />)

    await user.click(screen.getByRole('button', { name: 'Copy setup key' }))

    expect(screen.getByRole('status')).toHaveTextContent('Could not copy')
  })

  it('does not carry a "copied" note over to a new key', async () => {
    const user = userEvent.setup()
    clipboard(() => Promise.resolve())
    const { rerender } = render(<AuthenticatorSetupDetails setup={setup()} />)
    await user.click(screen.getByRole('button', { name: 'Copy setup key' }))
    expect(screen.getByRole('status')).toHaveTextContent('Setup key copied.')

    rerender(
      <AuthenticatorSetupDetails setup={setup({ secret: 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U' })} />,
    )

    expect(screen.getByRole('status')).toBeEmptyDOMElement()
  })

  it('shows when the server says the setup expires, in the person’s own clock time', () => {
    render(<AuthenticatorSetupDetails setup={setup()} />)

    const time = new Date('2026-09-20T16:12:00Z').toLocaleTimeString([], {
      hour: 'numeric',
      minute: '2-digit',
    })
    expect(screen.getByText(new RegExp(`This setup key expires at ${time}`))).toBeVisible()
    expect(screen.getByText(/shown once and cannot be retrieved later/)).toBeVisible()
  })

  it('does not print a made-up time when the expiry is not a date', () => {
    render(<AuthenticatorSetupDetails setup={setup({ expiresAt: 'soon' })} />)

    expect(screen.queryByText(/expires at/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Invalid/i)).not.toBeInTheDocument()
  })

  it('has no accessibility violations', async () => {
    const { container } = render(<AuthenticatorSetupDetails setup={setup()} />)

    await expectNoAxeViolations(container)
  })
})
