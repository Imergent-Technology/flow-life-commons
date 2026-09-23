// Converts what a `datetime-local` input holds (a browser-local wall-clock instant, with NO timezone of its own) into the
// UTC instant the Membership API expects, deliberately: `new Date(value)` parses a value in this exact shape as the
// browser's local time (the same convention `admin/time.ts`'s `shown()` already displays in), and `toISOString()` is then
// unambiguous. Never hand-rolled offset arithmetic.

import type { MembershipTerm } from '../api/membership.ts'

export interface MembershipTermState {
  /** Raw `datetime-local` value: `''` until the person picks something. */
  startsAt: string
  /** ADR 0028: open-ended access is a deliberate operator choice, never the default. */
  openEnded: boolean
  /** Raw `datetime-local` value. Ignored (and may be blank) while `openEnded` is true. */
  endsAt: string
}

export const initialMembershipTerm: MembershipTermState = {
  startsAt: '',
  openEnded: false,
  endsAt: '',
}

/** The ISO instant a `datetime-local` value names, or null if it is empty or not a valid instant. */
export function instantFromLocal(value: string): string | null {
  if (value === '') return null
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString()
}

export type MembershipTermField = 'starts_at' | 'ends_at'

export type MembershipTermResult =
  { ok: true; term: MembershipTerm } | { ok: false; field: MembershipTermField; message: string }

/**
 * Turns the form state into what the API expects, or says which field stopped it. Checked here, before the request: the
 * server is still authoritative (`ends_at` must be present, and strictly after `starts_at` when bounded), but a person
 * should not wait for a round trip to be told they left the start blank.
 */
export function resolveMembershipTerm(term: MembershipTermState): MembershipTermResult {
  const startsAt = instantFromLocal(term.startsAt)
  if (startsAt === null) {
    return { ok: false, field: 'starts_at', message: 'Enter when access starts.' }
  }
  if (term.openEnded) return { ok: true, term: { startsAt, openEnded: true, endsAt: null } }

  const endsAt = instantFromLocal(term.endsAt)
  if (endsAt === null) {
    return {
      ok: false,
      field: 'ends_at',
      message: 'Enter when access ends, or choose open-ended access.',
    }
  }
  if (Date.parse(endsAt) <= Date.parse(startsAt)) {
    return { ok: false, field: 'ends_at', message: 'Access must end after it starts.' }
  }
  return { ok: true, term: { startsAt, openEnded: false, endsAt } }
}
