import { useCallback } from 'react'
import { Link, useParams } from 'react-router'

import { getAccount } from '../../api/admin.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { shown } from '../../admin/time.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import {
  ACCOUNTS_MANAGE,
  hasCapability,
  INVITATIONS_ISSUE,
  MFA_RECOVER,
  ROLES_ASSIGN,
} from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { PageHeading } from '../../ui/PageHeading.tsx'
import { describeFailure } from '../../ui/problem.ts'
import { StatusBadge } from '../../ui/StatusBadge.tsx'
import { AccessRolesSection } from './AccessRolesSection.tsx'
import { AccountStatusSection } from './AccountStatusSection.tsx'
import { InvitationSection } from './InvitationSection.tsx'
import { MfaRecoverySection } from './MfaRecoverySection.tsx'

/**
 * One Account, and what an operator may do to it. Each control appears only if the signed-in operator holds the capability
 * for it, which decides what to PRESENT: the server refuses anything else, and each change asks for a recent proof.
 */
export function AccountDetailPage() {
  const current = useCurrentAccount()
  const { id = '' } = useParams()
  const load = useCallback((signal: AbortSignal) => getAccount(id, signal), [id])
  const [loaded, replace] = useLoad(load)

  if (loaded.status === 'loading') {
    return (
      <p role="status" className="text-slate-600">
        Loading account…
      </p>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <div className="flex max-w-md flex-col gap-3">
        <PageHeading title="Account" />
        <Alert tone="error">{describeFailure(loaded.failure).message}</Alert>
        <Link to="/admin/accounts" className="text-sm underline">
          Back to accounts
        </Link>
      </div>
    )
  }

  const account = loaded.value
  const own = account.id === current.account.id

  return (
    <div className="flex max-w-2xl flex-col gap-8">
      <header className="flex flex-col gap-2">
        <Link to="/admin/accounts" className="text-sm underline">
          ← Accounts
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <PageHeading title={account.displayName} />
          <StatusBadge status={account.status} />
        </div>
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="text-slate-500">Email</dt>
          <dd className="break-all">{account.email}</dd>
          <dt className="text-slate-500">Email verified</dt>
          <dd>
            {account.emailVerifiedAt === null ? 'Not verified' : shown(account.emailVerifiedAt)}
          </dd>
          <dt className="text-slate-500">Added</dt>
          <dd>{shown(account.createdAt)}</dd>
          <dt className="text-slate-500">Last signed in</dt>
          <dd>{account.lastLoginAt === null ? 'Never' : shown(account.lastLoginAt)}</dd>
          {account.disabledAt === null ? null : (
            <>
              <dt className="text-slate-500">Disabled</dt>
              <dd>{shown(account.disabledAt)}</dd>
            </>
          )}
        </dl>
      </header>

      {account.status === 'invited' || account.invitation !== null ? (
        <InvitationSection
          account={account}
          mayIssue={hasCapability(current, INVITATIONS_ISSUE) && account.status === 'invited'}
          onChanged={replace}
        />
      ) : null}

      <AccessRolesSection
        account={account}
        mayAssign={hasCapability(current, ROLES_ASSIGN)}
        onChanged={replace}
      />

      <MfaRecoverySection
        account={account}
        own={own}
        mayRecover={hasCapability(current, MFA_RECOVER)}
        onChanged={replace}
      />

      {hasCapability(current, ACCOUNTS_MANAGE) ? (
        <AccountStatusSection account={account} own={own} onChanged={replace} />
      ) : null}
    </div>
  )
}
