import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { describeDiscussionsFailure, personName } from '../../admin/discussionsWording.ts'
import { shown } from '../../admin/time.ts'
import { listDiscussions } from '../../api/discussions.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { DISCUSSIONS_PARTICIPATE, hasCapability } from '../../auth/capabilities.ts'
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
import { Select } from '../../ui/Select.tsx'
import { SkeletonRegion, SkeletonRows } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { StateBadge } from './StateBadge.tsx'

const PER_PAGE = 25

type StateFilter = '' | 'open' | 'resolved'

/**
 * Guardian Discussions (ADR 0035): the topics Guardians keep, most recently active first. The server owns the order (last
 * activity, then id), the filter, the search and the paging, and this asks for one page at a time without re-sorting it.
 * "Activity" is a message being POSTED: an edit, a removal, a new title, resolving and reopening do not move a discussion.
 * Search is submitted, not run on every keystroke, matches TITLES only (never message text), and a new search or filter
 * returns to page 1. Everyone who may view discussions sees every discussion: there are no private threads.
 */
export function DiscussionsPage() {
  const current = useCurrentAccount()
  const [page, setPage] = useState(1)
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const [state, setState] = useState<StateFilter>('')
  // Bumped by "Try again": a new load function is a new request, so a failed one can be asked for again.
  const [attempt, setAttempt] = useState(0)

  const load = useCallback(
    (signal: AbortSignal) =>
      listDiscussions({
        page,
        perPage: PER_PAGE,
        query,
        ...(state !== '' && { state }),
        signal,
      }),
    // `attempt` is not read: a new load function is how "Try again" asks for the same page again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [page, query, state, attempt],
  )
  const [discussions] = useLoad(load)
  const mayParticipate = hasCapability(current, DISCUSSIONS_PARTICIPATE)
  const filtered = query !== '' || state !== ''

  return (
    <Page width="wide">
      <PageHeader
        title="Discussions"
        description="Questions, decisions and follow-up that Guardians want to keep, in one place."
        action={
          mayParticipate ? (
            <Link to="/discussions/new" className={buttonVariants({ variant: 'primary' })}>
              Start a discussion
            </Link>
          ) : undefined
        }
      />

      <form
        role="search"
        aria-label="Find discussions"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          setPage(1)
          setQuery(typed.trim())
        }}
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-full sm:w-80">
          <Field label="Search titles">
            {(control) => (
              <Input
                {...control}
                type="search"
                value={typed}
                onChange={(event) => {
                  setTyped(event.target.value)
                }}
                maxLength={200}
                autoComplete="off"
              />
            )}
          </Field>
        </div>
        <div className="w-full sm:w-44">
          <Field label="Show">
            {(control) => (
              <Select
                {...control}
                value={state}
                onChange={(event) => {
                  setPage(1)
                  setState(event.target.value as StateFilter)
                }}
              >
                <option value="">All discussions</option>
                <option value="open">Open</option>
                <option value="resolved">Resolved</option>
              </Select>
            )}
          </Field>
        </div>
        <Button type="submit" className="max-sm:w-full">
          Search
        </Button>
      </form>

      {discussions.status === 'loading' ? (
        <SkeletonRegion label="Loading discussions…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {discussions.status === 'failed' ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describeDiscussionsFailure(discussions.failure).message}</Alert>
          <Button
            onClick={() => {
              setAttempt((n) => n + 1)
            }}
          >
            Try again
          </Button>
        </div>
      ) : null}
      {discussions.status === 'loaded' ? (
        <>
          {discussions.value.discussions.length === 0 ? (
            <EmptyState
              title={filtered ? 'No discussions match.' : 'There are no discussions yet.'}
              action={
                !filtered && mayParticipate ? (
                  <Link to="/discussions/new" className={buttonVariants({ variant: 'primary' })}>
                    Start the first discussion
                  </Link>
                ) : undefined
              }
            >
              {filtered
                ? 'Try a different title, or show all discussions.'
                : mayParticipate
                  ? 'Start one to keep a question or a decision where everyone can find it.'
                  : undefined}
            </EmptyState>
          ) : (
            <DataTable caption="Discussions">
              <DataTableHead>
                <tr>
                  <DataTableHeaderCell>Title</DataTableHeaderCell>
                  <DataTableHeaderCell>State</DataTableHeaderCell>
                  <DataTableHeaderCell>Started by</DataTableHeaderCell>
                  <DataTableHeaderCell>Messages</DataTableHeaderCell>
                  <DataTableHeaderCell>Last activity</DataTableHeaderCell>
                </tr>
              </DataTableHead>
              <DataTableBody>
                {discussions.value.discussions.map((discussion) => (
                  <DataTableRow key={discussion.id}>
                    <DataTableRowHeader>
                      <TextLink
                        to={`/discussions/${discussion.id}`}
                        className="wrap-anywhere text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                      >
                        {discussion.title}
                      </TextLink>
                    </DataTableRowHeader>
                    <DataTableCell label="State">
                      <StateBadge state={discussion.state} />
                    </DataTableCell>
                    <DataTableCell label="Started by">
                      {personName(discussion.creator)}
                    </DataTableCell>
                    <DataTableCell label="Messages">{discussion.messageCount}</DataTableCell>
                    <DataTableCell label="Last activity">
                      <time dateTime={discussion.lastActivityAt}>
                        {shown(discussion.lastActivityAt)}
                      </time>
                    </DataTableCell>
                  </DataTableRow>
                ))}
              </DataTableBody>
            </DataTable>
          )}
          <Pagination
            page={discussions.value.page}
            lastPage={discussions.value.lastPage}
            total={discussions.value.total}
            noun={{ one: 'discussion', other: 'discussions' }}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </Page>
  )
}
