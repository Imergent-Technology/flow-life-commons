import { useCurrentAccount } from '../auth/auth-context.ts'

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })

function format(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? iso : when.format(date)
}

/** What `/me` reports about the session: when it began and the latest it can last. No identifiers. */
export function SessionSummary() {
  const { session } = useCurrentAccount()
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
      <dt className="text-slate-500">Session started</dt>
      <dd>
        <time dateTime={session.authenticated_at}>{format(session.authenticated_at)}</time>
      </dd>
      <dt className="text-slate-500">Ends no later than</dt>
      <dd>
        <time dateTime={session.absolute_expires_at}>{format(session.absolute_expires_at)}</time>
      </dd>
    </dl>
  )
}
