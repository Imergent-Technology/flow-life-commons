import { useCallback, useState } from 'react'
import { Link } from 'react-router'

import { listMembers } from '../../api/membership.ts'
import { useLoad } from '../../ui/useLoad.ts'
import { accessThroughLabel } from '../../admin/wording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, MEMBERSHIP_MANAGE } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import {
  DataTable,
  DataTableBody,
  DataTableCell,
  DataTableHead,
  DataTableHeaderCell,
  DataTableRow,
  DataTableRowHeader,
} from '../../ui/DataTable.tsx'
import { EmptyState } from '../../ui/EmptyState.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Pagination } from '../../ui/Pagination.tsx'
import { describeFailure } from '../../ui/problem.ts'
import { SkeletonRegion, SkeletonRows } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { MembershipStateBadge } from './MembershipStateBadge.tsx'

const PER_PAGE = 25

/**
 * Every Person who has ever held a membership grant, a page at a time (ADR 0028): active, expired, future-only and
 * fully-revoked records alike. One row per Person, however many grants they hold. There is no search yet, and no
 * "member since": membership is derived, not a history of enrollment.
 */
export function MembersPage() {
  const current = useCurrentAccount()
  const [page, setPage] = useState(1)

  const load = useCallback(
    (signal: AbortSignal) => listMembers({ page, perPage: PER_PAGE, signal }),
    [page],
  )
  const [members] = useLoad(load)

  return (
    <Page width="wide">
      <PageHeader
        title="Members"
        action={
          hasCapability(current, MEMBERSHIP_MANAGE) ? (
            <Link to="/admin/members/new" className={buttonVariants({ variant: 'primary' })}>
              Add member
            </Link>
          ) : undefined
        }
      />

      {members.status === 'loading' ? (
        <SkeletonRegion label="Loading members…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {members.status === 'failed' ? (
        <Alert tone="error">{describeFailure(members.failure).message}</Alert>
      ) : null}
      {members.status === 'loaded' ? (
        <>
          {members.value.members.length === 0 ? (
            <EmptyState title="There are no membership records yet." />
          ) : (
            <DataTable caption="Members">
              <DataTableHead>
                <tr>
                  <DataTableHeaderCell>Name</DataTableHeaderCell>
                  <DataTableHeaderCell>State</DataTableHeaderCell>
                  <DataTableHeaderCell>Access</DataTableHeaderCell>
                </tr>
              </DataTableHead>
              <DataTableBody>
                {members.value.members.map((record) => (
                  <DataTableRow key={record.person.id}>
                    <DataTableRowHeader>
                      <TextLink
                        to={`/admin/members/${record.person.id}`}
                        className="text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                      >
                        {record.person.displayName}
                      </TextLink>
                    </DataTableRowHeader>
                    <DataTableCell label="State">
                      <MembershipStateBadge active={record.active} />
                    </DataTableCell>
                    <DataTableCell label="Access">{accessThroughLabel(record)}</DataTableCell>
                  </DataTableRow>
                ))}
              </DataTableBody>
            </DataTable>
          )}
          <Pagination
            page={members.value.page}
            lastPage={members.value.lastPage}
            total={members.value.total}
            noun={{ one: 'member', other: 'members' }}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </Page>
  )
}
