import { screen, within } from '@testing-library/react'
import type userEvent from '@testing-library/user-event'

import type { CurrentAccount } from '../api/auth.ts'
import { json, type FakeApi } from './fakeApi.ts'
import {
  operatorWith,
  PERSON,
  serveRelationships,
  wireGuardianType,
  wireManagement,
  wireRecord,
  wireVolunteerType,
  type Wire,
} from './relationships.ts'

type User = ReturnType<typeof userEvent.setup>

export interface Served {
  api: FakeApi
  /** `GET /management` is served by `management()`, `GET /{person}` by `record()`; either may be omitted. */
  slug: string
  path: string
}

/**
 * The shell and a record's reads. `view` and `management` are functions so a test can change the answer between calls (a
 * relationship that moves under the operator). A read that is not given is NOT served, so any request for it fails the
 * test: that is how a test proves a read was never made.
 */
export function serveRecord(options: {
  types: Wire[]
  slug: string
  view?: () => Response
  management?: () => Response
  account?: CurrentAccount
}): Served {
  const api = serveRelationships(options.types, options.account ?? operatorWith())
  const path = `/api/v1/admin/relationships/${options.slug}/${PERSON}`
  if (options.view) api.on(`GET ${path}`, options.view)
  if (options.management) api.on(`GET ${path}/management`, options.management)
  return { api, slug: options.slug, path }
}

export const guardianBoth = (
  overrides: { view?: Wire; management?: Wire; type?: Wire; account?: CurrentAccount } = {},
): Served =>
  serveRecord({
    types: [wireGuardianType(overrides.type)],
    slug: 'guardians',
    view: () => json(wireRecord(overrides.view)),
    management: () => json(wireManagement(overrides.management)),
    ...(overrides.account && { account: overrides.account }),
  })

export const volunteerBoth = (
  overrides: { view?: Wire; management?: Wire; type?: Wire; account?: CurrentAccount } = {},
): Served =>
  serveRecord({
    types: [wireVolunteerType(overrides.type)],
    slug: 'volunteers',
    view: () => json(wireRecord({ type: 'volunteer', fields: {}, ...overrides.view })),
    management: () =>
      json(
        wireManagement({
          type: 'volunteer',
          transitions: ['active', 'inactive'],
          status: 'pending',
          fields: {},
          default_role: null,
          basics: { primary_email: 'ada@example.org', primary_phone: '+1 555 010 0100' },
          ...overrides.management,
        }),
      ),
    ...(overrides.account && { account: overrides.account }),
  })

/** The step-up prompt's proof form, filled in and submitted. */
export async function prove(user: User) {
  const prompt = await screen.findByRole('dialog', { name: 'Confirm it is you' })
  await user.type(within(prompt).getByLabelText('Current password'), 'the current password')
  await user.type(within(prompt).getByLabelText('Authentication code'), '123456')
  await user.click(within(prompt).getByRole('button', { name: 'Confirm' }))
}

export const verificationRefused = () =>
  json({ message: 'Recent security verification is required.', verification_required: true }, 403)

/** The dialog with this name. */
export const dialog = (name: string | RegExp) => screen.findByRole('dialog', { name })

/** The body of the nth call to a route, or a failure that says there was none. */
export function bodyOf(api: FakeApi, route: Parameters<FakeApi['callsTo']>[0], index = 0): unknown {
  const call = api.callsTo(route)[index]
  if (call === undefined) throw new Error(`There was no call ${String(index)} to ${route}.`)
  return call.body
}
