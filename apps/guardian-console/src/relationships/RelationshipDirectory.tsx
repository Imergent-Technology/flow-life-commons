import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import { listDirectory, type RelationshipType } from '../api/relationships.ts'
import { shown } from '../admin/time.ts'
import { Alert } from '../ui/Alert.tsx'
import { buttonVariants } from '../ui/button-variants.ts'
import { Button } from '../ui/Button.tsx'
import {
  DataTable,
  DataTableBody,
  DataTableCell,
  DataTableHead,
  DataTableHeaderCell,
  DataTableRow,
  DataTableRowHeader,
} from '../ui/DataTable.tsx'
import { EmptyState } from '../ui/EmptyState.tsx'
import { Field } from '../ui/Field.tsx'
import { Input } from '../ui/Input.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Pagination } from '../ui/Pagination.tsx'
import { Select } from '../ui/Select.tsx'
import { SkeletonRegion, SkeletonRows } from '../ui/Skeleton.tsx'
import { TextLink } from '../ui/TextLink.tsx'
import { useLoad } from '../ui/useLoad.ts'
import { hasFeature, personName } from './definitions.ts'
import { intakeLabel } from '../shell/relationshipNavigation.ts'
import { RelationshipStatusBadge } from './RelationshipStatusBadge.tsx'
import { describeRelationshipFailure } from './wording.ts'

const PER_PAGE = 25

/**
 * The directory of ONE relationship type (ADR 0038, C3), for an operator who may view it. It follows the People directory's
 * conventions: the server pages (25 at a time) and searches, a search is submitted rather than run on every key, and a new
 * search or filter returns to page 1. The statuses to filter by are the definition's; the email and phone columns exist only
 * where the definition lets this type read basic details. Each row shows its own type's relationship and nothing of any
 * other, and this page never asks for the broader People directory.
 */
export function RelationshipDirectory({
  type,
  notice,
}: {
  type: RelationshipType
  notice?: string | null
}) {
  const [page, setPage] = useState(1)
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('')
  const [attempt, setAttempt] = useState(0)
  const readsBasics = hasFeature(type, 'people.read_basics')

  const load = useCallback(
    (signal: AbortSignal) =>
      listDirectory({ slug: type.slug, page, perPage: PER_PAGE, query, status, signal }),
    // `attempt` is not read: a new load function is how "Try again" asks for the same page again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [type.slug, page, query, status, attempt],
  )
  const [directory] = useLoad(load)
  const filtered = query !== '' || status !== ''

  return (
    <Page width="wide">
      <PageHeader
        title={type.labels.plural}
        description={type.labels.description}
        action={
          type.actions.manage ? (
            <Link
              to={`/relationships/${type.slug}/new`}
              className={buttonVariants({ variant: 'primary' })}
            >
              {intakeLabel(type)}
            </Link>
          ) : undefined
        }
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}

      <form
        role="search"
        aria-label={`Find ${type.labels.plural.toLowerCase()}`}
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          setPage(1)
          setQuery(typed.trim())
        }}
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-full sm:w-80">
          <Field label={readsBasics ? 'Name, email or phone' : 'Name'}>
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
        <div className="w-full sm:w-52">
          <Field label="Status">
            {(control) => (
              <Select
                {...control}
                value={status}
                onChange={(event) => {
                  setPage(1)
                  setStatus(event.target.value)
                }}
              >
                <option value="">All statuses</option>
                {type.states.map((state) => (
                  <option key={state.key} value={state.key}>
                    {state.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <Button type="submit" className="max-sm:w-full">
          Search
        </Button>
      </form>

      {directory.status === 'loading' ? (
        <SkeletonRegion label={`Loading ${type.labels.plural.toLowerCase()}…`} visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {directory.status === 'failed' ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describeRelationshipFailure(directory.failure, type).message}</Alert>
          <Button
            onClick={() => {
              setAttempt((n) => n + 1)
            }}
          >
            Try again
          </Button>
        </div>
      ) : null}
      {directory.status === 'loaded' ? (
        <>
          {directory.value.entries.length === 0 ? (
            <EmptyState
              title={
                filtered
                  ? `No ${type.labels.plural.toLowerCase()} match.`
                  : `There are no ${type.labels.plural.toLowerCase()} yet.`
              }
              action={
                !filtered && type.actions.manage ? (
                  <Link
                    to={`/relationships/${type.slug}/new`}
                    className={buttonVariants({ variant: 'secondary' })}
                  >
                    {intakeLabel(type)}
                  </Link>
                ) : undefined
              }
            >
              {filtered
                ? 'Try a different search, or another status.'
                : 'Nobody has been recorded yet.'}
            </EmptyState>
          ) : (
            <DataTable caption={type.labels.plural}>
              <DataTableHead>
                <tr>
                  <DataTableHeaderCell>Name</DataTableHeaderCell>
                  <DataTableHeaderCell>Status</DataTableHeaderCell>
                  <DataTableHeaderCell>Status since</DataTableHeaderCell>
                  {readsBasics ? (
                    <>
                      <DataTableHeaderCell>Primary email</DataTableHeaderCell>
                      <DataTableHeaderCell>Primary phone</DataTableHeaderCell>
                    </>
                  ) : null}
                </tr>
              </DataTableHead>
              <DataTableBody>
                {directory.value.entries.map((entry) => (
                  <DataTableRow key={entry.relationshipId}>
                    <DataTableRowHeader>
                      <TextLink
                        to={`/relationships/${type.slug}/${entry.person.id}`}
                        className="text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                      >
                        {personName(entry.person)}
                      </TextLink>
                    </DataTableRowHeader>
                    <DataTableCell label="Status">
                      <RelationshipStatusBadge type={type} status={entry.status} />
                    </DataTableCell>
                    <DataTableCell label="Status since">
                      {shown(entry.statusChangedAt)}
                    </DataTableCell>
                    {readsBasics ? (
                      <>
                        <DataTableCell label="Primary email" truncate>
                          {entry.basics?.primaryEmail ?? '—'}
                        </DataTableCell>
                        <DataTableCell label="Primary phone">
                          {entry.basics?.primaryPhone ?? '—'}
                        </DataTableCell>
                      </>
                    ) : null}
                  </DataTableRow>
                ))}
              </DataTableBody>
            </DataTable>
          )}
          <Pagination
            page={directory.value.page}
            lastPage={directory.value.lastPage}
            total={directory.value.total}
            noun={{
              one: type.labels.singular.toLowerCase(),
              other: type.labels.plural.toLowerCase(),
            }}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </Page>
  )
}
