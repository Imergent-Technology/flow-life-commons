import type { CurrentMembership } from '../api/myMembership.ts'

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' })

export function formatDate(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? iso : when.format(date)
}

/**
 * What the current access-through says, as text: open-ended, a finite date, or nothing while inactive.
 * Reads the backend's OWN `active`/`open_ended`/`current_access_ends_at` fields only — it never
 * re-derives "active" from a grant's own timestamps.
 */
export function accessThroughLabel(
  membership: Pick<CurrentMembership, 'active' | 'openEnded' | 'currentAccessEndsAt'>,
): string {
  if (!membership.active) return 'Not currently active'
  if (membership.openEnded) return 'Open-ended'
  return membership.currentAccessEndsAt === null
    ? 'Not currently active'
    : `Access through ${formatDate(membership.currentAccessEndsAt)}`
}
