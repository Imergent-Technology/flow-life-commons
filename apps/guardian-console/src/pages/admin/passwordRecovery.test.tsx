import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import {
  json,
  OPERATOR_ID,
  operator,
  serveOperator,
  TARGET_ID,
  verificationRequired,
  wire,
} from '../../test/admin.ts'
import { renderApp } from '../../test/renderApp.tsx'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const DETAIL = `/admin/accounts/${TARGET_ID}`
const ACCOUNT = `GET /api/v1/admin/accounts/${TARGET_ID}` as const
const RESET = `POST /api/v1/admin/accounts/${TARGET_ID}/password-reset` as const
const SEND = 'Send password reset email'

async function openDetail(account: Record<string, unknown> = wire(), capabilities?: string[]) {
  const api = serveOperator(capabilities === undefined ? operator() : operator(capabilities))
  api.on(ACCOUNT, () => json(account))
  renderApp(DETAIL)
  await screen.findByRole('heading', { level: 1, name: String(account.display_name) })
  return api
}

const sent = (account: Record<string, unknown> = wire()) =>
  json({ account, delivery: { status: 'sent' } })

describe('sending a password reset email', () => {
  it('says what will happen, and only then sends the normal reset email', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(RESET, () => sent())

    expect(screen.getByRole('heading', { name: 'Password' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: SEND }))
    const dialog = await screen.findByRole('dialog', {
      name: 'Send Tara Target a password reset email?',
    })
    for (const text of [
      /fresh password reset email goes to tara@example.org/,
      /Any earlier reset link stops working/,
      /You will not see or set the new password/,
      /two-step verification is not reset/,
    ]) {
      expect(dialog).toHaveTextContent(text)
    }
    expect(api.callsTo(RESET)).toHaveLength(0) // nothing is sent until it is confirmed
    await expectNoAxeViolations()

    await user.click(within(dialog).getByRole('button', { name: 'Send reset email' }))

    expect(
      await screen.findByText('A password reset email was sent to tara@example.org.'),
    ).toBeInTheDocument()
    expect(api.callsTo(RESET)).toHaveLength(1)
    expect(api.callsTo(RESET)[0]?.body ?? null).toBeNull() // there is nothing to submit
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows no token, link or password anywhere, and has no password field', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    // Even a server that wrongly sent one would not have it rendered: only the delivery status is read.
    api.on(RESET, () =>
      json({
        account: wire(),
        delivery: { status: 'sent' },
        token: 'LEAKED-TOKEN-0123456789012345678901234567890123',
        link: 'https://example.org/reset#token=LEAKED',
      }),
    )

    await user.click(screen.getByRole('button', { name: SEND }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog.querySelectorAll('input, textarea')).toHaveLength(0) // nothing to type: no password is chosen here
    expect(document.querySelector('input[type="password"]')).toBeNull()
    await user.click(within(dialog).getByRole('button', { name: 'Send reset email' }))
    await screen.findByText('A password reset email was sent to tara@example.org.')

    // Checked per text node: a token is one string, and joining every element's text would invent long runs from labels.
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
      expect(node.textContent).not.toMatch(/LEAKED|#token|[A-Za-z0-9_-]{43}/)
    }
    expect(document.documentElement.innerHTML).not.toMatch(/LEAKED|#token/)
    expect(document.querySelector('input[type="password"]')).toBeNull()
    expect(screen.queryByRole('link', { name: /reset/i })).not.toBeInTheDocument()
  })

  it('keeps two-step verification a separate action, with its own panel', async () => {
    await openDetail()
    expect(screen.getByRole('heading', { name: 'Password' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Two-step verification' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reset two-step verification' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /reset sign-in access/i })).not.toBeInTheDocument()
  })

  it('cannot be submitted twice: the confirm button is busy, and one request is made', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    let release: () => void = () => undefined
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    api.on(RESET, async () => {
      await held
      return sent()
    })

    await user.click(screen.getByRole('button', { name: SEND }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Send reset email' }))

    const busy = await within(dialog).findByRole('button', { name: 'Working…' })
    expect(busy).toBeDisabled()
    await user.click(busy)
    await user.click(busy)
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled()
    expect(api.callsTo(RESET)).toHaveLength(1)

    release()
    await screen.findByText('A password reset email was sent to tara@example.org.')
    expect(api.callsTo(RESET)).toHaveLength(1)
  })

  it('tells the truth when the message could not be sent', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(RESET, () => json({ account: wire(), delivery: { status: 'failed' } }))

    await user.click(screen.getByRole('button', { name: SEND }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Send reset email' }),
    )

    expect(
      await screen.findByText('The password reset email could not be sent. Try again in a moment.'),
    ).toBeInTheDocument()
    expect(screen.queryByText(/was sent to/)).not.toBeInTheDocument()
  })

  it("explains a refusal in the dialog, in the Console's own words", async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(RESET, () => json({ message: 'x', code: 'password_reset_recently_requested' }, 409))

    await user.click(screen.getByRole('button', { name: SEND }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Send reset email' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'sent to this account a moment ago',
    )
    expect(api.callsTo(RESET)).toHaveLength(1)
  })

  it('asks for a recent proof through the shared step-up, and does not repeat the send for the operator', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    let verified = false
    api.on(RESET, () => (verified ? sent() : verificationRequired()))
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return new Response(null, { status: 204 })
    })

    await user.click(screen.getByRole('button', { name: SEND }))
    await user.click(
      within(await screen.findByRole('dialog', { name: /password reset email/ })).getByRole(
        'button',
        { name: 'Send reset email' },
      ),
    )
    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    expect(within(prompt).getByText(/Nothing has been changed yet/)).toBeInTheDocument()
    await user.type(within(prompt).getByLabelText('Current password'), 'the current password')
    await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
    await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
    })

    expect(api.callsTo(RESET)).toHaveLength(1) // the refused send was not repeated on their behalf
    await user.click(screen.getByRole('button', { name: 'Send reset email' }))
    await screen.findByText('A password reset email was sent to tara@example.org.')
    expect(api.callsTo(RESET)).toHaveLength(2)
  })
})

describe('where the password panel appears', () => {
  it('is not offered to an operator who may not manage accounts', async () => {
    await openDetail(wire(), ['console.access', 'identity.accounts.view'])
    expect(screen.queryByRole('heading', { name: 'Password' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: SEND })).not.toBeInTheDocument()
  })

  it('is not offered for an account that is still invited: it gets a new invitation instead', async () => {
    await openDetail(
      wire({
        status: 'invited',
        mfa: { enrolled: false, recovery_codes_remaining: 0 },
        invitation: { expires_at: '2026-10-01T00:00:00Z', expired: false, delivery: 'email' },
      }),
    )
    expect(screen.queryByRole('button', { name: SEND })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Send a new invitation' })).toBeInTheDocument()
  })

  it('is not offered for a disabled account, nor on your own', async () => {
    await openDetail(wire({ status: 'disabled', disabled_at: '2026-09-22T12:00:00Z' }))
    expect(screen.queryByRole('button', { name: SEND })).not.toBeInTheDocument()
  })

  it("is not offered on the operator's own account", async () => {
    await openDetail(wire({ id: OPERATOR_ID }))
    expect(screen.queryByRole('button', { name: SEND })).not.toBeInTheDocument()
  })
})
