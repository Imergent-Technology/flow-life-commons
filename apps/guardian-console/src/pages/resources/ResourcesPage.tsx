import { useCallback, useState, type SyntheticEvent } from 'react'
import { Link } from 'react-router'

import {
  audienceLabel,
  cardTypeLabel,
  describeResourcesFailure,
  stateLabel,
} from '../../admin/resourcesWording.ts'
import { shown } from '../../admin/time.ts'
import {
  AUDIENCES,
  listCategories,
  listPacks,
  type Audience,
  type CardType,
  type PublicationState,
} from '../../api/resources.ts'
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
import { AudienceList, StateBadge } from './badges.tsx'
import { Notice } from './FeedbackAlert.tsx'
import { useNotice } from './useNotice.ts'

const PER_PAGE = 25
const CARD_TYPES: readonly CardType[] = ['basic', 'external_link', 'file']

/**
 * Resource management (ADR 0037): every Resource Pack, Drafts included, for the Guardians who author them. It is the editor's
 * view, not the library a Guardian reads: it shows what a Pack IS (its state, its audiences, how many of its Cards are
 * published), and who may see it is the server's projection, never worked out here. The server owns the order (Category, then
 * Pack order, uncategorised last), the filters and the paging; this asks for one page at a time and does not re-sort it. Search
 * is submitted rather than run on every keystroke and matches Pack titles only; a new search or filter returns to page 1.
 */
export function ResourcesPage() {
  const notice = useNotice()
  const [page, setPage] = useState(1)
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('')
  const [state, setState] = useState<PublicationState | ''>('')
  const [audience, setAudience] = useState<Audience | ''>('')
  const [cardType, setCardType] = useState<CardType | ''>('')
  // Bumped by "Try again": a new load function is a new request, so a failed one can be asked for again.
  const [attempt, setAttempt] = useState(0)

  const loadCategories = useCallback((signal: AbortSignal) => listCategories(signal), [])
  const [categories] = useLoad(loadCategories)

  const load = useCallback(
    (signal: AbortSignal) =>
      listPacks({
        page,
        perPage: PER_PAGE,
        ...(category !== '' && { category }),
        ...(state !== '' && { state }),
        ...(audience !== '' && { audience }),
        ...(cardType !== '' && { cardType }),
        ...(query !== '' && { query }),
        signal,
      }),
    // `attempt` is not read: a new load function is how "Try again" asks for the same page again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [page, category, state, audience, cardType, query, attempt],
  )
  const [packs] = useLoad(load)
  const filtered =
    query !== '' || category !== '' || state !== '' || audience !== '' || cardType !== ''

  return (
    <Page width="wide">
      <PageHeader
        title="Resources"
        description="The Resource Packs Guardians write and keep, Drafts included. Open one to edit its Cards, audiences and publication."
        action={
          <>
            <Link to="/resources/categories" className={buttonVariants({})}>
              Categories
            </Link>
            <Link to="/resources/new" className={buttonVariants({ variant: 'primary' })}>
              Add a Resource Pack
            </Link>
          </>
        }
      />

      <Notice text={notice} />

      <form
        role="search"
        aria-label="Find Resource Packs"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          setPage(1)
          setQuery(typed.trim())
        }}
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-full sm:w-72">
          <Field label="Search titles">
            {(control) => (
              <Input
                {...control}
                type="search"
                value={typed}
                maxLength={200}
                autoComplete="off"
                onChange={(event) => {
                  setTyped(event.target.value)
                }}
              />
            )}
          </Field>
        </div>
        <div className="w-full sm:w-48">
          <Field label="Category">
            {(control) => (
              <Select
                {...control}
                value={category}
                disabled={categories.status !== 'loaded'}
                onChange={(event) => {
                  setPage(1)
                  setCategory(event.target.value)
                }}
              >
                <option value="">All Categories</option>
                {categories.status === 'loaded'
                  ? categories.value.map((each) => (
                      <option key={each.id} value={each.id}>
                        {each.name}
                      </option>
                    ))
                  : null}
              </Select>
            )}
          </Field>
        </div>
        <div className="w-full sm:w-36">
          <Field label="State">
            {(control) => (
              <Select
                {...control}
                value={state}
                onChange={(event) => {
                  setPage(1)
                  setState(event.target.value as PublicationState | '')
                }}
              >
                <option value="">Any state</option>
                <option value="draft">{stateLabel('draft')}</option>
                <option value="published">{stateLabel('published')}</option>
              </Select>
            )}
          </Field>
        </div>
        <div className="w-full sm:w-40">
          <Field label="Audience">
            {(control) => (
              <Select
                {...control}
                value={audience}
                onChange={(event) => {
                  setPage(1)
                  setAudience(event.target.value as Audience | '')
                }}
              >
                <option value="">Any audience</option>
                {AUDIENCES.map((each) => (
                  <option key={each} value={each}>
                    {audienceLabel(each)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <div className="w-full sm:w-44">
          <Field label="Has a Card of type">
            {(control) => (
              <Select
                {...control}
                value={cardType}
                onChange={(event) => {
                  setPage(1)
                  setCardType(event.target.value as CardType | '')
                }}
              >
                <option value="">Any type</option>
                {CARD_TYPES.map((each) => (
                  <option key={each} value={each}>
                    {cardTypeLabel(each)}
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

      {packs.status === 'loading' ? (
        <SkeletonRegion label="Loading Resource Packs…" visibleLabel>
          <SkeletonRows />
        </SkeletonRegion>
      ) : null}
      {packs.status === 'failed' ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describeResourcesFailure(packs.failure).message}</Alert>
          <Button
            onClick={() => {
              setAttempt((n) => n + 1)
            }}
          >
            Try again
          </Button>
        </div>
      ) : null}
      {packs.status === 'loaded' ? (
        <>
          {packs.value.packs.length === 0 ? (
            <EmptyState
              title={filtered ? 'No Resource Packs match.' : 'There are no Resource Packs yet.'}
              action={
                !filtered ? (
                  <Link to="/resources/new" className={buttonVariants({ variant: 'primary' })}>
                    Add the first Resource Pack
                  </Link>
                ) : undefined
              }
            >
              {filtered
                ? 'Try different filters, or clear them to see every Pack.'
                : 'A Pack is a set of Cards aimed at an audience. Create a Category first if you have none, then add a Pack.'}
            </EmptyState>
          ) : (
            <DataTable caption="Resource Packs">
              <DataTableHead>
                <tr>
                  <DataTableHeaderCell>Pack</DataTableHeaderCell>
                  <DataTableHeaderCell>Category</DataTableHeaderCell>
                  <DataTableHeaderCell>State</DataTableHeaderCell>
                  <DataTableHeaderCell>Audiences</DataTableHeaderCell>
                  <DataTableHeaderCell>Cards</DataTableHeaderCell>
                  <DataTableHeaderCell>Last edited</DataTableHeaderCell>
                </tr>
              </DataTableHead>
              <DataTableBody>
                {packs.value.packs.map((pack) => (
                  <DataTableRow key={pack.id}>
                    <DataTableRowHeader>
                      <TextLink
                        to={`/resources/packs/${pack.id}`}
                        className="wrap-anywhere text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                      >
                        {pack.title}
                      </TextLink>
                    </DataTableRowHeader>
                    <DataTableCell label="Category">
                      <span className="wrap-anywhere">{pack.category?.name ?? 'No Category'}</span>
                    </DataTableCell>
                    <DataTableCell label="State">
                      <StateBadge state={pack.state} />
                    </DataTableCell>
                    <DataTableCell label="Audiences">
                      <AudienceList audiences={pack.audiences} />
                    </DataTableCell>
                    <DataTableCell label="Cards">
                      {pack.cardCount === 0
                        ? 'No Cards'
                        : `${String(pack.publishedCardCount)} of ${String(pack.cardCount)} published`}
                    </DataTableCell>
                    <DataTableCell label="Last edited">
                      <time dateTime={pack.updatedAt}>{shown(pack.updatedAt)}</time>
                    </DataTableCell>
                  </DataTableRow>
                ))}
              </DataTableBody>
            </DataTable>
          )}
          <Pagination
            page={packs.value.page}
            lastPage={packs.value.lastPage}
            total={packs.value.total}
            noun={{ one: 'Resource Pack', other: 'Resource Packs' }}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </Page>
  )
}
