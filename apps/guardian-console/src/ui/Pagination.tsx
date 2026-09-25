import { Button } from './Button.tsx'

/**
 * Previous / Next with the page position, for a list the server pages. It only reports the wish to change
 * page; the caller owns the page number and the fetch. `total` and `noun` are optional: given, the position
 * reads "Page 2 of 5 (120 members)".
 */
export function Pagination({
  page,
  lastPage,
  total,
  noun,
  onPageChange,
}: {
  page: number
  lastPage: number
  total?: number
  noun?: { one: string; other: string }
  onPageChange: (page: number) => void
}) {
  const count =
    total === undefined
      ? null
      : noun === undefined
        ? total.toString()
        : `${total.toString()} ${total === 1 ? noun.one : noun.other}`

  return (
    <nav aria-label="Pages" className="flex flex-wrap items-center gap-3 text-body">
      <Button
        disabled={page <= 1}
        onClick={() => {
          onPageChange(page - 1)
        }}
      >
        Previous
      </Button>
      <span role="status" className="text-muted-foreground">
        Page {page} of {lastPage}
        {count === null ? null : ` (${count})`}
      </span>
      <Button
        disabled={page >= lastPage}
        onClick={() => {
          onPageChange(page + 1)
        }}
      >
        Next
      </Button>
    </nav>
  )
}
