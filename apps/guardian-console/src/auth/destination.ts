import type { CurrentAccount } from '../api/auth.ts'
import { CONSOLE_ACCESS, hasCapability } from './capabilities.ts'

/** Where the Member self-service surface lives. The one place that spells it, so nothing else has to. */
export const MEMBER_HOME = '/my'

/**
 * Where a signed-in Account belongs by default, decided by capability alone, never a role name (ADR
 * 0017, ADR 0032): holding `console.access` lands in the Console; anything else lands in the Member
 * self-service area. This says nothing about whether either surface may then be ENTERED — the
 * server re-decides every request, and privileged routes stay refused regardless of where someone lands.
 */
export function defaultDestination(current: CurrentAccount): string {
  return hasCapability(current, CONSOLE_ACCESS) ? '/' : MEMBER_HOME
}
