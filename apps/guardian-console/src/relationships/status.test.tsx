import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { pageBody } from '../test/deferred.ts'
import { empty, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import {
  bodyOf,
  dialog,
  guardianBoth,
  prove,
  verificationRefused,
  volunteerBoth,
} from '../test/relationshipPages.ts'
import {
  codedInvalid,
  PERSON,
  REL_ID,
  refusal,
  wireDefaultRole,
  wireGuardianType,
  wireManagement,
  wireRecord,
} from '../test/relationships.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

const G = `/api/v1/admin/relationships/guardians/${PERSON}`
const V = `/api/v1/admin/relationships/volunteers/${PERSON}`
const GUARDIAN_PAGE = `/relationships/guardians/${PERSON}`
const VOLUNTEER_PAGE = `/relationships/volunteers/${PERSON}`

describe('changing a Volunteer-like status (no default role, no verification)', () => {
  it('offers exactly the transitions the server allows, worded by the definition', async () => {
    volunteerBoth()
    renderApp(VOLUNTEER_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    const group = screen.getByRole('group', { name: 'Change status' })
    expect(
      within(group)
        .getAllByRole('button')
        .map((b) => b.textContent),
    ).toEqual(['Change to Active', 'Change to Inactive'])
  })

  it('asks for a deliberate confirmation, sends nothing until it is given, and names the instance and revision shown', async () => {
    const user = userEvent.setup()
    const { api } = volunteerBoth()
    let changed = false
    api.on(`PUT ${V}/status`, () => {
      changed = true
      return json(
        wireManagement({
          type: 'volunteer',
          status: 'active',
          revision: 4,
          transitions: ['inactive', 'pending'],
          default_role: null,
          fields: {},
          basics: null,
        }),
      )
    })
    api.on(`GET ${V}`, () =>
      json(
        wireRecord({
          type: 'volunteer',
          fields: {},
          default_role: null,
          revision: changed ? 4 : 3,
          status: {
            key: changed ? 'active' : 'pending',
            since: '2026-10-01T10:00:00Z',
            by: { id: 'p', display_name: 'Gwen Operator' },
          },
        }),
      ),
    )
    renderApp(VOLUNTEER_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    await user.click(screen.getByRole('button', { name: 'Change to Active' }))
    const confirm = await dialog('Change Ada Lovelace to Active?')
    expect(within(confirm).getByText(/will change from/)).toHaveTextContent('Pending')
    // A Volunteer has no default role: no access option exists, and the dialog says nothing of access.
    expect(within(confirm).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(api.callsTo(`PUT ${V}/status`)).toHaveLength(0) // choosing is not confirming

    await user.click(within(confirm).getByRole('button', { name: 'Change to Active' }))
    await screen.findByText('Ada Lovelace is now Active.')
    expect(bodyOf(api, `PUT ${V}/status`)).toEqual({
      relationship_id: REL_ID,
      revision: 3,
      status: 'active',
    })
    // The response replaced what is shown, and the view read was refreshed to match.
    expect(
      within(screen.getByRole('group', { name: 'Change status' }))
        .getAllByRole('button')
        .map((b) => b.textContent),
    ).toEqual(['Change to Inactive', 'Change to Pending'])
    expect(api.callsTo(`GET ${V}`).length).toBeGreaterThanOrEqual(2)
    expect(api.callsTo('POST /api/v1/security/verify')).toHaveLength(0)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByText(/Nothing has been changed/)).not.toBeInTheDocument()
  })

  it('keeps the dialog open and says why when the server refuses, and does not claim success', async () => {
    const user = userEvent.setup()
    const { api } = volunteerBoth()
    api.on(`PUT ${V}/status`, codedInvalid('transition_not_allowed'))
    renderApp(VOLUNTEER_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    await user.click(
      within(await dialog('Change Ada Lovelace to Inactive?')).getByRole('button', {
        name: 'Change to Inactive',
      }),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'not a change this volunteer can make',
    )
    expect(screen.getByRole('dialog')).toBeVisible()
    expect(screen.queryByText(/is now/)).not.toBeInTheDocument()
  })

  it('never sends a role decision', async () => {
    const user = userEvent.setup()
    const { api } = volunteerBoth()
    api.on(
      `PUT ${V}/status`,
      json(
        wireManagement({
          type: 'volunteer',
          status: 'active',
          default_role: null,
          fields: {},
          basics: null,
        }),
      ),
    )
    renderApp(VOLUNTEER_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Change to Active' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Active' }),
    )
    await screen.findByText('Ada Lovelace is now Active.')
    expect('default_role' in (bodyOf(api, `PUT ${V}/status`) as object)).toBe(false)
  })
})

describe('changing a Guardian-like status', () => {
  it('leaving Active sends no default role and says the access given through it is withdrawn', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth()
    api.on(
      `PUT ${G}/status`,
      json(wireManagement({ status: 'inactive', transitions: ['active'], revision: 4 })),
    )
    renderApp(GUARDIAN_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

    await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
    const confirm = await dialog('Change Ada Lovelace to Inactive?')
    expect(
      within(confirm).getByText(
        /Any Guardian Initiate access given through this relationship is withdrawn/,
      ),
    ).toBeVisible()
    expect(within(confirm).queryByRole('checkbox')).not.toBeInTheDocument()
    await user.click(within(confirm).getByRole('button', { name: 'Change to Inactive' }))
    await screen.findByText('Ada Lovelace is now Inactive.')
    expect(bodyOf(api, `PUT ${G}/status`)).toEqual({
      relationship_id: REL_ID,
      revision: 3,
      status: 'inactive',
    })
  })

  describe('entering Active asks about Guardian Initiate access (ADR 0038, K5)', () => {
    // The whole table, driven through the real screen: what the option starts as, and what is sent if it is left alone.
    const rows: [
      string,
      { decision: 'granted' | 'declined' | null; mayProvision: boolean },
      { checked: boolean; disabled: boolean },
      string,
    ][] = [
      [
        'stored granted, may provision',
        { decision: 'granted', mayProvision: true },
        { checked: true, disabled: false },
        'grant',
      ],
      [
        'stored declined, may provision',
        { decision: 'declined', mayProvision: true },
        { checked: false, disabled: false },
        'decline',
      ],
      [
        'nothing stored, may provision',
        { decision: null, mayProvision: true },
        { checked: true, disabled: false },
        'grant',
      ],
      [
        'stored granted, may NOT provision',
        { decision: 'granted', mayProvision: false },
        { checked: false, disabled: true },
        'defer',
      ],
      [
        'stored declined, may NOT provision',
        { decision: 'declined', mayProvision: false },
        { checked: false, disabled: true },
        'defer',
      ],
      [
        'nothing stored, may NOT provision',
        { decision: null, mayProvision: false },
        { checked: false, disabled: true },
        'defer',
      ],
    ]

    it.each(rows)('%s', async (_name, { decision, mayProvision }, expected, sent) => {
      const user = userEvent.setup()
      const { api } = guardianBoth({
        type: {
          actions: { view: true, manage: true, delete: true, provision_default_role: mayProvision },
        },
        management: {
          status: 'inactive',
          transitions: ['active'],
          default_role: wireDefaultRole({ decision }),
        },
        view: {
          status: {
            key: 'inactive',
            since: '2026-09-01T10:00:00Z',
            by: { id: 'p', display_name: 'Gwen Operator' },
          },
        },
      })
      api.on(`PUT ${G}/status`, json(wireManagement({ status: 'active', revision: 4 })))
      renderApp(GUARDIAN_PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

      await user.click(screen.getByRole('button', { name: 'Change to Active' }))
      const confirm = await dialog('Change Ada Lovelace to Active?')
      const option = within(confirm).getByRole('checkbox', {
        name: 'Grant Guardian Initiate access',
      })
      if (expected.checked) expect(option).toBeChecked()
      else expect(option).not.toBeChecked()
      if (expected.disabled) expect(option).toBeDisabled()
      else expect(option).toBeEnabled()
      if (!mayProvision) {
        expect(
          within(confirm).getByText(
            /Granting Guardian Initiate access needs permission to assign roles/,
          ),
        ).toBeVisible()
      }

      // A preselection is not a decision: nothing has been sent, and confirming without touching it sends what it showed.
      expect(api.callsTo(`PUT ${G}/status`)).toHaveLength(0)
      await user.click(within(confirm).getByRole('button', { name: 'Change to Active' }))
      await screen.findByText('Ada Lovelace is now Active.')
      expect(bodyOf(api, `PUT ${G}/status`)).toEqual({
        relationship_id: REL_ID,
        revision: 3,
        status: 'active',
        default_role: sent,
      })
    })

    it('sends what the operator changed it to, in either direction', async () => {
      const user = userEvent.setup()
      const { api } = guardianBoth({
        management: { status: 'inactive', transitions: ['active'] },
        view: {
          status: {
            key: 'inactive',
            since: '2026-09-01T10:00:00Z',
            by: { id: 'p', display_name: 'Gwen Operator' },
          },
        },
      })
      api.on(`PUT ${G}/status`, json(wireManagement({ status: 'active', revision: 4 })))
      renderApp(GUARDIAN_PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
      await user.click(screen.getByRole('button', { name: 'Change to Active' }))
      const confirm = await dialog('Change Ada Lovelace to Active?')
      await user.click(
        within(confirm).getByRole('checkbox', { name: 'Grant Guardian Initiate access' }),
      )
      expect(
        within(confirm).getByText(/will not be given Guardian Initiate access now/),
      ).toBeVisible()
      await user.click(within(confirm).getByRole('button', { name: 'Change to Active' }))
      await screen.findByText('Ada Lovelace is now Active.')
      expect((bodyOf(api, `PUT ${G}/status`) as { default_role: string }).default_role).toBe(
        'decline',
      )
    })

    it("does not look at the person's other roles to decide whether to offer it", async () => {
      guardianBoth({ management: { status: 'inactive', transitions: ['active'] } })
      const user = userEvent.setup()
      renderApp(GUARDIAN_PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
      await user.click(screen.getByRole('button', { name: 'Change to Active' }))
      const confirm = await dialog('Change Ada Lovelace to Active?')
      expect(
        within(confirm).getByRole('checkbox', { name: 'Grant Guardian Initiate access' }),
      ).toBeVisible()
    })
  })

  describe('recent verification (the shared step-up flow)', () => {
    it('prompts, does not repeat the refused change, and sends it only on a deliberate second press', async () => {
      const user = userEvent.setup()
      const { api } = guardianBoth()
      let verified = false
      api.on(`PUT ${G}/status`, () =>
        verified
          ? json(wireManagement({ status: 'inactive', transitions: ['active'], revision: 4 }))
          : verificationRefused(),
      )
      api.on('POST /api/v1/security/verify', () => {
        verified = true
        return empty()
      })
      renderApp(GUARDIAN_PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

      await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
      await user.click(
        within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
      )
      const prompt = await dialog('Confirm it is you')
      expect(within(prompt).getByText(/Nothing has been changed yet/)).toBeVisible()
      await expectNoAxeViolations(pageBody())
      await prove(user)

      await waitFor(() => {
        expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
      })
      expect(api.callsTo(`PUT ${G}/status`)).toHaveLength(1)
      expect(await screen.findByText(/confirm again to continue/)).toBeVisible()

      await user.click(
        within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
      )
      await screen.findByText('Ada Lovelace is now Inactive.')
      expect(api.callsTo(`PUT ${G}/status`)).toHaveLength(2)
    })

    it('says nothing was changed if the prompt is cancelled', async () => {
      const user = userEvent.setup()
      const { api } = guardianBoth()
      api.on(`PUT ${G}/status`, () => verificationRefused())
      renderApp(GUARDIAN_PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
      await user.click(screen.getByRole('button', { name: 'Change to Inactive' }))
      await user.click(
        within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Inactive' }),
      )
      await user.click(
        within(await dialog('Confirm it is you')).getByRole('button', { name: 'Cancel' }),
      )
      expect(
        await screen.findByText(/Verification was cancelled. Nothing has been changed./),
      ).toBeVisible()
      expect(api.callsTo(`PUT ${G}/status`)).toHaveLength(1)
    })
  })

  it('tells a refusal to grant apart from a failure, and records nothing as done', async () => {
    const user = userEvent.setup()
    const { api } = guardianBoth({
      management: { status: 'inactive', transitions: ['active'] },
      view: {
        status: {
          key: 'inactive',
          since: '2026-09-01T10:00:00Z',
          by: { id: 'p', display_name: 'Gwen Operator' },
        },
      },
    })
    api.on(`PUT ${G}/status`, refusal('role_provisioning_not_permitted', 403))
    renderApp(GUARDIAN_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    await user.click(screen.getByRole('button', { name: 'Change to Active' }))
    await user.click(
      within(await dialog(/Change Ada/)).getByRole('button', { name: 'Change to Active' }),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Granting Guardian Initiate access needs permission to assign roles, which you do not have. Nothing was changed.',
    )
    expect(screen.queryByText(/is now Active/)).not.toBeInTheDocument()
  })

  it('has no Pending state and adds none: only what the definition and the server offer', async () => {
    guardianBoth()
    renderApp(GUARDIAN_PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByText(/Pending/)).not.toBeInTheDocument()
    expect(wireGuardianType().initial_states).toEqual(['active', 'inactive'])
  })
})
