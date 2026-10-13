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
  serveRecord,
  verificationRefused,
  volunteerBoth,
} from '../test/relationshipPages.ts'
import {
  GWEN,
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
const PAGE = `/relationships/guardians/${PERSON}`
const INACTIVE = { key: 'inactive', since: '2026-09-01T10:00:00Z', by: GWEN }
const panel = () => screen.getByRole('region', { name: 'Guardian Initiate access' })

describe('the default-role panel', () => {
  describe('says what the decision and the grant add up to, about this relationship only', () => {
    it.each([
      [
        'granted and in effect',
        {
          decision: 'granted',
          effective: true,
          decided_by: GWEN,
          decided_at: '2026-09-02T10:00:00Z',
        },
        'active',
        ['Granted, and in effect', 'Gwen Operator'],
      ],
      [
        'declined',
        { decision: 'declined', decided_by: GWEN, decided_at: '2026-09-02T10:00:00Z' },
        'active',
        ['Declined', 'Gwen Operator'],
      ],
      ['not decided', { decision: null }, 'active', ['Not decided']],
      [
        'withdrawn because the relationship is inactive',
        {
          decision: 'granted',
          effective: false,
          decided_by: GWEN,
          decided_at: '2026-09-02T10:00:00Z',
        },
        'inactive',
        ['Granted, withdrawn while not active', 'It is not restored automatically'],
      ],
    ])('%s', async (_name, state, status, words) => {
      guardianBoth({
        management: {
          status,
          transitions: [status === 'active' ? 'inactive' : 'active'],
          default_role: wireDefaultRole(state),
        },
        view: { status: { ...INACTIVE, key: status }, default_role: wireDefaultRole(state) },
      })
      renderApp(PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
      for (const word of words) expect(within(panel()).getByText(new RegExp(word))).toBeVisible()
      // Only this role: the panel names no other role and no capability.
      expect(panel().textContent).not.toMatch(
        /guardian-|platform|senior|full|console\.access|capabilit/i,
      )
    })
  })

  it('offers Grant only while the relationship counts, to an operator the definition says may provision', async () => {
    guardianBoth({
      management: { default_role: wireDefaultRole({ decision: 'declined' }) },
    })
    const first = renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(within(panel()).getByRole('button', { name: 'Grant access' })).toBeVisible()
    first.unmount()

    // Inactive: no grant and no restoration option, and nothing left to withdraw.
    guardianBoth({
      management: {
        status: 'inactive',
        transitions: ['active'],
        default_role: wireDefaultRole({ decision: 'granted' }),
      },
      view: { status: INACTIVE },
    })
    const second = renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(within(panel()).queryByRole('button', { name: 'Grant access' })).not.toBeInTheDocument()
    expect(
      within(panel()).queryByRole('button', { name: 'Withdraw access' }),
    ).not.toBeInTheDocument()
    second.unmount()

    // Not permitted to provision: no Grant, and an honest explanation.
    guardianBoth({
      type: { actions: { view: true, manage: true, delete: true, provision_default_role: false } },
      management: { default_role: wireDefaultRole({ decision: 'declined' }) },
    })
    renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(within(panel()).queryByRole('button', { name: 'Grant access' })).not.toBeInTheDocument()
    expect(within(panel()).getByText(/needs permission to assign roles/)).toBeVisible()
  })

  it('offers Withdraw with manage alone: it does not need the authority to assign roles', async () => {
    guardianBoth({
      type: { actions: { view: true, manage: true, delete: true, provision_default_role: false } },
      management: {
        default_role: wireDefaultRole({
          decision: 'granted',
          effective: true,
          decided_by: GWEN,
          decided_at: '2026-09-02T10:00:00Z',
        }),
      },
    })
    renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(within(panel()).getByRole('button', { name: 'Withdraw access' })).toBeVisible()
    expect(within(panel()).queryByRole('button', { name: 'Grant access' })).not.toBeInTheDocument()
  })

  it('shows a view-only operator the state and no control, whatever a hint says', async () => {
    serveRecord({
      types: [
        wireGuardianType({
          // A hint that claims provisioning cannot be true without manage; even so it opens nothing for someone who only views.
          actions: { view: true, manage: false, delete: false, provision_default_role: true },
        }),
      ],
      slug: 'guardians',
      view: () =>
        json(
          wireRecord({
            default_role: wireDefaultRole({
              decision: 'granted',
              effective: true,
              decided_by: GWEN,
              decided_at: '2026-09-02T10:00:00Z',
            }),
          }),
        ),
    })
    renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(within(panel()).getByText('Granted, and in effect')).toBeVisible()
    expect(within(panel()).queryByRole('button')).not.toBeInTheDocument()
  })

  it('is absent for a type with no default role', async () => {
    volunteerBoth()
    renderApp(`/relationships/volunteers/${PERSON}`)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByRole('region', { name: /access$/ })).not.toBeInTheDocument()
  })

  describe('granting', () => {
    it('is confirmed deliberately, goes through the shared step-up flow, and sends the instance, the revision and grant', async () => {
      const user = userEvent.setup()
      const { api } = guardianBoth({
        management: { default_role: wireDefaultRole({ decision: 'declined' }) },
      })
      let verified = false
      api.on(`PUT ${G}/default-role`, () =>
        verified
          ? json(
              wireManagement({
                revision: 4,
                default_role: wireDefaultRole({
                  decision: 'granted',
                  effective: true,
                  decided_by: GWEN,
                  decided_at: '2026-10-12T10:00:00Z',
                }),
              }),
            )
          : verificationRefused(),
      )
      api.on('POST /api/v1/security/verify', () => {
        verified = true
        return empty()
      })
      renderApp(PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

      await user.click(within(panel()).getByRole('button', { name: 'Grant access' }))
      const confirm = await dialog('Grant Guardian Initiate access to Ada Lovelace?')
      expect(api.callsTo(`PUT ${G}/default-role`)).toHaveLength(0) // a button press opens a question, nothing more
      await user.click(within(confirm).getByRole('button', { name: 'Grant access' }))

      await prove(user)
      await waitFor(() => {
        expect(screen.queryByRole('dialog', { name: 'Confirm it is you' })).not.toBeInTheDocument()
      })
      expect(api.callsTo(`PUT ${G}/default-role`)).toHaveLength(1) // not repeated for them
      await user.click(
        within(await dialog(/Grant Guardian Initiate/)).getByRole('button', {
          name: 'Grant access',
        }),
      )

      await screen.findByText('Guardian Initiate access was granted to Ada Lovelace.')
      expect(bodyOf(api, `PUT ${G}/default-role`, 1)).toEqual({
        relationship_id: REL_ID,
        revision: 3,
        default_role: 'grant',
      })
      expect(within(panel()).getByText('Granted, and in effect')).toBeVisible()
      await expectNoAxeViolations(pageBody())
    })

    it('tells a refusal for lack of permission apart from a relationship that is not active, and a stale one', async () => {
      const user = userEvent.setup()
      const { api } = guardianBoth({
        management: { default_role: wireDefaultRole({ decision: 'declined' }) },
      })
      renderApp(PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

      api.on(`PUT ${G}/default-role`, refusal('role_provisioning_not_permitted', 403))
      await user.click(within(panel()).getByRole('button', { name: 'Grant access' }))
      await user.click(
        within(await dialog(/Grant Guardian/)).getByRole('button', { name: 'Grant access' }),
      )
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'needs permission to assign roles, which you do not have. Nothing was changed.',
      )
      await user.click(screen.getByRole('button', { name: 'Cancel' }))

      api.on(`PUT ${G}/default-role`, refusal('relationship_not_active', 409))
      await user.click(within(panel()).getByRole('button', { name: 'Grant access' }))
      await user.click(
        within(await dialog(/Grant Guardian/)).getByRole('button', { name: 'Grant access' }),
      )
      expect(await screen.findByRole('alert')).toHaveTextContent(
        'can only be granted while the guardian is active. Nothing was changed.',
      )
      expect(screen.queryByText(/was granted to/)).not.toBeInTheDocument()
    })
  })

  describe('withdrawing', () => {
    it('sends decline, says roles granted directly are untouched, and needs no role-assignment authority', async () => {
      const user = userEvent.setup()
      const { api } = guardianBoth({
        type: {
          actions: { view: true, manage: true, delete: true, provision_default_role: false },
        },
        management: {
          default_role: wireDefaultRole({
            decision: 'granted',
            effective: true,
            decided_by: GWEN,
            decided_at: '2026-09-02T10:00:00Z',
          }),
        },
      })
      api.on(
        `PUT ${G}/default-role`,
        json(
          wireManagement({
            revision: 4,
            default_role: wireDefaultRole({
              decision: 'declined',
              decided_by: GWEN,
              decided_at: '2026-10-12T10:00:00Z',
            }),
          }),
        ),
      )
      renderApp(PAGE)
      await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })

      await user.click(within(panel()).getByRole('button', { name: 'Withdraw access' }))
      const confirm = await dialog('Withdraw Guardian Initiate access from Ada Lovelace?')
      expect(
        within(confirm).getByText(/Roles granted to them directly are not affected/),
      ).toBeVisible()
      await user.click(within(confirm).getByRole('button', { name: 'Withdraw access' }))

      await screen.findByText('Guardian Initiate access was withdrawn from Ada Lovelace.')
      expect(bodyOf(api, `PUT ${G}/default-role`)).toEqual({
        relationship_id: REL_ID,
        revision: 3,
        default_role: 'decline',
      })
      expect(within(panel()).getByText('Declined')).toBeVisible()
    })
  })

  it('never sends defer from a button: a decision to defer is only ever the unavailable option on a status change', async () => {
    const { api } = guardianBoth()
    renderApp(PAGE)
    await screen.findByRole('heading', { level: 1, name: 'Ada Lovelace' })
    expect(screen.queryByRole('button', { name: /defer|restore/i })).not.toBeInTheDocument()
    expect(api.callsTo(`PUT ${G}/default-role`)).toHaveLength(0)
  })
})
