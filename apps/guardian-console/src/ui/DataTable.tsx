import type { ComponentProps } from 'react'

import { cn } from './cn.ts'

/**
 * Presentational table primitives over native table markup, for the operational lists (Accounts, Members).
 * They style; they do not sort, filter, select or fetch. Rows and cells are ordinary elements, so the
 * caller composes them exactly as it would a plain `<table>`.
 *
 *     <DataTable caption="Members">
 *       <DataTableHead><tr><DataTableHeaderCell>Name</DataTableHeaderCell></tr></DataTableHead>
 *       <tbody><DataTableRow><DataTableRowHeader>…</DataTableRowHeader></DataTableRow></tbody>
 *     </DataTable>
 *
 * The caption names the table for assistive technology and is not shown. The surface scrolls sideways
 * rather than squashing columns; give the table a `min-w-*` through `className` where it needs one.
 */
export function DataTable({
  caption,
  className,
  children,
  ...props
}: Omit<ComponentProps<'table'>, 'caption'> & { caption: string }) {
  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-surface shadow-panel">
      <table {...props} className={cn('w-full border-collapse text-left text-body', className)}>
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
      className={cn('h-(--table-header-height) border-b border-border bg-muted', className)}
    />
  )
}

export function DataTableRow({ className, ...props }: ComponentProps<'tr'>) {
  return (
    <tr
      {...props}
      className={cn(
        'h-(--row-height) border-b border-border last:border-b-0 hover:bg-muted/60',
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
        className,
      )}
    />
  )
}

/** The row's first cell: a `th scope="row"`, so a screen reader names the row by it. Usually holds the link. */
export function DataTableRowHeader({ className, ...props }: ComponentProps<'th'>) {
  return (
    <th scope="row" {...props} className={cn(cell, 'font-medium text-foreground', className)} />
  )
}

/**
 * A data cell. `truncate` keeps it to one line with an ellipsis, and exposes the full text as a `title`
 * when the content is plain text. Use it for values that are long but not essential to read in full
 * (an email address), not for anything a person must be able to read.
 */
export function DataTableCell({
  truncate = false,
  className,
  children,
  title,
  ...props
}: ComponentProps<'td'> & { truncate?: boolean }) {
  const fullText =
    truncate && title === undefined && typeof children === 'string' ? children : title
  return (
    <td
      {...props}
      title={fullText}
      className={cn(cell, 'text-foreground', truncate && 'max-w-0 truncate', className)}
    >
      {children}
    </td>
  )
}
