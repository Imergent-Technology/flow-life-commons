import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { listAccounts, type AccountStatus } from '../../api/admin.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, INVITATIONS_ISSUE } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { secondaryButton } from '../../ui/classes.ts'
import { PageHeading } from '../../ui/PageHeading.tsx'
import { describeFailure } from '../../ui/problem.ts'
import { StatusBadge } from '../../ui/StatusBadge.tsx'

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
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <PageHeading title="Accounts" />
        {hasCapability(current, INVITATIONS_ISSUE) ? (
          <Link to="/admin/accounts/invite" className={secondaryButton}>
            Invite an operator
          </Link>
        ) : null}
      </div>

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
        <div className="flex flex-col gap-1">
          <label htmlFor="account-search" className="text-sm font-medium text-slate-800">
            Name or email
          </label>
          <input
            id="account-search"
            type="search"
            value={typed}
            onChange={(event) => {
              setTyped(event.target.value)
            }}
            maxLength={100}
            autoComplete="off"
            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="account-status" className="text-sm font-medium text-slate-800">
            Status
          </label>
          <select
            id="account-status"
            value={status}
            onChange={(event) => {
              setPage(1)
              setStatus(event.target.value as AccountStatus | '')
            }}
            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900"
          >
            <option value="">Any</option>
            <option value="invited">Invited</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
          </select>
        </div>
        <button type="submit" className={secondaryButton}>
          Search
        </button>
      </form>

      {accounts.status === 'loading' ? (
        <p role="status" className="text-slate-600">
          Loading accounts…
        </p>
      ) : null}
      {accounts.status === 'failed' ? (
        <Alert tone="error">{describeFailure(accounts.failure).message}</Alert>
      ) : null}
      {accounts.status === 'loaded' ? (
        <>
          {accounts.value.accounts.length === 0 ? (
            <p role="status" className="text-slate-600">
              No accounts match.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[40rem] border-collapse text-left text-sm">
                <caption className="sr-only">Accounts</caption>
                <thead>
                  <tr className="border-b border-slate-300 text-slate-600">
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Name
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Email
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Status
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Two-step
                    </th>
                    <th scope="col" className="py-2 font-medium">
                      Access
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {accounts.value.accounts.map((account) => (
                    <tr key={account.id} className="border-b border-slate-200 align-top">
                      <th scope="row" className="py-2 pr-4 font-medium">
                        <Link
                          to={`/admin/accounts/${account.id}`}
                          className="underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                        >
                          {account.displayName}
                        </Link>
                        {account.id === current.account.id ? (
                          <span className="ml-2 text-xs font-normal text-slate-500">(you)</span>
                        ) : null}
                      </th>
                      <td className="py-2 pr-4 break-all">{account.email}</td>
                      <td className="py-2 pr-4">
                        <StatusBadge status={account.status} />
                      </td>
                      <td className="py-2 pr-4">{account.mfa.enrolled ? 'On' : 'Not set up'}</td>
                      <td className="py-2">
                        {account.assignments.length === 0
                          ? '—'
                          : account.assignments.map((a) => a.name).join(', ')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <nav aria-label="Pages" className="flex flex-wrap items-center gap-3 text-sm">
            <button
              type="button"
              className={secondaryButton}
              disabled={accounts.value.page <= 1}
              onClick={() => {
                setPage(accounts.value.page - 1)
              }}
            >
              Previous
            </button>
            <span role="status">
              Page {accounts.value.page} of {accounts.value.lastPage} ({accounts.value.total}{' '}
              {accounts.value.total === 1 ? 'account' : 'accounts'})
            </span>
            <button
              type="button"
              className={secondaryButton}
              disabled={accounts.value.page >= accounts.value.lastPage}
              onClick={() => {
                setPage(accounts.value.page + 1)
              }}
            >
              Next
            </button>
          </nav>
        </>
      ) : null}
    </div>
  )
}
