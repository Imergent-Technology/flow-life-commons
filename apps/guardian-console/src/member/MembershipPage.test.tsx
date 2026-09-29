import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { vi } from 'vitest'

import { accountFor, FakeApi, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'

const MEMBER = accountFor({ capabilities: [] })

function serve(membership: unknown) {
  const api = new FakeApi()
  api.on('GET /api/v1/me', () => json(MEMBER))
  api.on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  api.on('GET /api/v1/my/membership', () => json(membership))
  api.install()
  return api
}

afterEach(() => {
  vi.unstubAllGlobals()
})

async function open() {
  renderApp('/my/membership')
  return screen.findByRole('heading', { level: 1, name: 'Membership' })
}

describe('no grants at all', () => {
  it('is a normal, successful answer: Inactive, with a calm empty state, never a refusal', async () => {
    serve({ active: false, current_access_ends_at: null, open_ended: false, grants: [] })
    await open()

    expect(screen.getByText('Inactive')).toBeVisible()
    expect(screen.getByText('Not currently active')).toBeVisible()
    expect(screen.getByText('No membership history yet')).toBeVisible()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('active bounded', () => {
  it('shows Active and the access-through date', async () => {
    serve({
      active: true,
      current_access_ends_at: '2027-01-15T00:00:00Z',
      open_ended: false,
      grants: [
        { starts_at: '2026-01-15T00:00:00Z', ends_at: '2027-01-15T00:00:00Z', revoked: false },
      ],
    })
    await open()

    expect(screen.getByText('Active')).toBeVisible()
    expect(screen.getByText(/Access through/)).toHaveTextContent('Access through Jan 15, 2027')
    expect(screen.queryByText('Revoked')).not.toBeInTheDocument()
  })
})

describe('active open-ended', () => {
  it('shows Active and Open-ended, with no access-through date', async () => {
    serve({
      active: true,
      current_access_ends_at: null,
      open_ended: true,
      grants: [{ starts_at: '2026-01-15T00:00:00Z', ends_at: null, revoked: false }],
    })
    await open()

    expect(screen.getByText('Active')).toBeVisible()
    expect(screen.getAllByText('Open-ended').length).toBeGreaterThan(0)
  })
})

describe('future-only', () => {
  it('is not currently active, but the future grant is present in the history', async () => {
    serve({
      active: false,
      current_access_ends_at: null,
      open_ended: false,
      grants: [
        { starts_at: '2027-06-01T00:00:00Z', ends_at: '2028-06-01T00:00:00Z', revoked: false },
      ],
    })
    await open()

    expect(screen.getByText('Inactive')).toBeVisible()
    expect(screen.getByText('Not currently active')).toBeVisible()
    expect(screen.getByText(/Jun 1, 2027/)).toBeVisible()
    expect(screen.queryByText('No membership history yet')).not.toBeInTheDocument()
  })
})

describe('expired', () => {
  it('is not currently active, and the lapsed grant is shown truthfully as history, not hidden', async () => {
    serve({
      active: false,
      current_access_ends_at: null,
      open_ended: false,
      grants: [
        { starts_at: '2024-01-01T00:00:00Z', ends_at: '2025-01-01T00:00:00Z', revoked: false },
      ],
    })
    await open()

    expect(screen.getByText('Inactive')).toBeVisible()
    expect(screen.getByText(/Jan 1, 2024/)).toBeVisible()
    expect(screen.getByText(/Jan 1, 2025/)).toBeVisible()
  })
})

describe('revoked', () => {
  it('shows the Revoked fact on its own row, never a colour alone', async () => {
    serve({
      active: false,
      current_access_ends_at: null,
      open_ended: false,
      grants: [
        { starts_at: '2026-01-01T00:00:00Z', ends_at: '2027-01-01T00:00:00Z', revoked: true },
      ],
    })
    await open()

    expect(screen.getByText('Revoked')).toBeVisible()
  })
})

describe('multiple historical grants', () => {
  it('shows the complete history: expired, revoked and the currently active grant together', async () => {
    serve({
      active: true,
      current_access_ends_at: '2027-06-01T00:00:00Z',
      open_ended: false,
      grants: [
        { starts_at: '2023-01-01T00:00:00Z', ends_at: '2024-01-01T00:00:00Z', revoked: false },
        { starts_at: '2024-06-01T00:00:00Z', ends_at: '2025-06-01T00:00:00Z', revoked: true },
        { starts_at: '2026-06-01T00:00:00Z', ends_at: '2027-06-01T00:00:00Z', revoked: false },
      ],
    })
    await open()

    expect(screen.getByText('Active')).toBeVisible()
    expect(screen.getByText('Revoked')).toBeVisible()
    expect(screen.getAllByRole('listitem')).toHaveLength(3)
  })
})

describe('it never recomputes "active" itself', () => {
  it("trusts the server's active/open_ended/current_access_ends_at even when a grant's own dates would suggest otherwise", async () => {
    // A grant that STARTS in the past with NO end — exactly the shape a naive re-derivation in React would
    // read as "currently active, open-ended" — but the server says otherwise. The page must show what the
    // server said, not what these dates alone would imply.
    serve({
      active: false,
      current_access_ends_at: null,
      open_ended: false,
      grants: [{ starts_at: '2020-01-01T00:00:00Z', ends_at: null, revoked: false }],
    })
    await open()

    expect(screen.getByText('Inactive')).toBeVisible()
    expect(screen.getByText('Not currently active')).toBeVisible()
    // The grant itself is still shown truthfully as "open-ended" in its OWN row — that is the grant's
    // term, not the derived current-access state, and the DTO carries both independently.
    expect(screen.getAllByText('Open-ended').length).toBeGreaterThan(0)
  })
})

describe('errors', () => {
  it('shows a normal error, never "not a member", when the request fails', async () => {
    const api = serve(null)
    api.on('GET /api/v1/my/membership', () => json({ message: 'down' }, 503))
    await open()

    expect(await screen.findByRole('alert')).toHaveTextContent(/temporarily unavailable/i)
    expect(screen.queryByText('Inactive')).not.toBeInTheDocument()
    expect(screen.queryByText('No membership history yet')).not.toBeInTheDocument()
  })
})
