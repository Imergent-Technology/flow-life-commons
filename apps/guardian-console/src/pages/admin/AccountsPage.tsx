import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { listAccounts, type AccountStatus } from '../../api/admin.ts'
import { useLoad } from '../../ui/useLoad.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, INVITATIONS_ISSUE } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
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
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Pagination } from '../../ui/Pagination.tsx'
import { describeFailure } from '../../ui/problem.ts'
import { Select } from '../../ui/Select.tsx'
import { SkeletonRegion, SkeletonRows } from '../../ui/Skeleton.tsx'
import { StatusBadge } from '../../ui/StatusBadge.tsx'
import { TextLink } from '../../ui/TextLink.tsx'

const PER_PAGE = 25

/**
 * The operators and other people with an Account: who they are, what state they are in, and what access they hold, a page
 * at a time. Read-only apart from the link to invite someone. Nothing here is a contact record.
 */
export function AccountsPage() {
  const current = useCurrentAccount()
  const [page, setPage] = useState(1)
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<AccountStatus | ''>('')

  const load = useCallback(
    (signal: AbortSignal) => listAccounts({ page, perPage: PER_PAGE, query, status, signal }),
    [page, query, status],
  )
  const [accounts] = useLoad(load)

  return (
    <Page width="wide">
      <PageHeader
        title="Accounts"
        action={
          hasCapability(current, INVITATIONS_ISSUE) ? (
            <Link to="/admin/accounts/invite" className={buttonVariants({ variant: 'primary' })}>
              Invite an operator
            </Link>
          ) : undefined
        }
      />

      <form
        role="search"
        aria-label="Find accounts"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          setPage(1)
          setQuery(typed)
        }}
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-full sm:w-72">
          <Field label="Name or email">
            {(control) => (
              <Input
                {...control}
                type="search"
                value={typed}
                onChange={(event) => {
                  setTyped(event.target.value)
                }}
                maxLength={100}
                autoComplete="off"
              />
            )}
          </Field>
        </div>
        <div className="w-full sm:w-44">
          <Field label="Status">
            {(control) => (
              <Select
                {...control}
                value={status}
                onChange={(event) => {
                  setPage(1)
                  setStatus(event.target.value as AccountStatus | '')
                }}
              >
                <option value="">Any</option>
                <option value="invited">Invited</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
              </Select>
            )}
          </Field>
        </div>
        <Button type="submit" className="max-sm:w-full">
          Search
        </Button>
      </form>

      {accounts.status === 'loading' ? (
        <SkeletonRegion label="Loading accounts…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {accounts.status === 'failed' ? (
        <Alert tone="error">{describeFailure(accounts.failure).message}</Alert>
      ) : null}
      {accounts.status === 'loaded' ? (
        <>
          {accounts.value.accounts.length === 0 ? (
            <EmptyState title="No accounts match.">
              Try a different name, email address or status.
            </EmptyState>
          ) : (
            <DataTable caption="Accounts">
              <DataTableHead>
                <tr>
                  <DataTableHeaderCell>Name</DataTableHeaderCell>
                  <DataTableHeaderCell className="w-[34%]">Email</DataTableHeaderCell>
                  <DataTableHeaderCell>Status</DataTableHeaderCell>
                  <DataTableHeaderCell>Two-step</DataTableHeaderCell>
                  <DataTableHeaderCell>Access</DataTableHeaderCell>
                </tr>
              </DataTableHead>
              <DataTableBody>
                {accounts.value.accounts.map((account) => (
                  <DataTableRow key={account.id}>
                    <DataTableRowHeader>
                      <TextLink
                        to={`/admin/accounts/${account.id}`}
                        className="text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                      >
                        {account.displayName}
                      </TextLink>
                      {account.id === current.account.id ? (
                        <span className="ml-2 text-meta font-normal text-muted-foreground">
                          (you)
                        </span>
                      ) : null}
                    </DataTableRowHeader>
                    <DataTableCell label="Email" truncate>
                      {account.email}
                    </DataTableCell>
                    <DataTableCell label="Status">
                      <StatusBadge status={account.status} />
                    </DataTableCell>
                    <DataTableCell label="Two-step">
                      {account.mfa.enrolled ? 'On' : 'Not set up'}
                    </DataTableCell>
                    <DataTableCell label="Access">
                      {account.assignments.length === 0
                        ? '—'
                        : account.assignments.map((a) => a.name).join(', ')}
                    </DataTableCell>
                  </DataTableRow>
                ))}
              </DataTableBody>
            </DataTable>
          )}
          <Pagination
            page={accounts.value.page}
            lastPage={accounts.value.lastPage}
            total={accounts.value.total}
            noun={{ one: 'account', other: 'accounts' }}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </Page>
  )
}
