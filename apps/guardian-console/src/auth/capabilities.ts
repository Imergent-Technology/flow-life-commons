// Capabilities are what the Console asks about; role names never appear here (ADR 0017). What `/me`
// returns is for PRESENTATION only: it decides what to show, never what is allowed. The server
// re-decides every request, so hiding a control is a courtesy and showing one grants nothing.

import type { CurrentAccount } from '../api/auth.ts'

export const CONSOLE_ACCESS = 'console.access'

export function hasCapability(current: CurrentAccount, capability: string): boolean {
  return current.capabilities.includes(capability)
}
