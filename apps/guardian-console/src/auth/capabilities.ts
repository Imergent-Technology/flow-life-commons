// Capabilities are what the Console asks about; role names never appear here (ADR 0017). What `/me`
// returns is for PRESENTATION only: it decides what to show, never what is allowed. The server
// re-decides every request, so hiding a control is a courtesy and showing one grants nothing.

import type { CurrentAccount } from '../api/auth.ts'

export const CONSOLE_ACCESS = 'console.access'

// Operator administration. The server decides every request; these only choose what to show.
export const ACCOUNTS_VIEW = 'identity.accounts.view'
export const ACCOUNTS_MANAGE = 'identity.accounts.manage'
export const INVITATIONS_ISSUE = 'identity.invitations.issue'
export const MFA_RECOVER = 'identity.mfa.recover'
export const ROLES_ASSIGN = 'access.roles.assign'

// Membership records (ADR 0028). Changes nothing; membership state is always derived by the server.
export const MEMBERSHIP_VIEW = 'membership.records.view'
export const MEMBERSHIP_MANAGE = 'membership.records.manage'

export function hasCapability(current: CurrentAccount, capability: string): boolean {
  return current.capabilities.includes(capability)
}
