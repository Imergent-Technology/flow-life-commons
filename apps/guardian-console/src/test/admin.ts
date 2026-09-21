import type { CurrentAccount } from '../api/auth.ts'
import { accountFor, empty, FakeApi, json } from './fakeApi.ts'

/** Capabilities are identifiers; an operator with all of them is what the platform's administrator resolves to. */
export const ADMIN_CAPABILITIES = [
  'access.roles.assign',
  'console.access',
  'identity.accounts.manage',
  'identity.accounts.view',
  'identity.invitations.issue',
  'identity.mfa.recover',
]

export const OPERATOR_ID = '01J0000000000000000000ACCT'
export const TARGET_ID = '01J00000000000000000TARGET'

export function operator(capabilities: string[] = ADMIN_CAPABILITIES): CurrentAccount {
  return accountFor({ capabilities })
}

/** A wire account (snake_case, as the server sends it), with sensible defaults. */
export function wire(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: TARGET_ID,
    person_id: '01J000000000000000TARGETPRS',
    display_name: 'Tara Target',
    email: 'tara@example.org',
    email_verified_at: '2026-09-20T10:00:00Z',
    status: 'active',
    created_at: '2026-09-19T09:00:00Z',
    last_login_at: '2026-09-21T08:30:00Z',
    disabled_at: null,
    mfa: { enrolled: true, recovery_codes_remaining: 8 },
    invitation: null,
    assignments: [
      {
        key: 'custom_key_one',
        name: 'Sample Access One',
        description: 'Whatever the server says this is.',
        granted_at: '2026-09-19T09:05:00Z',
      },
    ],
    ...overrides,
  }
}

/** A catalog whose keys and words are NOT the platform's real ones: whatever the server offers is what is shown. */
export const CATALOG = {
  data: [
    {
      key: 'custom_key_one',
      name: 'Sample Access One',
      description: 'Whatever the server says this is.',
      capabilities: ['console.access'],
    },
    {
      key: 'custom_key_two',
      name: 'Sample Access Two',
      description: 'Something else the server offers.',
      capabilities: ['console.access', 'identity.accounts.view'],
    },
  ],
}

export function page(accounts: unknown[], meta: Record<string, number> = {}) {
  return {
    data: accounts,
    meta: { page: 1, per_page: 25, total: accounts.length, last_page: 1, ...meta },
  }
}

/** Serves the shell every signed-in Console page needs; the test adds the administration routes it cares about. */
export function serveOperator(account: CurrentAccount = operator()): FakeApi {
  const api = new FakeApi()
  api.on('GET /api/v1/me', () => json(account))
  api.on('GET /api/v1/health', json({ status: 'ok', service: 's', api_version: 'v1', checks: {} }))
  api.on('GET /api/v1/admin/roles', json(CATALOG))
  api.install()
  return api
}

export const verificationRequired = () =>
  json({ message: 'Recent security verification is required.', verification_required: true }, 403)

export { empty, json }
