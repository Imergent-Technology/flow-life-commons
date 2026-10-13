// The Resources audience catalog (ADR 0038, R1): `guardian`, `member` and `volunteer`, in that order. It is a catalog of WHO A
// RESOURCE IS FOR, not of who may read it: an audience is a targeting label on a Pack or Card, and eligibility to read is decided by
// the server from the signed-in Person's relationships (policy C) or ignored for a holder of `resources.view` (policy A).
//
// It lives apart from both the management client and the library client so that each can use it without depending on the other:
// the library must not import the management client at run time (ADR 0038, C7), and management has no business importing the library.
// An unknown audience is refused here exactly as the server refuses it: nothing is guessed, so a value outside the catalog is no
// audience (R1: an unknown audience fails closed).

export type Audience = 'guardian' | 'member' | 'volunteer'

/** Every audience, in the catalog's order. The order is the one the server uses and the one every list in the Console follows. */
export const AUDIENCES: readonly Audience[] = ['guardian', 'member', 'volunteer']

export const isAudience = (value: unknown): value is Audience =>
  typeof value === 'string' && AUDIENCES.some((audience) => audience === value)

export const isAudienceList = (value: unknown): value is Audience[] =>
  Array.isArray(value) && value.every(isAudience)
