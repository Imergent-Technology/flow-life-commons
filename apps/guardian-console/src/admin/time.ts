/** A server instant, shown as the reader's local date and time. */
export function shown(iso: string | null): string {
  if (iso === null) return '—'
  const when = new Date(iso)
  return Number.isNaN(when.getTime())
    ? '—'
    : when.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
