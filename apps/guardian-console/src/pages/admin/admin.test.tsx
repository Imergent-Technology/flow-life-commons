import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import {
  CATALOG,
  json,
  OPERATOR_ID,
  operator,
  page,
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

async function openDetail(account: Record<string, unknown> = wire()) {
  const api = serveOperator()
  api.on(ACCOUNT, () => json(account))
  renderApp(DETAIL)
  await screen.findByRole('heading', { level: 1, name: String(account.display_name) })
  return api
}

/** The step-up prompt's proof form, filled in and submitted. */
async function prove(user: ReturnType<typeof userEvent.setup>, password = 'the current password') {
  const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
  await user.type(within(prompt).getByLabelText('Current password'), password)
  await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
  await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
}

describe('the account list', () => {
  it('lists accounts a page at a time, in words, with the operator marked', async () => {
    const api = serveOperator()
    api.on(
      'GET /api/v1/admin/accounts',
      json(
        page(
          [
            wire(),
            wire({
              id: OPERATOR_ID,
              display_name: 'Gwen Guardian',
              email: 'guardian@example.org',
              status: 'invited',
              mfa: { enrolled: false, recovery_codes_remaining: 0 },
              assignments: [],
            }),
          ],
          { total: 30, last_page: 2 },
        ),
      ),
    )
    renderApp('/admin/accounts')

    const table = await screen.findByRole('table', { name: 'Accounts' })
    const rows = within(table).getAllByRole('row')
    expect(rows).toHaveLength(3) // header + two
    const [, targetRow, operatorRow] = rows
    if (targetRow === undefined || operatorRow === undefined) throw new Error('rows are missing')
    const target = within(targetRow)
    const you = within(operatorRow)
    expect(target.getByRole('link', { name: 'Tara Target' })).toHaveAttribute('href', DETAIL)
    expect(target.getByText('Active')).toBeInTheDocument()
    expect(target.getByText('Sample Access One')).toBeInTheDocument()
    expect(you.getByText('Invited')).toBeInTheDocument()
    expect(you.getByText('(you)')).toBeInTheDocument()
    expect(screen.getByText('Page 1 of 2 (30 accounts)')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    await expectNoAxeViolations()
  })

  it('searches and filters through the server, and pages on', async () => {
    const user = userEvent.setup()
    const api = serveOperator()
    api.on('GET /api/v1/admin/accounts', json(page([wire()], { total: 60, last_page: 3 })))
    renderApp('/admin/accounts')
    await screen.findByRole('table', { name: 'Accounts' })

    await user.type(screen.getByLabelText('Name or email'), 'tara')
    await user.selectOptions(screen.getByLabelText('Status'), 'disabled')
    await user.click(screen.getByRole('button', { name: 'Search' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))

    await waitFor(() => {
      expect(api.calls.at(-1)?.path).toBe(
        '/api/v1/admin/accounts?page=2&per_page=25&q=tara&status=disabled',
      )
    })
  })

  it('says so when nothing matches, and when the list cannot be loaded', async () => {
    const api = serveOperator()
    api.on('GET /api/v1/admin/accounts', json(page([])))
    renderApp('/admin/accounts')
    expect(await screen.findByText('No accounts match.')).toBeInTheDocument()
    api.on('GET /api/v1/admin/accounts', json({ message: 'boom' }, 500))
  })

  it('offers "Invite an operator" only to someone who may issue invitations', async () => {
    const api = serveOperator(operator(['console.access', 'identity.accounts.view']))
    api.on('GET /api/v1/admin/accounts', json(page([wire()])))
    renderApp('/admin/accounts')
    await screen.findByRole('table', { name: 'Accounts' })
    expect(screen.queryByRole('link', { name: 'Invite an operator' })).not.toBeInTheDocument()
  })

  it('shows a guardian no way in: no Accounts link, and "Not permitted" if they type the address', async () => {
    serveOperator(operator(['console.access']))
    renderApp('/admin/accounts')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Accounts' })).not.toBeInTheDocument()
  })

  it('links to Accounts from the navigation for someone who may view them', async () => {
    const api = serveOperator()
    api.on('GET /api/v1/admin/accounts', json(page([wire()])))
    renderApp('/')
    expect(await screen.findByRole('link', { name: 'Accounts' })).toHaveAttribute(
      'href',
      '/admin/accounts',
    )
  })
})

describe('an account', () => {
  it('shows what an operator needs, and only what the operator may do', async () => {
    await openDetail(wire({ email_verified_at: null }))

    expect(screen.getByText('Not verified')).toBeInTheDocument()
    expect(screen.getByText('Set up, with 8 recovery codes left.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reset two-step verification' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Disable this account' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove Sample Access One' })).toBeInTheDocument()
    await expectNoAxeViolations()
  })

  it('offers a view-only operator nothing to change', async () => {
    const api = serveOperator(operator(['console.access', 'identity.accounts.view']))
    api.on(ACCOUNT, () => json(wire()))
    renderApp(DETAIL)
    await screen.findByRole('heading', { level: 1, name: 'Tara Target' })

    expect(screen.queryByRole('button', { name: /Disable|Re-enable/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Remove|Add access/ })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Reset two-step verification' }),
    ).not.toBeInTheDocument()
    expect(screen.getByText('Sample Access One')).toBeInTheDocument() // what they hold, in the server's words
  })

  it("does not offer to disable or reset the operator's OWN account", async () => {
    await openDetail(wire({ id: OPERATOR_ID }))
    // The route id is the target's, but the page compares the loaded account to who is signed in.
    expect(screen.queryByRole('button', { name: 'Disable this account' })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Reset two-step verification' }),
    ).not.toBeInTheDocument()
    expect(screen.getByText(/cannot disable your own account/)).toBeInTheDocument()
    expect(screen.getByText(/use Account security/)).toBeInTheDocument()
  })

  it('says so for an account that no longer exists', async () => {
    const api = serveOperator()
    api.on(ACCOUNT, () => json({ message: 'no', code: 'account_not_found' }, 404))
    renderApp(DETAIL)
    expect(await screen.findByText('That account no longer exists.')).toBeInTheDocument()
  })
})

describe('disabling and re-enabling', () => {
  it('warns what disabling does, does nothing until confirmed, and then does it', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`, () =>
      json(wire({ status: 'disabled', disabled_at: '2026-09-22T12:00:00Z' })),
    )

    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    const dialog = await screen.findByRole('dialog', { name: 'Disable Tara Target?' })
    expect(within(dialog).getByText(/signed out everywhere/)).toBeInTheDocument()
    expect(
      within(dialog).getByText(/person record, their access roles and their history are kept/),
    ).toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`)).toHaveLength(0)
    await expectNoAxeViolations()

    await user.click(within(dialog).getByRole('button', { name: 'Disable account' }))

    expect(await screen.findByText('Tara Target is disabled.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Re-enable this account' })).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('does nothing at all when the person cancels', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }),
    )

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`)).toHaveLength(0)
  })

  it('explains that re-enabling still needs credentials, MFA and capabilities', async () => {
    const user = userEvent.setup()
    const api = await openDetail(wire({ status: 'disabled', disabled_at: '2026-09-22T12:00:00Z' }))
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/enable`, () => json(wire()))

    await user.click(screen.getByRole('button', { name: 'Re-enable this account' }))
    const dialog = await screen.findByRole('dialog', { name: 'Re-enable Tara Target?' })
    expect(within(dialog).getByText(/still need their two-step code/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Re-enable account' }))

    expect(await screen.findByText('Tara Target can sign in again.')).toBeInTheDocument()
  })

  it("shows the server's refusal in the Console's own words when it would leave no administrator", async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`, () =>
      json(
        {
          message: 'The platform must keep at least one active administrator.',
          code: 'last_administrator_required',
        },
        409,
      ),
    )
    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Disable account' }),
    )

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('without an active administrator')
    expect(screen.getByRole('button', { name: 'Disable account' })).toBeInTheDocument() // still open, nothing changed
  })
})

describe('recent verification (step-up)', () => {
  it('prompts for password and a second factor, does NOT repeat the action, and lets the person confirm again', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    let verified = false
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`, () =>
      verified
        ? json(wire({ status: 'disabled', disabled_at: '2026-09-22T12:00:00Z' }))
        : verificationRequired(),
    )
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return new Response(null, { status: 204 })
    })

    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    await user.click(
      within(await screen.findByRole('dialog', { name: 'Disable Tara Target?' })).getByRole(
        'button',
        { name: 'Disable account' },
      ),
    )

    // The prompt opens on top, names itself, and says nothing has happened yet.
    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    expect(within(prompt).getByText(/Nothing has been changed yet/)).toBeInTheDocument()
    await expectNoAxeViolations()
    await prove(user)

    // The proof was sent, once, with the password and the code, and the refused action was NOT sent again.
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
    })
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(1)
    expect(api.callsTo('POST /api/v1/security/verify')[0]?.body).toEqual({
      current_password: 'the current password',
      code: '123456',
    })
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`)).toHaveLength(1)
    expect(await screen.findByText(/confirm again to continue/)).toBeInTheDocument()

    // Only a deliberate second press does it.
    await user.click(screen.getByRole('button', { name: 'Disable account' }))
    expect(await screen.findByText('Tara Target is disabled.')).toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`)).toHaveLength(2)
  })

  it('keeps the prompt open, with the reason, when the proof is wrong, and repeats nothing', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`, () => verificationRequired())
    api.on('POST /api/v1/security/verify', () =>
      json(
        {
          message: 'The current password is incorrect.',
          errors: { current_password: ['The current password is incorrect.'] },
        },
        422,
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Disable account' }),
    )
    await prove(user, 'wrong')

    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    expect(
      await within(prompt).findByText('The current password is incorrect.'),
    ).toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`)).toHaveLength(1)
  })

  it('says nothing was changed when the person cancels the prompt', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`, () => verificationRequired())

    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Disable account' }),
    )
    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    await user.click(within(prompt).getByRole('button', { name: 'Cancel' }))

    expect(
      await screen.findByText(/Verification was cancelled. Nothing has been changed./),
    ).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
  })

  it('never keeps the password or the code once the prompt is closed', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/disable`, () => verificationRequired())
    api.on('POST /api/v1/security/verify', () => new Response(null, { status: 204 }))
    await user.click(screen.getByRole('button', { name: 'Disable this account' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Disable account' }),
    )
    await prove(user, 'a-very-recognisable-password')

    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
    })
    expect(document.body.innerHTML).not.toContain('a-very-recognisable-password')
    expect(localStorage).toHaveLength(0)
    expect(sessionStorage).toHaveLength(0)
  })
})

describe('access', () => {
  it('shows what the server offers, not a list of its own, and adds one after confirmation', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/assignments`, () =>
      json(
        wire({
          assignments: [
            ...(wire().assignments as unknown[]),
            {
              key: 'custom_key_two',
              name: 'Sample Access Two',
              description: 'Something else the server offers.',
              granted_at: '2026-09-22T12:00:00Z',
            },
          ],
        }),
      ),
    )

    const select = await screen.findByLabelText('Give them access')
    await waitFor(() => {
      expect(within(select).getByRole('option', { name: 'Sample Access Two' })).toBeInTheDocument()
    })
    expect(
      within(select).queryByRole('option', { name: 'Sample Access One' }),
    ).not.toBeInTheDocument() // already held
    await user.selectOptions(select, 'custom_key_two')
    await user.click(screen.getByRole('button', { name: 'Add access' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Give access' }),
    )

    expect(await screen.findByText(/now has “Sample Access Two” access/)).toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/assignments`)[0]?.body).toEqual({
      key: 'custom_key_two',
    })
  })

  it('removes access after confirmation, and reports the last-administrator refusal plainly', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`DELETE /api/v1/admin/accounts/${TARGET_ID}/assignments/custom_key_one`, () =>
      json({ message: 'x', code: 'last_administrator_required' }, 409),
    )

    await user.click(screen.getByRole('button', { name: 'Remove Sample Access One' }))
    const dialog = await screen.findByRole('dialog', { name: /Remove “Sample Access One”/ })
    await user.click(within(dialog).getByRole('button', { name: 'Remove access' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'without an active administrator',
    )
    expect(
      api.callsTo(`DELETE /api/v1/admin/accounts/${TARGET_ID}/assignments/custom_key_one`),
    ).toHaveLength(1)
  })
})

describe('two-step recovery', () => {
  it('says exactly what will happen to the person, and only then resets', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/mfa/reset`, () =>
      json(wire({ mfa: { enrolled: false, recovery_codes_remaining: 0 } })),
    )

    await user.click(screen.getByRole('button', { name: 'Reset two-step verification' }))
    const dialog = await screen.findByRole('dialog', {
      name: 'Reset two-step verification for Tara Target?',
    })
    for (const text of [
      /authenticator app and recovery codes for tara@example.org will stop working/,
      /signed out everywhere/,
      /must sign in with their password and set up two-step verification again/,
      /password, access and history are not changed/,
      /Check that it is really them/,
    ]) {
      expect(dialog).toHaveTextContent(text)
    }
    expect(api.callsTo(`POST /api/v1/admin/accounts/${TARGET_ID}/mfa/reset`)).toHaveLength(0)
    await expectNoAxeViolations()

    await user.click(within(dialog).getByRole('button', { name: 'Reset two-step verification' }))
    expect(
      await screen.findByText(/will set it up again when they next sign in/),
    ).toBeInTheDocument()
    expect(screen.getByText('Not set up.')).toBeInTheDocument()
  })

  it('offers nothing for an account with no second factor', async () => {
    await openDetail(wire({ mfa: { enrolled: false, recovery_codes_remaining: 0 } }))
    expect(
      screen.queryByRole('button', { name: 'Reset two-step verification' }),
    ).not.toBeInTheDocument()
  })
})

describe('invitations', () => {
  it('sends a fresh one for an account that is still invited, replacing the old', async () => {
    const user = userEvent.setup()
    const invited = wire({
      status: 'invited',
      email_verified_at: null,
      last_login_at: null,
      mfa: { enrolled: false, recovery_codes_remaining: 0 },
      assignments: [],
      invitation: { expires_at: '2026-09-25T00:00:00Z', expired: true, delivery: 'email' },
    })
    const api = await openDetail(invited)
    api.on(`POST /api/v1/admin/accounts/${TARGET_ID}/invitation`, () =>
      json({
        account: {
          ...invited,
          invitation: { expires_at: '2026-10-01T00:00:00Z', expired: false, delivery: 'email' },
        },
        delivery: { status: 'sent' },
      }),
    )

    expect(screen.getByText(/invitation expired on/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Send a new invitation' }))
    const dialog = await screen.findByRole('dialog', { name: /new invitation/ })
    expect(within(dialog).getByText(/old one stops working/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }))

    expect(
      await screen.findByText('A new invitation was sent to tara@example.org.'),
    ).toBeInTheDocument()
    expect(screen.getByText(/invitation is valid until/)).toBeInTheDocument()
  })

  it('offers no invitation controls for an active account', async () => {
    await openDetail()
    expect(screen.queryByRole('button', { name: 'Send a new invitation' })).not.toBeInTheDocument()
  })
})

describe('inviting an operator', () => {
  async function openInvite(capabilities?: string[]) {
    const api = serveOperator(capabilities === undefined ? operator() : operator(capabilities))
    renderApp('/admin/accounts/invite')
    await screen.findByRole('heading', { level: 1, name: 'Invite an operator' })
    return api
  }

  it('invites by name and email, with the access the server offers, and never shows a secret', async () => {
    const user = userEvent.setup()
    const api = await openInvite()
    api.on('POST /api/v1/admin/invitations', () =>
      json(
        {
          account: wire({
            display_name: 'New Person',
            email: 'new@example.org',
            status: 'invited',
            assignments: [],
          }),
          delivery: { status: 'sent' },
        },
        201,
      ),
    )
    await screen.findByRole('checkbox', { name: /Sample Access Two/ })
    await expectNoAxeViolations()

    await user.type(screen.getByLabelText('Display name'), 'New Person')
    await user.type(screen.getByLabelText('Email address'), 'new@example.org')
    await user.click(screen.getByRole('checkbox', { name: /Sample Access Two/ }))
    await user.click(screen.getByRole('button', { name: 'Send invitation' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Invitation sent' }),
    ).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/admin/invitations')[0]?.body).toEqual({
      email: 'new@example.org',
      display_name: 'New Person',
      initial_assignments: ['custom_key_two'],
    })
    expect(document.body.textContent).not.toMatch(/[A-Za-z0-9_-]{43}/) // no token-shaped text anywhere
  })

  it('says plainly when the account was created but the email could not be sent, and points at the remedy', async () => {
    const user = userEvent.setup()
    const api = await openInvite()
    api.on('POST /api/v1/admin/invitations', () =>
      json({ account: wire({ status: 'invited' }), delivery: { status: 'failed' } }, 201),
    )
    await user.type(screen.getByLabelText('Display name'), 'New Person')
    await user.type(screen.getByLabelText('Email address'), 'new@example.org')
    await user.click(screen.getByRole('button', { name: 'Send invitation' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Invitation not sent' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Send a new invitation')
    expect(screen.getByRole('link', { name: 'Open the account' })).toHaveAttribute('href', DETAIL)
  })

  it('tells the operator when the address is already in use, on the field', async () => {
    const user = userEvent.setup()
    const api = await openInvite()
    api.on('POST /api/v1/admin/invitations', () =>
      json({ message: 'x', code: 'email_already_in_use' }, 409),
    )
    await user.type(screen.getByLabelText('Display name'), 'New Person')
    await user.type(screen.getByLabelText('Email address'), 'taken@example.org')
    await user.click(screen.getByRole('button', { name: 'Send invitation' }))

    expect(
      await screen.findByText('An account already uses that email address.'),
    ).toBeInTheDocument()
    expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-invalid', 'true')
  })

  it('steps up, then waits for a deliberate second press instead of sending on its own', async () => {
    const user = userEvent.setup()
    const api = await openInvite()
    api.on('POST /api/v1/admin/invitations', () => verificationRequired())
    api.on('POST /api/v1/security/verify', () => new Response(null, { status: 204 }))
    await user.type(screen.getByLabelText('Display name'), 'New Person')
    await user.type(screen.getByLabelText('Email address'), 'new@example.org')
    await user.click(screen.getByRole('button', { name: 'Send invitation' }))
    await prove(user)

    expect(await screen.findByText(/press “Send invitation” again to continue/)).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/admin/invitations')).toHaveLength(1)
    expect(screen.getByLabelText('Display name')).toHaveValue('New Person') // nothing typed was lost
  })

  it('does not offer roles to someone who may not assign them, and asks the server for none', async () => {
    const api = await openInvite([
      'console.access',
      'identity.invitations.issue',
      'identity.accounts.view',
    ])
    expect(screen.queryByRole('group', { name: /Access to give them/ })).not.toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/admin/invitations')).toHaveLength(0)
  })

  it('is out of reach for someone who may not issue invitations', async () => {
    serveOperator(operator(['console.access', 'identity.accounts.view']))
    renderApp('/admin/accounts/invite')
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
  })
})

describe('what the Console is told', () => {
  it("renders the catalog's words as given, whatever they are", async () => {
    const api = serveOperator()
    api.on(
      'GET /api/v1/admin/roles',
      json({
        data: [
          {
            key: 'zz',
            name: 'A Brand New Access',
            description: 'Added after the Console was built.',
            capabilities: [],
          },
        ],
      }),
    )
    api.on(ACCOUNT, () => json(wire({ assignments: [] })))
    renderApp(DETAIL)

    const select = await screen.findByLabelText('Give them access')
    expect(
      await within(select).findByRole('option', { name: 'A Brand New Access' }),
    ).toBeInTheDocument()
    expect(CATALOG.data).toHaveLength(2) // and the shared fixture is not what was used here
  })
})
