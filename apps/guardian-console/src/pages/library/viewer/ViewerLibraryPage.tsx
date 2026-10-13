import { useCallback, useState, type SyntheticEvent } from 'react'

import { audienceLabel } from '../../../admin/resourcesWording.ts'
import { AUDIENCES, type Audience } from '../../../api/audiences.ts'
import {
  browseViewerLibrary,
  type ViewerCategory,
  type ViewerPackEntry,
  type ViewerPublicationFilter,
} from '../../../api/resourceLibrary.ts'
import type { CategoryRef } from '../../../api/resources.ts'
import { Alert } from '../../../ui/Alert.tsx'
import { Button } from '../../../ui/Button.tsx'
import { EmptyState } from '../../../ui/EmptyState.tsx'
import { Field } from '../../../ui/Field.tsx'
import { Input } from '../../../ui/Input.tsx'
import { Page } from '../../../ui/Page.tsx'
import { PageHeader } from '../../../ui/PageHeader.tsx'
import { Select } from '../../../ui/Select.tsx'
import { SkeletonRegion, SkeletonText } from '../../../ui/Skeleton.tsx'
import { TextLink } from '../../../ui/TextLink.tsx'
import { useLoad } from '../../../ui/useLoad.ts'
import { useReportForbidden } from '../libraryMode.ts'
import { describeLibraryFailure } from '../wording.ts'
import { PublicationBadge } from './ViewerMarkers.tsx'
import { cardCounts, editedBy, forAudiences } from './viewerWording.ts'

const UNCATEGORIZED = 'Uncategorized'

/**
 * The Resource Library for someone who holds `resources.view` (ADR 0038, C7, R4-R9; policy A): every Pack and Card the platform has,
 * Drafts and every audience included, grouped by Category with the Packs that have none last. It is for reading, not authoring:
 * nothing here edits, publishes, reorders or deletes, and the management API is never asked.
 *
 * What the platform sends beyond the audience reader's answer is shown as what it is, the way the Resource was WRITTEN: a Draft is
 * marked as one, and a Pack says whom it is aimed at, how many Cards it has and how many of those are Drafts, and who last edited it.
 * None of it describes the person reading. The server owns the order, the search (titles and summaries of a Pack and all its Cards,
 * Drafts too) and every filter; this shows them as sent and works none out. Search is submitted rather than run on every keystroke;
 * the other filters apply at once. The library is not paged.
 */
export function ViewerLibraryPage() {
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('')
  const [publication, setPublication] = useState<ViewerPublicationFilter | ''>('')
  const [audience, setAudience] = useState<Audience | ''>('')
  // The Categories a person can filter to: those the UNFILTERED library listed. A filtered answer lists fewer, so it never replaces them.
  const [catalog, setCatalog] = useState<CategoryRef[]>([])
  // Bumped by "Try again": a new load function is a new request, so a failed one can be asked for again.
  const [attempt, setAttempt] = useState(0)

  const load = useCallback(
    async (signal: AbortSignal) => {
      const result = await browseViewerLibrary({
        ...(category !== '' && { category }),
        ...(query !== '' && { query }),
        ...(publication !== '' && { publication }),
        ...(audience !== '' && { audience }),
        signal,
      })
      if (
        result.ok &&
        category === '' &&
        query === '' &&
        publication === '' &&
        audience === '' &&
        !signal.aborted
      ) {
        setCatalog(
          result.value.flatMap((group) => (group.category === null ? [] : [group.category])),
        )
      }
      return result
    },
    // `attempt` is not read: a new load function is how "Try again" asks for the same thing again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [category, query, publication, audience, attempt],
  )
  const [library] = useLoad(load)
  // A 403 is the platform saying `resources.view` is gone: the route takes this page away and decides what follows. Until it does, and
  // from the moment of the refusal, nothing of the viewer is drawn.
  const refused = useReportForbidden(library.status === 'failed' ? library.failure : null, 'viewer')
  const filtered = category !== '' || query !== '' || publication !== '' || audience !== ''

  return (
    <Page width="wide">
      <PageHeader
        title="Resource Library"
        description="Every Pack, Draft or Published, whoever it is aimed at. For reading only."
      />

      <form
        role="search"
        aria-label="Find Resources"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          setQuery(typed.trim())
        }}
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-full sm:w-72">
          <Field label="Search Resources">
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
        <div className="w-full sm:w-56">
          <Field label="Category">
            {(control) => (
              <Select
                {...control}
                value={category}
                disabled={catalog.length === 0 && category === ''}
                onChange={(event) => {
                  setCategory(event.target.value)
                }}
              >
                <option value="">All Categories</option>
                {catalog.map((each) => (
                  <option key={each.id} value={each.id}>
                    {each.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <div className="w-full sm:w-44">
          <Field label="Publication">
            {(control) => (
              <Select
                {...control}
                value={publication}
                onChange={(event) => {
                  setPublication(event.target.value as ViewerPublicationFilter | '')
                }}
              >
                <option value="">All</option>
                <option value="published">Published</option>
                <option value="draft">Drafts</option>
              </Select>
            )}
          </Field>
        </div>
        <div className="w-full sm:w-44">
          <Field label="Audience">
            {(control) => (
              <Select
                {...control}
                value={audience}
                onChange={(event) => {
                  setAudience(event.target.value as Audience | '')
                }}
              >
                <option value="">All audiences</option>
                {AUDIENCES.map((each) => (
                  <option key={each} value={each}>
                    {audienceLabel(each)}
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
      <p className="text-meta text-muted-foreground">
        The labels say how each Pack was written: whether it is a Draft, and whom it is aimed at.
        You can read all of it, whoever it is aimed at. The Draft filter also finds Published Packs
        that hold a Draft Card.
      </p>

      {library.status === 'loading' || refused ? (
        <SkeletonRegion label="Loading Resources…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      ) : null}
      {library.status === 'failed' && !refused ? (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">{describeLibraryFailure(library.failure).message}</Alert>
          <Button
            onClick={() => {
              setAttempt((n) => n + 1)
            }}
          >
            Try again
          </Button>
        </div>
      ) : null}
      {library.status === 'loaded' ? (
        library.value.length === 0 ? (
          <EmptyState
            title={filtered ? 'No Resources match.' : 'There are no Resources yet.'}
            action={
              filtered ? (
                <Button
                  onClick={() => {
                    setTyped('')
                    setQuery('')
                    setCategory('')
                    setPublication('')
                    setAudience('')
                  }}
                >
                  Clear search and filters
                </Button>
              ) : undefined
            }
          >
            {filtered ? 'Try different words, or other filters.' : 'Nothing has been written yet.'}
          </EmptyState>
        ) : (
          <div className="flex flex-col gap-8">
            {library.value.map((group) => (
              <CategoryGroup key={group.category?.id ?? 'uncategorized'} group={group} />
            ))}
          </div>
        )
      ) : null}
    </Page>
  )
}

function CategoryGroup({ group }: { group: ViewerCategory }) {
  const headingId = `category-${group.category?.id ?? 'uncategorized'}`
  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-3">
      <h2
        id={headingId}
        className="font-display text-section font-medium wrap-anywhere text-foreground"
      >
        {group.category?.name ?? UNCATEGORIZED}
      </h2>
      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {group.packs.map((pack) => (
          <PackEntry key={pack.id} pack={pack} />
        ))}
      </ul>
    </section>
  )
}

function PackEntry({ pack }: { pack: ViewerPackEntry }) {
  return (
    <li className="relative flex flex-col gap-1.5 rounded-lg border border-border bg-surface p-4 hover:bg-muted/50">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <TextLink
          to={`/resource-library/${encodeURIComponent(pack.id)}`}
          className="font-medium wrap-anywhere text-foreground decoration-border-strong after:absolute after:inset-0 after:content-[''] hover:text-foreground hover:decoration-current"
        >
          {pack.title}
        </TextLink>
        <PublicationBadge state={pack.state} />
      </div>
      {pack.summary !== null && pack.summary !== '' ? (
        <p className="text-body wrap-anywhere text-muted-foreground">{pack.summary}</p>
      ) : null}
      <p className="text-meta wrap-anywhere text-muted-foreground">
        {forAudiences(pack.audiences)}
        {' · '}
        {pack.isSeries ? 'Series · ' : ''}
        {cardCounts(pack.cardCount, pack.draftCardCount)}
      </p>
      <p className="text-meta wrap-anywhere text-muted-foreground">
        {editedBy(pack.editor, pack.updatedAt)}
      </p>
    </li>
  )
}
