import { useCallback, useState, type SyntheticEvent } from 'react'

import {
  browseLibrary,
  type LibraryCategory,
  type LibraryPackEntry,
} from '../../api/resourceLibrary.ts'
import type { CategoryRef } from '../../api/resources.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { EmptyState } from '../../ui/EmptyState.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { Select } from '../../ui/Select.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { describeLibraryFailure } from './wording.ts'

/**
 * The Resource Library (ADR 0037): what a Guardian may read, grouped by Category, as the platform's delivery projection answers it.
 * It is for reading, not authoring, and it is a different surface from Resource management: it asks only the library endpoint, so a
 * Draft, a Pack aimed at someone else, or a Card this person may not see is not here and is not hinted at. The server owns the order
 * (Categories, then Packs), the search (titles and summaries of what is visible, never content) and which Categories are listed; this
 * shows them as sent. The library is not paged. Search is submitted rather than run on every keystroke; a Category filter applies
 * at once.
 */
export function LibraryPage() {
  const [typed, setTyped] = useState('')
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('')
  // The Categories a person can filter to: those the UNFILTERED library listed. A filtered answer lists fewer, so it never replaces them.
  const [catalog, setCatalog] = useState<CategoryRef[]>([])
  // Bumped by "Try again": a new load function is a new request, so a failed one can be asked for again.
  const [attempt, setAttempt] = useState(0)

  const load = useCallback(
    async (signal: AbortSignal) => {
      const result = await browseLibrary({
        ...(category !== '' && { category }),
        ...(query !== '' && { query }),
        signal,
      })
      if (result.ok && category === '' && query === '' && !signal.aborted) {
        setCatalog(result.value.map((group) => group.category))
      }
      return result
    },
    // `attempt` is not read: a new load function is how "Try again" asks for the same thing again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [category, query, attempt],
  )
  const [library] = useLoad(load)
  const filtered = category !== '' || query !== ''

  return (
    <Page width="wide">
      <PageHeader
        title="Resource Library"
        description="Guides, links and files for Guardians, by Category."
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
        <Button type="submit" className="max-sm:w-full">
          Search
        </Button>
      </form>

      {library.status === 'loading' ? (
        <SkeletonRegion label="Loading Resources…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      ) : null}
      {library.status === 'failed' ? (
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
            title={filtered ? 'No Resources match.' : 'There are no Resources for you yet.'}
            action={
              filtered ? (
                <Button
                  onClick={() => {
                    setTyped('')
                    setQuery('')
                    setCategory('')
                  }}
                >
                  Clear search and filter
                </Button>
              ) : undefined
            }
          >
            {filtered
              ? 'Try different words, or another Category.'
              : 'When Resources are published for Guardians they will appear here.'}
          </EmptyState>
        ) : (
          <div className="flex flex-col gap-8">
            {library.value.map((group) => (
              <CategoryGroup key={group.category.id} group={group} />
            ))}
          </div>
        )
      ) : null}
    </Page>
  )
}

function CategoryGroup({ group }: { group: LibraryCategory }) {
  const headingId = `category-${group.category.id}`
  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-3">
      <h2
        id={headingId}
        className="font-display text-section font-medium wrap-anywhere text-foreground"
      >
        {group.category.name}
      </h2>
      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {group.packs.map((pack) => (
          <PackEntry key={pack.id} pack={pack} />
        ))}
      </ul>
    </section>
  )
}

/** Series and size say how a Pack is read; a single Card needs neither. The count is of what THIS viewer can see. */
function packNote(pack: LibraryPackEntry): string | null {
  const cards = pack.cardCount > 1 ? `${String(pack.cardCount)} Cards` : null
  if (pack.isSeries && cards !== null) return `Series · ${cards}`
  return cards
}

function PackEntry({ pack }: { pack: LibraryPackEntry }) {
  const note = packNote(pack)
  return (
    <li className="relative flex flex-col gap-1.5 rounded-lg border border-border bg-surface p-4 hover:bg-muted/50">
      <TextLink
        to={`/resource-library/${encodeURIComponent(pack.id)}`}
        className="font-medium wrap-anywhere text-foreground decoration-border-strong after:absolute after:inset-0 after:content-[''] hover:text-foreground hover:decoration-current"
      >
        {pack.title}
      </TextLink>
      {pack.summary !== null && pack.summary !== '' ? (
        <p className="text-body wrap-anywhere text-muted-foreground">{pack.summary}</p>
      ) : null}
      {note !== null ? <p className="text-meta text-muted-foreground">{note}</p> : null}
    </li>
  )
}
