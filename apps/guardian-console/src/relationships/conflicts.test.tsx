import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { bodyOf, dialog, serveRecord } from '../test/relationshipPages.ts'
import {
  GWEN,
  notFound,
  PERSON,
  REL_ID,
  REL_ID_2,
  stale,
  wireGuardianType,
  wireManagement,
  wireRecord,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const G = `/api/v1/admin/relationships/guardians/${PERSON}`
const PAGE = `/relationships/guardians/${PERSON}`

/** A relationship that can be moved under the operator: `now` is what the server holds at any moment. */
function movable(initial: { id: string; revision: number }, options: { view?: boolean } = {}) {
  const now = { ...initial, status: 'active', transitions: ['inactive'], by: GWEN }
  const management = () =>
    wireManagement({
      relationship_id: now.id,
      revision: now.revision,
      status: now.status,
      transitions: now.transitions,
    })
  const served = serveRecord({
    types: [
      wireGuardianType(
        options.view === false
          ? { actions: { view: false, manage: true, delete: true, provision_default_role: true } }
          : {},
      ),
    ],
    slug: 'guardians',
    management: () => json(management()),
    ...(options.view === false
      ? {}
      : {
          view: () =>
            json(
              wireRecord({
                relationship_id: now.id,
                revision: now.revision,
                status: {
                  key: now.status,
                  since: '2026-10-05T08:00:00Z',
                  by: { id: 'p2', display_name: 'Someone Else' },
                },
                created_by: { id: 'p3', display_name: 'Rita Recorder' },
                created_at: '2026-10-06T08:00:00Z',
              }),
            ),
        }),
  })
  return { ...served, now, management }
}

async function startStatusChange(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
  await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
  await user.click(
    within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
  )
}

describe('a stale revision (409 stale_revision)', () => {
  it('re-reads, shows the current state and who last changed the status, explains, and does NOT resend', async () => {
    const user = userEvent.setup()
    const world = movable({ id: REL_ID, revision: 3 })
    world.api.on(`PUT ${G}/status`, () => {
      // Someone else changed it first: the server now holds revision 5, already inactive.
      world.now.revision = 5
      world.now.status = 'inactive'
      world.now.transitions = ['active']
      return stale(world.management())
    })
    renderApp(PAGE)
    await startStatusChange(user)

    const notice = await screen.findByText('This guardian was changed while you were working.')
    const box = notice.closest('[role="status"]')
    expect(box).toHaveTextContent('Your change was not applied.')
    expect(box).toHaveTextContent('Its status was last set by Someone Else on')
    // It re-read (the management view and the record), and shows the current state.
    expect(world.api.callsTo(`GET ${G}/management`)).toHaveLength(2)
    expect(world.api.callsTo(`GET ${G}`)).toHaveLength(2)
    expect(await screen.findByRole('button', { name: 'Change to Active' })).toBeVisible()
    // Never sent again for the operator, and the dialog is gone.
    expect(world.api.callsTo(`PUT ${G}/status`)).toHaveLength(1)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    // A generic failure is not what was said.
    expect(screen.queryByText(/unavailable|Something unexpected/)).not.toBeInTheDocument()
  })

  it('a new, deliberate decision uses the current revision and sends once more only when the operator presses again', async () => {
    const user = userEvent.setup()
    const world = movable({ id: REL_ID, revision: 3 })
    let refused = true
    world.api.on(`PUT ${G}/status`, () => {
      if (refused) {
        refused = false
        world.now.revision = 4
        return stale(world.management())
      }
      return json(wireManagement({ status: 'inactive', transitions: ['active'], revision: 5 }))
    })
    renderApp(PAGE)
    await startStatusChange(user)
    await screen.findByText('This guardian was changed while you were working.')
    expect(world.api.callsTo(`PUT ${G}/status`)).toHaveLength(1)

    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
    )
    await screen.findByText('Ada Lovelace is now Inactive.')
    expect(world.api.callsTo(`PUT ${G}/status`)).toHaveLength(2)
    expect(bodyOf(world.api, `PUT ${G}/status`, 1)).toMatchObject({
      relationship_id: REL_ID,
      revision: 4,
    })
    // A successful change clears the old explanation.
    expect(
      screen.queryByText('This guardian was changed while you were working.'),
    ).not.toBeInTheDocument()
  })

  it('says a relationship that was deleted and recorded again is a NEW one, and never attaches the old submission to it, even at the same revision', async () => {
    const user = userEvent.setup()
    // Both instances happen to be at revision 1.
    const world = movable({ id: REL_ID, revision: 1 })
    world.api.on(`PUT ${G}/status`, () => {
      world.now.id = REL_ID_2
      world.now.revision = 1
      return stale(world.management())
    })
    renderApp(PAGE)
    await startStatusChange(user)

    const notice = await screen.findByText('This guardian was deleted and recorded again.')
    const box = notice.closest('[role="status"]')
    expect(box).toHaveTextContent('What you see now is the new guardian.')
    expect(box).toHaveTextContent('it was not applied to this one')
    expect(box).toHaveTextContent('It was recorded again by Rita Recorder on')
    expect(world.api.callsTo(`PUT ${G}/status`)).toHaveLength(1)

    // The operator's next decision, if they make one, names the NEW instance.
    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
    )
    await waitFor(() => {
      expect(world.api.callsTo(`PUT ${G}/status`)).toHaveLength(2)
    })
    expect(bodyOf(world.api, `PUT ${G}/status`, 1)).toMatchObject({
      relationship_id: REL_ID_2,
      revision: 1,
    })
  })

  it('gives a manager who cannot view the explanation, without provenance they may not see', async () => {
    const user = userEvent.setup()
    const world = movable({ id: REL_ID, revision: 3 }, { view: false })
    world.api.on(`PUT ${G}/status`, () => {
      world.now.revision = 4
      return stale(world.management())
    })
    renderApp(PAGE)
    await startStatusChange(user)

    const notice = await screen.findByText('This guardian was changed while you were working.')
    expect(notice.closest('[role="status"]')).not.toHaveTextContent('last set by')
    expect(world.api.callsTo(`GET ${G}`)).toHaveLength(0)
  })

  it('says the relationship no longer exists when the re-read finds nothing, and leaves nothing to act on', async () => {
    const user = userEvent.setup()
    const world = movable({ id: REL_ID, revision: 3 })
    let gone = false
    world.api.on(`GET ${G}/management`, () => (gone ? notFound() : json(world.management())))
    world.api.on(`GET ${G}`, () => (gone ? notFound() : json(wireRecord())))
    world.api.on(`PUT ${G}/status`, () => {
      gone = true
      return stale(world.management())
    })
    renderApp(PAGE)
    await startStatusChange(user)

    expect(await screen.findByText(/no longer exists: it was deleted/)).toBeVisible()
    expect(screen.queryByRole('button', { name: /Change to/ })).not.toBeInTheDocument()
    expect(world.api.callsTo(`PUT ${G}/status`)).toHaveLength(1)
  })

  it('can be dismissed', async () => {
    const user = userEvent.setup()
    const world = movable({ id: REL_ID, revision: 3 })
    world.api.on(`PUT ${G}/status`, () => {
      world.now.revision = 4
      return stale(world.management())
    })
    renderApp(PAGE)
    await startStatusChange(user)
    await screen.findByText('This guardian was changed while you were working.')
    await user.click(screen.getByRole('button', { name: 'Dismiss' }))
    expect(
      screen.queryByText('This guardian was changed while you were working.'),
    ).not.toBeInTheDocument()
  })

  it('applies to a field edit as well, which resets to what is current and does not resend', async () => {
    const user = userEvent.setup()
    const world = movable({ id: REL_ID, revision: 3 })
    world.api.on(`PATCH ${G}/fields`, () => {
      world.now.revision = 4
      return stale(world.management())
    })
    renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Edit details' }))
    await user.type(screen.getByRole('textbox', { name: 'Area of stewardship' }), ' and orchard')
    await user.click(screen.getByRole('button', { name: 'Save details' }))

    await screen.findByText('This guardian was changed while you were working.')
    expect(world.api.callsTo(`PATCH ${G}/fields`)).toHaveLength(1)
    expect(screen.queryByRole('form', { name: /Edit/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit details' })).toBeVisible()
  })
})
