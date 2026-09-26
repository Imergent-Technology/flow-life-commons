import type { ComponentProps } from 'react'

import { cn } from './cn.ts'

/**
 * Presentational table primitives over native table markup, for the operational lists (Accounts, Members).
 * They style; they do not sort, filter, select or fetch. Rows and cells are ordinary elements, so the
 * caller composes them exactly as it would a plain `<table>`.
 *
 *     <DataTable caption="Members">
 *       <DataTableHead><tr><DataTableHeaderCell>Name</DataTableHeaderCell></tr></DataTableHead>
 *       <DataTableBody>
 *         <DataTableRow>
 *           <DataTableRowHeader>…</DataTableRowHeader>
 *           <DataTableCell label="State">…</DataTableCell>
 *         </DataTableRow>
 *       </DataTableBody>
 *     </DataTable>
 *
 * The caption names the table for assistive technology and is not shown.
 *
 * Two layouts, one markup. From 768px the table is a table, with a tinted header that stays at the top of the
 * window while the list scrolls (it can, because the table never scrolls sideways: its cells wrap or
 * truncate, and it would be unreadable long before it overflowed). Below 768px each row becomes a stacked
 * record: the row header is its title and every other cell is a "Label  value" line, the label being the
 * cell's own `label` (CSS reads it from `data-label`), so no value is ever shown without its name. The header
 * row stays in the document for assistive technology and is hidden from view.
 */
export function DataTable({
  caption,
  className,
  children,
  ...props
}: Omit<ComponentProps<'table'>, 'caption'> & { caption: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface shadow-panel max-md:rounded-none max-md:border-0 max-md:bg-transparent max-md:shadow-none md:overflow-clip">
      <table
        {...props}
        className={cn('w-full border-collapse text-left text-body max-md:block', className)}
      >
        <caption className="sr-only">{caption}</caption>
        {children}
      </table>
    </div>
  )
}

export function DataTableHead({ className, ...props }: ComponentProps<'thead'>) {
  return (
    <thead
      {...props}
      className={cn('h-(--table-header-height) bg-muted max-md:sr-only', className)}
    />
  )
}

export function DataTableBody({ className, ...props }: ComponentProps<'tbody'>) {
  return <tbody {...props} className={cn('max-md:flex max-md:flex-col max-md:gap-3', className)} />
}

export function DataTableRow({ className, ...props }: ComponentProps<'tr'>) {
  return (
    <tr
      {...props}
      className={cn(
        'h-(--row-height) border-b border-border last:border-b-0 hover:bg-muted/60',
        'max-md:block max-md:h-auto max-md:rounded-lg max-md:border max-md:border-border max-md:bg-surface max-md:p-3.5 max-md:shadow-panel max-md:last:border-b max-md:hover:bg-surface',
        className,
      )}
    />
  )
}

const cell = 'px-3.5 align-middle'

export function DataTableHeaderCell({ className, ...props }: ComponentProps<'th'>) {
  return (
    <th
      scope="col"
      {...props}
      className={cn(
        cell,
        'text-meta font-medium whitespace-nowrap text-muted-foreground',
        'shadow-[inset_0_-1px_0_var(--border)] md:sticky md:top-0 md:z-10 md:bg-muted',
        className,
      )}
    />
  )
}

/**
 * The row's first cell: a `th scope="row"`, so a screen reader names the row by it. Usually holds the link.
 * In a stacked record it is the record's title, so it carries no label of its own.
 */
export function DataTableRowHeader({ className, ...props }: ComponentProps<'th'>) {
  return (
    <th
      scope="row"
      {...props}
      className={cn(
        cell,
        'font-medium wrap-anywhere text-foreground',
        'max-md:block max-md:px-0 max-md:pb-2 max-md:text-left',
        className,
      )}
    />
  )
}

/**
 * A data cell. `label` is the column's name: it is what the stacked layout shows beside the value, so it is
 * required. `truncate` keeps the value to one line with an ellipsis and exposes the full text as a `title`
 * when the content is plain text; use it for values that are long but not essential to read in full (an
 * email address), not for anything a person must be able to read. A stacked record never truncates, and it
 * puts the label above such a value instead of beside it, so a long value has the whole width of the record
 * rather than what a label column leaves it (a name a few characters too long for the narrow column would
 * otherwise leave its last one or two on a line of their own).
 */
export function DataTableCell({
  label,
  truncate = false,
  className,
  children,
  title,
  ...props
}: ComponentProps<'td'> & { label: string; truncate?: boolean }) {
  const fullText =
    truncate && title === undefined && typeof children === 'string' ? children : title
  return (
    <td
      {...props}
      data-label={label}
      title={fullText}
      className={cn(
        cell,
        'text-foreground',
        truncate &&
          'max-w-0 truncate max-md:max-w-none max-md:overflow-visible max-md:wrap-anywhere max-md:text-clip max-md:whitespace-normal',
        'max-md:grid max-md:items-baseline max-md:px-0 max-md:py-1 max-md:*:justify-self-start max-md:before:text-meta max-md:before:text-muted-foreground max-md:before:content-[attr(data-label)]',
        truncate
          ? 'max-md:grid-cols-[minmax(0,1fr)] max-md:gap-y-0.5'
          : 'max-md:grid-cols-[6.5rem_minmax(0,1fr)] max-md:gap-x-3',
        className,
      )}
    >
      {children}
    </td>
  )
}
