import type { Candidate } from '../api/relationships.ts'

/**
 * Which candidates may be chosen (ADR 0038, P4). The lookup returns no contact value, so people who share a display name
 * cannot be told apart by it, and a choice made on the name alone could attach the wrong person. When a NAME search
 * returns two or more candidates with the same name, none of them is offered for choosing; the operator searches again by
 * an exact email or phone, or adds someone new. A candidate found by an exact email or phone is chosen on that, and one who
 * already holds the type is a link to their record, never a second recording.
 *
 * A name search that was cut off (`truncated`) shows only some of the people who match, so a name that looks unique among
 * those shown may be shared with someone who was not: no name-only match can be chosen from it either.
 */
export type CandidateChoice =
  { kind: 'choose' } | { kind: 'ambiguous' } | { kind: 'already'; status: string }

const nameKey = (candidate: Candidate) => (candidate.person.displayName ?? '').trim().toLowerCase()

export function sharedNames(candidates: readonly Candidate[]): Set<string> {
  const counts = new Map<string, number>()
  for (const candidate of candidates) {
    if (candidate.matchedOn !== 'display_name') continue
    counts.set(nameKey(candidate), (counts.get(nameKey(candidate)) ?? 0) + 1)
  }
  return new Set([...counts].filter(([, count]) => count > 1).map(([name]) => name))
}

export function choiceFor(
  candidate: Candidate,
  shared: ReadonlySet<string>,
  truncated = false,
): CandidateChoice {
  if (candidate.status !== null) return { kind: 'already', status: candidate.status }
  if (candidate.matchedOn === 'display_name' && (truncated || shared.has(nameKey(candidate)))) {
    return { kind: 'ambiguous' }
  }
  return { kind: 'choose' }
}

export const MIN_QUERY = 3
