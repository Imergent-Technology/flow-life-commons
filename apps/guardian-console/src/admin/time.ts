/** A server instant, shown as the reader's local date and time. */
export function shown(iso: string | null): string {
  if (iso === null) return '—'
  const when = new Date(iso)
  return Number.isNaN(when.getTime())
    ? '—'
    : when.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * An instant as a `datetime-local` input holds it: the reader's local wall clock to the minute, with no zone. The inverse of
 * `instantFromLocal` (membershipTerm.ts), to the minute: a form prefilled from an instant and left alone round-trips to the
 * same string, so "unchanged" can be told from "changed".
 */
export function localInputFrom(iso: string): string {
  const when = new Date(iso)
  if (Number.isNaN(when.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  const date = [String(when.getFullYear()), pad(when.getMonth() + 1), pad(when.getDate())].join('-')
  return `${date}T${pad(when.getHours())}:${pad(when.getMinutes())}`
}
