import type { DefaultRoleChoice, DefaultRoleDecision } from '../api/relationships.ts'

// The Guardian Initiate choice, the non-escalating contract of ADR 0038 K5. Nothing here names a role: whether a type has a
// default role, what it is called and whether this operator may provision it all arrive from the served definition.
//
// The server never grants by omission, and neither does this file. A preselection is only a starting point; the operator
// still confirms, and what is sent is what they confirmed.

/** What the option looks like when the operator opens it. */
export type InitialChoice =
  /** The operator cannot provision: the option is unavailable and the request will say `defer`. */
  { available: false } | { available: true; checked: boolean }

/**
 * K5's table. For an operator who may provision: a stored `declined` starts unchecked and everything else (a stored
 * `granted`, nothing stored, or active recognition at intake) starts checked. For an operator who may not: unavailable.
 * The matrix is the whole rule, so the stored decision is the ONLY thing besides the hint that is read.
 */
export function initialChoice(input: {
  mayProvision: boolean
  stored: DefaultRoleDecision | null
}): InitialChoice {
  if (!input.mayProvision) return { available: false }
  return { available: true, checked: input.stored !== 'declined' }
}

/**
 * What to send. An unavailable option is always `defer`, which grants nothing and cannot erase a stored `declined`. An
 * available one sends exactly what the operator left it at: checked is `grant`, unchecked is `decline`.
 */
export function choiceToSend(option: InitialChoice, checked: boolean): DefaultRoleChoice {
  if (!option.available) return 'defer'
  return checked ? 'grant' : 'decline'
}

export function unavailableExplanation(roleName: string): string {
  return `Granting ${roleName} access needs permission to assign roles. Someone who has it can grant it from this record later.`
}
