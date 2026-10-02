import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { listPeople } from '../../api/people.ts'
import { describePeopleFailure } from '../../admin/peopleWording.ts'
import { useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, PEOPLE_MANAGE } from '../../auth/capabilities.ts'
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
import { SkeletonRegion, SkeletonRows } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { useLoad } from '../../ui/useLoad.ts'

const PER_PAGE = 25

/**
 * The People directory (ADR 0034): EVERY Person the platform holds, in name order, whether or not anything has been
 * recorded about them. The server owns the order, the search and the paging; this asks for one page at a time. Search is
 * submitted, not run on every keystroke, and starting a new search returns to page 1. The emails and phones shown are the
 * ones recorded here as contact methods, never an Account's login.
 */
export function PeoplePage() {
  const current = useCurrentAccount()
  const [page, setPage] = useState(1)
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  // Bumped by "Try again": a new load function is a new request, so a failed one can be asked for again.
  const [attempt, setAttempt] = useState(0)

  const load = useCallback(
    (signal: AbortSignal) => listPeople({ page, perPage: PER_PAGE, query, signal }),
    // `attempt` is not read: a new load function is how "Try again" asks for the same page again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [page, query, attempt],
  )
  const [people] = useLoad(load)

  return (
    <Page width="wide">
      <PageHeader
        title="People"
        action={
          hasCapability(current, PEOPLE_MANAGE) ? (
            <Link to="/people/new" className={buttonVariants({ variant: 'primary' })}>
              Add person
            </Link>
          ) : undefined
        }
      />

      <form
        role="search"
        aria-label="Find people"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          setPage(1)
          setQuery(typed.trim())
        }}
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-full sm:w-80">
          <Field label="Name, email or phone">
            {(control) => (
              <Input
                {...control}
                type="search"
                value={typed}
                onChange={(event) => {
                  setTyped(event.target.value)
                }}
                maxLength={255}
                autoComplete="off"
              />
            )}
          </Field>
        </div>
        <Button type="submit" className="max-sm:w-full">
          Search
        </Button>
      </form>

      {people.status === 'loading' ? (
        <SkeletonRegion label="Loading people…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {people.status === 'failed' ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describePeopleFailure(people.failure).message}</Alert>
          <Button
            onClick={() => {
              setAttempt((n) => n + 1)
            }}
          >
            Try again
          </Button>
        </div>
      ) : null}
      {people.status === 'loaded' ? (
        <>
          {people.value.people.length === 0 ? (
            <EmptyState title={query === '' ? 'There are no people yet.' : 'No people match.'}>
              {query === ''
                ? 'Add the first person to start the directory.'
                : 'Try a different name, email address or phone number.'}
            </EmptyState>
          ) : (
            <DataTable caption="People">
              <DataTableHead>
                <tr>
                  <DataTableHeaderCell>Name</DataTableHeaderCell>
                  <DataTableHeaderCell>Primary email</DataTableHeaderCell>
                  <DataTableHeaderCell>Primary phone</DataTableHeaderCell>
                </tr>
              </DataTableHead>
              <DataTableBody>
                {people.value.people.map((person) => (
                  <DataTableRow key={person.id}>
                    <DataTableRowHeader>
                      <TextLink
                        to={`/people/${person.id}`}
                        className="text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                      >
                        {person.displayName}
                      </TextLink>
                    </DataTableRowHeader>
                    <DataTableCell label="Primary email" truncate>
                      {person.primaryEmail ?? '—'}
                    </DataTableCell>
                    <DataTableCell label="Primary phone">
                      {person.primaryPhone ?? '—'}
                    </DataTableCell>
                  </DataTableRow>
                ))}
              </DataTableBody>
            </DataTable>
          )}
          <Pagination
            page={people.value.page}
            lastPage={people.value.lastPage}
            total={people.value.total}
            noun={{ one: 'person', other: 'people' }}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </Page>
  )
}
