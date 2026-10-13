import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { nth, pageBody } from '../test/deferred.ts'
import { empty, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import {
  dialog,
  guardianBoth,
  prove,
  serveRecord,
  verificationRefused,
  volunteerBoth,
} from '../test/relationshipPages.ts'
import {
  directoryPage,
  notFound,
  PERSON,
  REL_ID,
  REL_ID_2,
  refusal,
  stale,
  wireEntry,
  wireGuardianType,
  wireManagement,
  wireRecord,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const G = `/api/v1/admin/relationships/guardians/${PERSON}`
/** The deletions sent (they carry a query string, which `callsTo` does not match). */
const deletes = (api: { calls: { method: string; path: string; body: unknown }[] }) =>
  api.calls.filter((c) => c.method === 'DELETE' && c.path.startsWith(G))

describe('permanent deletion', () => {
  it('is the last thing on the record, in a danger zone', async () => {
    guardianBoth()
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    const panels = screen.getAllByRole('region')
    const last = nth(panels, panels.length - 1)
    expect(last).toHaveAccessibleName('Delete permanently')
    expect(within(last).getByText(/This cannot be undone/)).toBeVisible()
  })

  it('says what goes and what stays, and for a type with a default role says the access is withdrawn', async () => {
    const user = userEvent.setup()
    guardianBoth()
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: /Delete this guardian/ }))
    const confirm = await dialog("Delete Ada Lovelace's guardian permanently?")

    const text = confirm.textContent
    for (const goes of ['this guardian;', 'its details;', 'its history.'])
      expect(text).toContain(goes)
    for (const stays of [
      'Ada Lovelace as a person;',
      'their contact information;',
      'their other relationships;',
      'roles that were granted to them directly.',
    ]) {
      expect(text).toContain(stays)
    }
    expect(text).toContain('It cannot be undone.')
    expect(text).toContain(
      'Guardian Initiate access that was given through this guardian is withdrawn.',
    )
    await expectNoAxeViolations(pageBody())
  })

  it('does not mention access for a type that has no default role', async () => {
    const user = userEvent.setup()
    volunteerBoth()
    renderApp(`/relationships/volunteers/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: /Delete this volunteer/ }))
    const confirm = await dialog("Delete Ada Lovelace's volunteer permanently?")
    expect(confirm.textContent).not.toMatch(/access/i)
  })

  it('goes through step-up, sends the instance and revision as query parameters with no body, and lands somewhere the operator can go', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth()
    let verified = false
    api.on(`DELETE ${G}`, () => (verified ? empty() : verificationRefused()))
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return empty()
    })
    api.on('GET /api/v1/admin/relationships/guardians', json(directoryPage([wireEntry()])))
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    await user.click(screen.getByRole('button', { name: /Delete this guardian/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )
    await prove(user)
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
    })
    expect(deletes(api)).toHaveLength(1) // not repeated for them
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )

    // To the directory, which this operator may read, with the outcome said.
    expect(await screen.findByRole('heading', { level: 1, name: 'Guardians' })).toBeVisible()
    expect(screen.getByText("Ada Lovelace's guardian was deleted permanently.")).toBeVisible()
    const call = deletes(api)[1]
    expect(call?.path).toBe(`${G}?relationship_id=${REL_ID}&revision=3`)
    expect(call?.body).toBeNull()
  })

  it('sends a manager who cannot view to the way to record someone, not to a directory they may not read', async () => {
    const user = userEvent.setup()
    const { api } = serveRecord({
      types: [
        wireGuardianType({
          actions: { view: false, manage: true, delete: true, provision_default_role: true },
        }),
      ],
      slug: 'guardians',
      management: () => json(wireManagement()),
    })
    api.on(`DELETE ${G}`, empty())
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: /Delete this guardian/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Record a Guardian' }),
    ).toBeVisible()
    expect(
      api.calls.some(
        (c) => c.method === 'GET' && c.path === '/api/v1/admin/relationships/guardians',
      ),
    ).toBe(false)
  })

  it('says a relationship still in use was not deleted, and nothing was removed', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth()
    api.on(`DELETE ${G}`, refusal('relationship_in_use', 409))
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: /Delete this guardian/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'still in use by other records, so it was not deleted',
    )
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace' })).toBeVisible()
  })

  it('never deletes a relationship that was recorded again in its place: it explains and stops', async () => {
    const user = userEvent.setup()
    let current = { id: REL_ID, revision: 1 }
    const { api } = serveRecord({
      types: [wireGuardianType()],
      slug: 'guardians',
      management: () =>
        json(wireManagement({ relationship_id: current.id, revision: current.revision })),
      view: () => json(wireRecord({ relationship_id: current.id, revision: current.revision })),
    })
    api.on(`DELETE ${G}`, () => {
      current = { id: REL_ID_2, revision: 1 }
      return stale(wireManagement({ relationship_id: REL_ID_2, revision: 1 }))
    })
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: /Delete this guardian/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )

    expect(await screen.findByText('This guardian was deleted and recorded again.')).toBeVisible()
    expect(deletes(api)).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace' })).toBeVisible() // the new one is still there
  })

  it('treats a relationship that is already gone as gone, and offers no further action on it', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth()
    api.on(`DELETE ${G}`, notFound())
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: /Delete this guardian/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'could not be found. It may have been deleted.',
    )
  })

  it('is not offered to an operator whom the definition does not let delete', async () => {
    serveRecord({
      types: [
        wireGuardianType({
          actions: { view: true, manage: true, delete: false, provision_default_role: true },
        }),
      ],
      slug: 'guardians',
      management: () => json(wireManagement()),
      view: () => json(wireRecord()),
    })
    renderApp(`/relationships/guardians/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByRole('region', { name: 'Delete permanently' })).not.toBeInTheDocument()
  })
})
