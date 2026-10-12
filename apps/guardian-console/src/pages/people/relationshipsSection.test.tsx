import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../../test/a11y.ts'
import { pageBody } from '../../test/deferred.ts'
import { empty, json } from '../../test/fakeApi.ts'
import { interactionsPage, PERSON_ID, wirePerson } from '../../test/people.ts'
import { renderApp } from '../../test/renderApp.tsx'
import { dialog, verificationRefused } from '../../test/relationshipPages.ts'
import {
  notFound,
  operatorWith,
  refusal,
  serveRelationships,
  wireApprenticeType,
  wireGuardianType,
  wireManagement,
  wireRecord,
  wireVolunteerType,
} from '../../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

// The Person record is by CRM's id; the relationship routes take the same Person id.
const P = PERSON_ID
const G = `/api/v1/admin/relationships/guardians/${P}`
const V = `/api/v1/admin/relationships/volunteers/${P}`

function openPerson(types: Record<string, unknown>[], capabilities: string[] = []) {
  const api = serveRelationships(types, operatorWith('crm.people.view', ...capabilities))
  api.on(`GET /api/v1/admin/people/${P}`, json(wirePerson()))
  api.on(`GET /api/v1/admin/people/${P}/interactions`, json(interactionsPage([])))
  api.on('GET /api/v1/admin/contact-tags', json({ data: [] }))
  return api
}

const asVolunteer = (overrides: Record<string, unknown> = {}) =>
  wireManagement({
    type: 'volunteer',
    status: 'pending',
    transitions: ['active'],
    fields: {},
    default_role: null,
    basics: null,
    ...overrides,
  })

describe('the Relationships section of the Person record', () => {
  it("has one tab for each type the operator may view or manage, in the definitions' order, and nothing else", async () => {
    const api = openPerson([wireVolunteerType(), wireApprenticeType(), wireGuardianType()])
    api.on(`GET ${G}`, json(wireRecord()))
    api.on(`GET ${G}/management`, json(wireManagement()))
    renderApp(`/people/${P}`)

    const tabs = await screen.findByRole('tablist', { name: 'Relationships' })
    expect(
      within(tabs)
        .getAllByRole('tab')
        .map((tab) => tab.textContent),
    ).toEqual(['Guardian', 'Volunteer', 'Apprentice'])
    expect(within(tabs).getByRole('tab', { name: 'Guardian' })).toHaveAttribute(
      'aria-selected',
      'true',
    )
    // Only the selected type has been asked about.
    expect(
      api.calls.some((c) => c.path.includes('/volunteers/') || c.path.includes('/apprentices/')),
    ).toBe(false)
    await expectNoAxeViolations(pageBody())
  })

  it('never learns from the tabs, or from any request, that the person holds a type the operator has no capability for', async () => {
    // A Volunteer manager with no Guardian capability: the served types are the Volunteer alone.
    const api = openPerson([wireVolunteerType()])
    api.on(`GET ${V}`, json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })))
    api.on(`GET ${V}/management`, json(asVolunteer()))
    renderApp(`/people/${P}`)

    const tabs = await screen.findByRole('tablist', { name: 'Relationships' })
    expect(within(tabs).getAllByRole('tab')).toHaveLength(1)
    expect(within(tabs).getByRole('tab', { name: 'Volunteer' })).toBeVisible()
    await screen.findByRole('region', { name: 'Volunteer' })
    expect(api.calls.some((c) => c.path.includes('guardian'))).toBe(false)
    expect(pageBody().textContent).not.toMatch(/guardian/i)
  })

  it("shows the generic panel in the tab, with the controls the operator's capabilities allow", async () => {
    const api = openPerson([wireGuardianType()])
    api.on(`GET ${G}`, json(wireRecord()))
    api.on(`GET ${G}/management`, json(wireManagement()))
    renderApp(`/people/${P}`)
    const panel = await screen.findByRole('tabpanel')
    expect(await within(panel).findByRole('region', { name: 'Guardian' })).toBeVisible()
    expect(within(panel).getByRole('button', { name: 'Change to Inactive' })).toBeVisible()
    // Panels sit one level beneath the section's own heading.
    expect(within(panel).getAllByRole('heading', { level: 3 }).length).toBeGreaterThan(0)
    expect(within(panel).queryByRole('heading', { level: 2 })).not.toBeInTheDocument()
  })

  it('switches tabs with the keyboard, and reads the other type only when it is shown', async () => {
    const user = userEvent.setup()
    const api = openPerson([wireGuardianType(), wireVolunteerType()])
    api.on(`GET ${G}`, json(wireRecord()))
    api.on(`GET ${G}/management`, json(wireManagement()))
    api.on(`GET ${V}`, json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })))
    api.on(`GET ${V}/management`, json(asVolunteer()))
    renderApp(`/people/${P}`)
    const guardian = await screen.findByRole('tab', { name: 'Guardian' })
    await screen.findByRole('region', { name: 'Guardian' })

    guardian.focus()
    await user.keyboard('{ArrowRight}')
    const volunteer = screen.getByRole('tab', { name: 'Volunteer' })
    expect(volunteer).toHaveFocus()
    expect(volunteer).toHaveAttribute('aria-selected', 'true')
    expect(volunteer).toHaveAttribute('tabindex', '0')
    expect(guardian).toHaveAttribute('tabindex', '-1')
    expect(await screen.findByRole('region', { name: 'Volunteer' })).toBeVisible()
    expect(api.callsTo(`GET ${V}`)).toHaveLength(1)

    await user.keyboard('{Home}')
    expect(guardian).toHaveFocus()
    await user.keyboard('{End}')
    expect(volunteer).toHaveFocus()
    await user.keyboard('{ArrowRight}') // wraps
    expect(guardian).toHaveFocus()
  })

  it('offers to record a person who lacks the type, when the operator may manage it, and records nothing by doing so', async () => {
    const user = userEvent.setup()
    const api = openPerson([wireVolunteerType()])
    api.on(`GET ${V}`, notFound())
    api.on(`GET ${V}/management`, notFound())
    api.on(
      'GET /api/v1/admin/relationships/volunteers/candidates',
      json({ data: [], truncated: false }),
    )
    renderApp(`/people/${P}`)

    expect(await screen.findByText('Ada Lovelace is not recorded as a Volunteer.')).toBeVisible()
    await user.click(screen.getByRole('link', { name: 'Record as Volunteer' }))
    // Intake opens on its existing-person confirmation, naming them, and still records nothing.
    expect(await dialog('Record Ada Lovelace?')).toBeVisible()
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(0)
  })

  it('does not offer to record it to an operator who can only view the type', async () => {
    const api = openPerson([
      wireVolunteerType({
        actions: { view: true, manage: false, delete: false, provision_default_role: false },
      }),
    ])
    api.on(`GET ${V}`, notFound())
    renderApp(`/people/${P}`)
    expect(await screen.findByText('Ada Lovelace is not recorded as a Volunteer.')).toBeVisible()
    expect(screen.queryByRole('link', { name: /Record as/ })).not.toBeInTheDocument()
  })

  it('has no section at all for an operator who may do nothing with any type', async () => {
    openPerson([
      wireGuardianType({
        actions: { view: false, manage: false, delete: false, provision_default_role: false },
      }),
    ])
    renderApp(`/people/${P}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByRole('tablist', { name: 'Relationships' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Relationships' })).not.toBeInTheDocument()
  })

  it('says so, and offers to try again, when the definitions cannot be read', async () => {
    const api = openPerson([wireGuardianType()])
    api.on('GET /api/v1/admin/relationship-types', json({ message: 'boom' }, 500))
    renderApp(`/people/${P}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('temporarily unavailable')
    expect(screen.getByRole('button', { name: 'Try again' })).toBeVisible()
  })

  it('still adds no Account, role, membership or security information to the Person record', async () => {
    const api = openPerson([wireGuardianType()])
    api.on(`GET ${G}`, json(wireRecord()))
    api.on(`GET ${G}/management`, json(wireManagement()))
    renderApp(`/people/${P}`)
    await screen.findByRole('region', { name: 'Guardian' })
    expect(
      api.calls.some(
        (c) =>
          c.path.includes('/accounts') || c.path.includes('/members') || c.path.includes('/roles'),
      ),
    ).toBe(false)
    expect(pageBody().textContent).not.toMatch(/membership|two-step|password|login/i)
  })

  it('deletes a relationship from its tab, through step-up, and leaves the tab saying the person is no longer recorded', async () => {
    const user = userEvent.setup()
    const api = openPerson([wireVolunteerType()])
    api.on(`GET ${V}`, json(wireRecord({ type: 'volunteer', fields: {}, default_role: null })))
    api.on(`GET ${V}/management`, json(asVolunteer()))
    let verified = false
    api.on(`DELETE ${V}`, () => (verified ? empty() : verificationRefused()))
    api.on('POST /api/v1/security/verify', () => {
      verified = true
      return empty()
    })
    renderApp(`/people/${P}`)
    await screen.findByRole('region', { name: 'Volunteer' })
    await user.click(screen.getByRole('button', { name: /Delete this volunteer/ }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )
    // The shared prompt: prove, then confirm again.
    const prompt = await dialog('Confirm it is you')
    await user.type(within(prompt).getByLabelText('Current password'), 'pw')
    await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
    await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
    await user.click(
      within(await dialog(/Delete Ada/)).getByRole('button', { name: 'Delete permanently' }),
    )

    expect(
      await screen.findByText("Ada Lovelace's volunteer was deleted permanently."),
    ).toBeVisible()
    expect(screen.getByText('Ada Lovelace is not recorded as a Volunteer.')).toBeVisible()
    // The Person page is still the Person page.
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace' })).toBeVisible()
  })

  it('reports a refusal inside the tab rather than anywhere else', async () => {
    const api = openPerson([wireGuardianType()])
    api.on(`GET ${G}`, refusal('anything', 403))
    api.on(`GET ${G}/management`, refusal('anything', 403))
    renderApp(`/people/${P}`)
    expect(await within(await screen.findByRole('tabpanel')).findByRole('alert')).toHaveTextContent(
      'not permitted',
    )
  })
})
