import { useCurrentAccount } from '../auth/auth-context.ts'
import { Property, PropertyList } from './PropertyList.tsx'

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })

function format(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? iso : when.format(date)
}

/** What `/me` reports about the session: when it began and the latest it can last. No identifiers. */
export function SessionSummary() {
  const { session } = useCurrentAccount()
  return (
    <PropertyList>
      <Property term="Session started">
        <time dateTime={session.authenticated_at}>{format(session.authenticated_at)}</time>
      </Property>
      <Property term="Ends no later than">
        <time dateTime={session.absolute_expires_at}>{format(session.absolute_expires_at)}</time>
      </Property>
    </PropertyList>
  )
}
