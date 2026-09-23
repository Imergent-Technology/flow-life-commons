import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { resolveMembershipTerm } from '../../admin/membershipTerm.ts'
import { operator, serveOperator, verificationRequired } from '../../test/admin.ts'
import { expectNoAxeViolations } from '../../test/a11y.ts'
import {
  GRANT_ID,
  grantAlreadyRevoked,
  membershipRecordNotFound,
  membersPage,
  PERSON_ID,
  wireGrant,
  wireGrantEntry,
  wireMember,
} from '../../test/membership.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { json } from '../../test/fakeApi.ts'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

const VIEW_ONLY = ['console.access', 'membership.records.view']
const MANAGE = ['console.access', 'membership.records.view', 'membership.records.manage']

const DETAIL = `/admin/members/${PERSON_ID}`
const MEMBER = `GET /api/v1/admin/members/${PERSON_ID}` as const

async function openDetail(member: Record<string, unknown> = wireMember()) {
  const api = serveOperator(operator(MANAGE))
  api.on(MEMBER, () => json(member))
  renderApp(DETAIL)
  await screen.findByRole('heading', {
    level: 1,
    name: (member.person as { display_name: string }).display_name,
  })
  return api
}

/** The step-up prompt's proof form, filled in and submitted. */
async function prove(user: ReturnType<typeof userEvent.setup>, password = 'the current password') {
  const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
  await user.type(within(prompt).getByLabelText('Current password'), password)
  await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
  await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
}

describe('navigation', () => {
  it('shows the Members link with membership.records.view', async () => {
    serveOperator(operator(VIEW_ONLY))
    renderApp('/')
    expect(await screen.findByRole('link', { name: 'Members' })).toHaveAttribute(
      'href',
      '/admin/members',
    )
  })

  it('hides the Members link without membership.records.view', async () => {
    serveOperator(operator(['console.access']))
    renderApp('/')
    await screen.findByRole('heading', { level: 1, name: 'Flow Life Guardian Console' })
    expect(screen.queryByRole('link', { name: 'Members' })).not.toBeInTheDocument()
  })

  it('refuses the list route on the page for someone without the capability', async () => {
    serveOperator(operator(['console.access']))
    renderApp('/admin/members')
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Not permitted' }),
    ).toBeInTheDocument()
  })

  it('loads the correct Person on the detail route', async () => {
    await openDetail(wireMember({ person: { id: PERSON_ID, display_name: 'Ada Admin' } }))
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Admin' })).toBeInTheDocument()
  })
})

describe('the member list', () => {
  it('shows an active member with a finite access-through', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on(
      'GET /api/v1/admin/members',
      json(
        membersPage([
          wireMember({
            active: true,
            open_ended: false,
            current_access_ends_at: '2026-12-31T00:00:00Z',
          }),
        ]),
      ),
    )
    renderApp('/admin/members')

    const table = await screen.findByRole('table', { name: 'Members' })
    expect(within(table).getByText('Active')).toBeInTheDocument()
    expect(within(table).getByText(/Access through/)).toBeInTheDocument()
    await expectNoAxeViolations()
  })

  it('shows an active open-ended member explicitly', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on(
      'GET /api/v1/admin/members',
      json(membersPage([wireMember({ active: true, open_ended: true })])),
    )
    renderApp('/admin/members')

    const table = await screen.findByRole('table', { name: 'Members' })
    expect(within(table).getByText('Open-ended')).toBeInTheDocument()
  })

  it('keeps an inactive (expired, future, or revoked-history) member visible', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on(
      'GET /api/v1/admin/members',
      json(
        membersPage([
          wireMember({ active: false, open_ended: false, current_access_ends_at: null }),
        ]),
      ),
    )
    renderApp('/admin/members')

    const table = await screen.findByRole('table', { name: 'Members' })
    expect(within(table).getByText('Inactive')).toBeInTheDocument()
  })

  it('represents a Person with several grants exactly once, per the API response', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on(
      'GET /api/v1/admin/members',
      json(membersPage([wireMember({ grants: [wireGrantEntry(), wireGrantEntry({ id: 'g2' })] })])),
    )
    renderApp('/admin/members')

    const table = await screen.findByRole('table', { name: 'Members' })
    expect(within(table).getAllByRole('link', { name: 'Mia Member' })).toHaveLength(1)
  })

  it('pages on request', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(VIEW_ONLY))
    api.on(
      'GET /api/v1/admin/members',
      json(membersPage([wireMember()], { total: 60, last_page: 3 })),
    )
    renderApp('/admin/members')
    await screen.findByRole('table', { name: 'Members' })

    await user.click(screen.getByRole('button', { name: 'Next' }))

    await waitFor(() => {
      expect(api.calls.at(-1)?.path).toBe('/api/v1/admin/members?page=2&per_page=25')
    })
  })

  it('shows a deliberate empty state', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on('GET /api/v1/admin/members', json(membersPage([])))
    renderApp('/admin/members')
    expect(await screen.findByText('There are no membership records yet.')).toBeInTheDocument()
  })
})

describe('capability-driven UI', () => {
  it('offers a view-only operator records but no way to add or manage', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on('GET /api/v1/admin/members', json(membersPage([wireMember()])))
    renderApp('/admin/members')
    await screen.findByRole('table', { name: 'Members' })
    expect(screen.queryByRole('link', { name: 'Add member' })).not.toBeInTheDocument()
  })

  it('offers a view-only operator nothing to change on the detail page', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on(MEMBER, () => json(wireMember()))
    renderApp(DETAIL)
    await screen.findByRole('heading', { level: 1, name: 'Mia Member' })
    expect(screen.queryByRole('button', { name: 'Add grant' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Revoke this grant' })).not.toBeInTheDocument()
  })

  it('offers add/grant/revoke with membership.records.manage', async () => {
    await openDetail()
    expect(screen.getByRole('button', { name: 'Add grant' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Revoke this grant' })).toBeInTheDocument()
  })
})

describe('add member', () => {
  function fillTerm(startsAt = '2026-06-01T10:00', endsAt = '2026-12-01T10:00') {
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: startsAt } })
    fireEvent.change(screen.getByLabelText('Ends at'), { target: { value: endsAt } })
  }

  it('creates a new Person and their initial grant, not an Account', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on('POST /api/v1/admin/members', () => json(wireMember(), 201))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })

    await user.type(screen.getByLabelText('Display name'), 'Mia Member')
    fillTerm()
    await user.click(screen.getByRole('button', { name: 'Add member' }))

    await screen.findByRole('heading', { level: 1, name: 'Member added' })
    const call = api.callsTo('POST /api/v1/admin/members')[0]
    expect(call?.body).toMatchObject({ display_name: 'Mia Member', source: 'operator' })
    expect(call?.body).not.toHaveProperty('email')
  })

  it('requires the display name', async () => {
    const user = userEvent.setup()
    serveOperator(operator(MANAGE))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })
    fillTerm()

    await user.click(screen.getByRole('button', { name: 'Add member' }))
    // The native `required` attribute blocks submission; the display name field is still empty.
    expect(screen.getByLabelText('Display name')).toHaveValue('')
  })

  it('requires a bounded end date when open-ended is off: the native required field blocks submission', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })

    await user.type(screen.getByLabelText('Display name'), 'Mia Member')
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T10:00' } })
    expect(screen.getByLabelText('Ends at')).toBeRequired()
    await user.click(screen.getByRole('button', { name: 'Add member' }))

    // Left blank, `ends_at` never silently becomes open-ended: nothing is sent either way.
    expect(api.callsTo('POST /api/v1/admin/members')).toHaveLength(0)
    expect(
      screen.queryByRole('heading', { level: 1, name: 'Member added' }),
    ).not.toBeInTheDocument()
  })

  it('refuses an unresolved term client-side too, so a blank end never silently becomes open-ended', () => {
    const blank = { startsAt: '2026-06-01T10:00', openEnded: false, endsAt: '' }
    expect(resolveMembershipTerm(blank)).toEqual({
      ok: false,
      field: 'ends_at',
      message: 'Enter when access ends, or choose open-ended access.',
    })
  })

  it('sends an explicit ends_at of null when open-ended is checked', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on('POST /api/v1/admin/members', () => json(wireMember(), 201))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })

    await user.type(screen.getByLabelText('Display name'), 'Mia Member')
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T10:00' } })
    await user.click(screen.getByLabelText('Open-ended access (no end date)'))
    expect(screen.queryByLabelText('Ends at')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Add member' }))

    await screen.findByRole('heading', { level: 1, name: 'Member added' })
    expect(api.callsTo('POST /api/v1/admin/members')[0]?.body).toMatchObject({ ends_at: null })
  })

  it('defaults the source to operator, and luma_legacy is selectable', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on('POST /api/v1/admin/members', () => json(wireMember(), 201))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })
    expect(screen.getByLabelText('Source')).toHaveValue('operator')

    await user.type(screen.getByLabelText('Display name'), 'Mia Member')
    fillTerm()
    await user.selectOptions(screen.getByLabelText('Source'), 'luma_legacy')
    await user.type(screen.getByLabelText('Source reference'), 'luma-4821')
    await user.click(screen.getByRole('button', { name: 'Add member' }))

    await screen.findByRole('heading', { level: 1, name: 'Member added' })
    expect(api.callsTo('POST /api/v1/admin/members')[0]?.body).toMatchObject({
      source: 'luma_legacy',
      source_reference: 'luma-4821',
    })
  })

  it('shows a validation problem cleanly', async () => {
    const user = userEvent.setup()
    serveOperator(operator(MANAGE)).on(
      'POST /api/v1/admin/members',
      json(
        {
          message: 'The display name is invalid.',
          code: 'invalid_membership_term',
          errors: { display_name: ['The display name is invalid.'] },
        },
        422,
      ),
    )
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })
    await user.type(screen.getByLabelText('Display name'), 'x')
    fillTerm()
    await user.click(screen.getByRole('button', { name: 'Add member' }))

    expect(await screen.findByText('The display name is invalid.')).toBeInTheDocument()
  })
})

describe('date and time', () => {
  it('displays a grant instant using the reader-local formatting convention', async () => {
    await openDetail(
      wireMember({ grants: [wireGrantEntry({ starts_at: '2026-06-15T14:30:00Z' })] }),
    )
    const expected = new Date('2026-06-15T14:30:00Z').toLocaleString(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    })
    expect(
      screen.getByText(new RegExp(expected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))),
    ).toBeInTheDocument()
  })

  it('converts a browser-local datetime-local value to the expected UTC instant', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on('POST /api/v1/admin/members', () => json(wireMember(), 201))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })

    await user.type(screen.getByLabelText('Display name'), 'Mia Member')
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T10:00' } })
    fireEvent.change(screen.getByLabelText('Ends at'), { target: { value: '2026-12-01T10:00' } })
    await user.click(screen.getByRole('button', { name: 'Add member' }))

    await screen.findByRole('heading', { level: 1, name: 'Member added' })
    const body = api.callsTo('POST /api/v1/admin/members')[0]?.body as { starts_at: string }
    // The container runs in UTC, so a datetime-local value of 10:00 is the UTC instant 10:00:00.000Z.
    expect(body.starts_at).toBe(new Date('2026-06-01T10:00').toISOString())
  })
})

describe('add grant', () => {
  it('adds a grant through the review-then-confirm flow', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/members/${PERSON_ID}/grants`, () =>
      json(wireGrant({ id: 'new-grant' }), 201),
    )
    api.on(MEMBER, () =>
      json(wireMember({ grants: [wireGrantEntry(), wireGrantEntry({ id: 'new-grant' })] })),
    )

    await user.click(screen.getByRole('button', { name: 'Add grant' }))
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T10:00' } })
    await user.click(screen.getByLabelText('Open-ended access (no end date)'))
    await user.click(screen.getByRole('button', { name: 'Review and add' }))

    const dialog = await screen.findByRole('dialog', {
      name: `Add a membership grant for Mia Member?`,
    })
    await user.click(within(dialog).getByRole('button', { name: 'Add grant' }))

    await waitFor(() => {
      expect(api.callsTo(`POST /api/v1/admin/members/${PERSON_ID}/grants`)).toHaveLength(1)
    })
    expect(await screen.findByText('A membership grant was added.')).toBeInTheDocument()
  })

  it('does not refuse an overlapping term client-side', async () => {
    const user = userEvent.setup()
    const api = await openDetail(
      wireMember({
        grants: [
          wireGrantEntry({ starts_at: '2026-01-01T00:00:00Z', ends_at: '2026-12-31T00:00:00Z' }),
        ],
      }),
    )
    api.on(`POST /api/v1/admin/members/${PERSON_ID}/grants`, () =>
      json(wireGrant({ id: 'overlap' }), 201),
    )
    api.on(MEMBER, () => json(wireMember()))

    await user.click(screen.getByRole('button', { name: 'Add grant' }))
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T00:00' } })
    fireEvent.change(screen.getByLabelText('Ends at'), { target: { value: '2027-06-01T00:00' } })
    await user.click(screen.getByRole('button', { name: 'Review and add' }))

    // No client-side rejection: the review dialog opens with an enabled confirm button, not a validation error.
    const dialog = await screen.findByRole('dialog', {
      name: `Add a membership grant for Mia Member?`,
    })
    expect(within(dialog).getByRole('button', { name: 'Add grant' })).toBeEnabled()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('refreshes the authoritative record after adding a grant', async () => {
    const user = userEvent.setup()
    const api = await openDetail(wireMember({ active: false, grants: [] }))
    api.on(`POST /api/v1/admin/members/${PERSON_ID}/grants`, () => json(wireGrant(), 201))
    api.on(MEMBER, () => json(wireMember({ active: true, open_ended: true })))

    await user.click(screen.getByRole('button', { name: 'Add grant' }))
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T00:00' } })
    await user.click(screen.getByLabelText('Open-ended access (no end date)'))
    await user.click(screen.getByRole('button', { name: 'Review and add' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Add grant' }),
    )

    await waitFor(() => {
      expect(screen.getByText('Active')).toBeInTheDocument()
    })
    expect(api.callsTo(MEMBER).length).toBeGreaterThan(1)
  })
})

describe('revoke grant', () => {
  it('requires confirmation, and cancelling sends nothing', async () => {
    const user = userEvent.setup()
    const api = await openDetail()

    await user.click(screen.getByRole('button', { name: 'Revoke this grant' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`)).toHaveLength(0)
  })

  it('revokes and refreshes on success', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(
      `POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`,
      () => new Response(null, { status: 204 }),
    )
    api.on(MEMBER, () =>
      json(
        wireMember({
          active: false,
          grants: [wireGrantEntry({ revoked_at: '2026-09-23T12:00:00Z' })],
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Revoke this grant' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Revoke grant' }),
    )

    expect(await screen.findByText(/The grant has been revoked/)).toBeInTheDocument()
    expect(api.callsTo(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`)).toHaveLength(1)
    await waitFor(() => {
      expect(api.callsTo(MEMBER).length).toBeGreaterThan(1)
    })
  })

  it('shows a stale-state message and refreshes when the grant was already revoked', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`, grantAlreadyRevoked)
    api.on(MEMBER, () =>
      json(
        wireMember({
          active: false,
          grants: [wireGrantEntry({ revoked_at: '2026-09-23T12:00:00Z' })],
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Revoke this grant' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Revoke grant' }),
    )

    expect(await screen.findByText(/already revoked/)).toBeInTheDocument()
    await waitFor(() => {
      expect(api.callsTo(MEMBER).length).toBeGreaterThan(1)
    })
  })

  it('offers no un-revoke or delete action once a grant is revoked', async () => {
    await openDetail(
      wireMember({ grants: [wireGrantEntry({ revoked_at: '2026-09-23T12:00:00Z' })] }),
    )
    expect(screen.queryByRole('button', { name: /Revoke/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Delete/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Undo|Un-revoke/ })).not.toBeInTheDocument()
  })
})

describe('step-up', () => {
  it('follows the existing step-up flow for revoke, and never repeats the action on its own', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    let verified = false
    api.on(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`, () =>
      verified ? new Response(null, { status: 204 }) : verificationRequired(),
    )
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return new Response(null, { status: 204 })
    })
    api.on(MEMBER, () =>
      json(
        wireMember({
          active: false,
          grants: [wireGrantEntry({ revoked_at: '2026-09-23T12:00:00Z' })],
        }),
      ),
    )

    await user.click(screen.getByRole('button', { name: 'Revoke this grant' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Revoke grant' }),
    )
    await prove(user)

    expect(api.callsTo(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`)).toHaveLength(1)
    expect(await screen.findByText(/confirm again to continue/)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Revoke grant' }))
    await waitFor(() => {
      expect(api.callsTo(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`)).toHaveLength(2)
    })
  })

  it('does not mutate anything when step-up is cancelled', async () => {
    const user = userEvent.setup()
    const api = await openDetail()
    api.on(`POST /api/v1/admin/membership-grants/${GRANT_ID}/revoke`, () => verificationRequired())

    await user.click(screen.getByRole('button', { name: 'Revoke this grant' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Revoke grant' }),
    )
    const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    await user.click(within(prompt).getByRole('button', { name: 'Cancel' }))

    expect(await screen.findByText(/Verification was cancelled/)).toBeInTheDocument()
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
  })
})

describe('errors', () => {
  it('shows a not-found state for a stale or invalid member URL', async () => {
    serveOperator(operator(VIEW_ONLY)).on(MEMBER, membershipRecordNotFound)
    renderApp(DETAIL)
    expect(
      await screen.findByText('That membership record could not be found.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to members' })).toBeInTheDocument()
  })

  it('shows "Not permitted" for 403 (no membership capability)', async () => {
    serveOperator(operator(['console.access']))
    renderApp('/admin/members')
    expect(await screen.findByRole('heading', { name: 'Not permitted' })).toBeInTheDocument()
  })
})

describe('privacy and scope', () => {
  it('never assumes an email, account or security field on a member row', async () => {
    const api = serveOperator(operator(VIEW_ONLY))
    api.on('GET /api/v1/admin/members', json(membersPage([wireMember()])))
    renderApp('/admin/members')
    const table = await screen.findByRole('table', { name: 'Members' })
    const text = table.textContent.toLowerCase()
    for (const forbidden of ['email', 'password', 'account_id', 'session']) {
      expect(text).not.toContain(forbidden)
    }
  })

  it('never shows a "member since" label', async () => {
    await openDetail()
    expect(screen.queryByText(/member since/i)).not.toBeInTheDocument()
  })

  it('does not request or send a payment or tier field when adding a member', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator(MANAGE))
    api.on('POST /api/v1/admin/members', () => json(wireMember(), 201))
    renderApp('/admin/members/new')
    await screen.findByRole('heading', { level: 1, name: 'Add a new member' })
    expect(screen.queryByLabelText(/tier|amount|payment/i)).not.toBeInTheDocument()

    await user.type(screen.getByLabelText('Display name'), 'Mia Member')
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T10:00' } })
    await user.click(screen.getByLabelText('Open-ended access (no end date)'))
    await user.click(screen.getByRole('button', { name: 'Add member' }))

    await screen.findByRole('heading', { level: 1, name: 'Member added' })
    const body = api.callsTo('POST /api/v1/admin/members')[0]?.body as Record<string, unknown>
    expect(body).not.toHaveProperty('amount')
    expect(body).not.toHaveProperty('tier')
  })
})

describe('existing-Person grant entry point (from an Account)', () => {
  it('offers "Grant membership access" from an Account with no membership record', async () => {
    const user = userEvent.setup()
    const api = serveOperator(operator([...MANAGE, 'identity.accounts.view']))
    const accountWire = {
      id: '01J0000000000000000TARGET',
      person_id: PERSON_ID,
      display_name: 'Tara Target',
      email: 'tara@example.org',
      email_verified_at: null,
      status: 'active',
      created_at: '2026-01-01T00:00:00Z',
      last_login_at: null,
      disabled_at: null,
      mfa: { enrolled: false, recovery_codes_remaining: 0 },
      invitation: null,
      assignments: [],
    }
    api.on('GET /api/v1/admin/accounts/01J0000000000000000TARGET', () => json(accountWire))
    api.on(MEMBER, membershipRecordNotFound)
    api.on(`POST /api/v1/admin/members/${PERSON_ID}/grants`, () => json(wireGrant(), 201))

    renderApp('/admin/accounts/01J0000000000000000TARGET')
    await screen.findByRole('heading', { level: 1, name: 'Tara Target' })
    expect(await screen.findByText('They hold no membership record.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Grant membership access' }))
    fireEvent.change(screen.getByLabelText('Starts at'), { target: { value: '2026-06-01T00:00' } })
    await user.click(screen.getByLabelText('Open-ended access (no end date)'))
    await user.click(screen.getByRole('button', { name: 'Grant access' }))

    await waitFor(() => {
      expect(api.callsTo(`POST /api/v1/admin/members/${PERSON_ID}/grants`)).toHaveLength(1)
    })
  })
})
