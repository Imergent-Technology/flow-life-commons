import { useCallback, useState } from 'react'
import { Link } from 'react-router'

import { listMembers } from '../../api/membership.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { accessThroughLabel } from '../../admin/wording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, MEMBERSHIP_MANAGE } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { secondaryButton } from '../../ui/classes.ts'
import { PageHeading } from '../../ui/PageHeading.tsx'
import { describeFailure } from '../../ui/problem.ts'
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
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <PageHeading title="Members" />
        {hasCapability(current, MEMBERSHIP_MANAGE) ? (
          <Link to="/admin/members/new" className={secondaryButton}>
            Add member
          </Link>
        ) : null}
      </div>

      {members.status === 'loading' ? (
        <p role="status" className="text-slate-600">
          Loading members…
        </p>
      ) : null}
      {members.status === 'failed' ? (
        <Alert tone="error">{describeFailure(members.failure).message}</Alert>
      ) : null}
      {members.status === 'loaded' ? (
        <>
          {members.value.members.length === 0 ? (
            <p role="status" className="text-slate-600">
              There are no membership records yet.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[32rem] border-collapse text-left text-sm">
                <caption className="sr-only">Members</caption>
                <thead>
                  <tr className="border-b border-slate-300 text-slate-600">
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Name
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      State
                    </th>
                    <th scope="col" className="py-2 font-medium">
                      Access
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {members.value.members.map((record) => (
                    <tr key={record.person.id} className="border-b border-slate-200 align-top">
                      <th scope="row" className="py-2 pr-4 font-medium">
                        <Link
                          to={`/admin/members/${record.person.id}`}
                          className="underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                        >
                          {record.person.displayName}
                        </Link>
                      </th>
                      <td className="py-2 pr-4">
                        <MembershipStateBadge active={record.active} />
                      </td>
                      <td className="py-2">{accessThroughLabel(record)}</td>
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
              disabled={members.value.page <= 1}
              onClick={() => {
                setPage(members.value.page - 1)
              }}
            >
              Previous
            </button>
            <span role="status">
              Page {members.value.page} of {members.value.lastPage} ({members.value.total}{' '}
              {members.value.total === 1 ? 'member' : 'members'})
            </span>
            <button
              type="button"
              className={secondaryButton}
              disabled={members.value.page >= members.value.lastPage}
              onClick={() => {
                setPage(members.value.page + 1)
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
