import { useCallback } from 'react'
import { useParams } from 'react-router'

import { getAccount } from '../../api/admin.ts'
import { useLoad } from '../../ui/useLoad.ts'
import { shown } from '../../admin/time.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import {
  ACCOUNTS_MANAGE,
  hasCapability,
  INVITATIONS_ISSUE,
  MEMBERSHIP_MANAGE,
  MEMBERSHIP_VIEW,
  MFA_RECOVER,
  ROLES_ASSIGN,
} from '../../auth/capabilities.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { DetailLayout, Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { describeFailure } from '../../ui/problem.ts'
import { Property, PropertyList } from '../../ui/PropertyList.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { StatusBadge } from '../../ui/StatusBadge.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { AccessRolesSection } from './AccessRolesSection.tsx'
import { AccountMembershipSection } from './AccountMembershipSection.tsx'
import { AccountStatusSection } from './AccountStatusSection.tsx'
import { InvitationSection } from './InvitationSection.tsx'
import { MfaRecoverySection } from './MfaRecoverySection.tsx'
import { PasswordRecoverySection } from './PasswordRecoverySection.tsx'

/**
 * One Account, and what an operator may do to it. Each control appears only if the signed-in operator holds the capability
 * for it, which decides what to PRESENT: the server refuses anything else, and each change asks for a recent proof.
 */
export function AccountDetailPage() {
  const current = useCurrentAccount()
  const { id = '' } = useParams()
  const load = useCallback((signal: AbortSignal) => getAccount(id, signal), [id])
  const [loaded, replace] = useLoad(load)
  useBreadcrumbLeaf(loaded.status === 'loaded' ? loaded.value.displayName : undefined)

  if (loaded.status === 'loading') {
    return (
      <Page width="detail">
        <SkeletonRegion label="Loading account…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (loaded.status === 'failed') {
    return (
      <Page width="prose">
        <PageHeader title="Account" />
        <Alert tone="error">{describeFailure(loaded.failure).message}</Alert>
        <TextLink to="/admin/accounts" className="self-start">
          Back to accounts
        </TextLink>
      </Page>
    )
  }

  const account = loaded.value
  const own = account.id === current.account.id

  return (
    <Page width="detail">
      <PageHeader title={account.displayName} status={<StatusBadge status={account.status} />} />

      <DetailLayout
        aside={
          <Panel title="Details">
            <PropertyList>
              <Property term="Email">{account.email}</Property>
              <Property term="Email verified">
                {account.emailVerifiedAt === null ? 'Not verified' : shown(account.emailVerifiedAt)}
              </Property>
              <Property term="Added">{shown(account.createdAt)}</Property>
              <Property term="Last signed in">
                {account.lastLoginAt === null ? 'Never' : shown(account.lastLoginAt)}
              </Property>
              {account.disabledAt === null ? null : (
                <Property term="Disabled">{shown(account.disabledAt)}</Property>
              )}
            </PropertyList>
          </Panel>
        }
      >
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

        {hasCapability(current, ACCOUNTS_MANAGE) ? (
          <PasswordRecoverySection account={account} own={own} />
        ) : null}

        <MfaRecoverySection
          account={account}
          own={own}
          mayRecover={hasCapability(current, MFA_RECOVER)}
          onChanged={replace}
        />

        <AccountMembershipSection
          account={account}
          mayView={hasCapability(current, MEMBERSHIP_VIEW)}
          mayManage={hasCapability(current, MEMBERSHIP_MANAGE)}
        />

        {hasCapability(current, ACCOUNTS_MANAGE) ? (
          <AccountStatusSection account={account} own={own} onChanged={replace} />
        ) : null}
      </DetailLayout>
    </Page>
  )
}
