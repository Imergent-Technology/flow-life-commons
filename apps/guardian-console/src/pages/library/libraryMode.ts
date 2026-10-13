import { createContext, useContext, useEffect } from 'react'

import type { CurrentAccount } from '../../api/auth.ts'
import type { Failure } from '../../api/http.ts'
import { hasCapability, RESOURCES_VIEW } from '../../auth/capabilities.ts'

/**
 * Which reader the library is showing (ADR 0038, C1). It is decided once, when the library is entered, from the latest `/me`:
 * `resources.view` means the viewer (policy A), anything else the audience reader (policy C). It is never chosen by an address, a
 * preference, a role or a relationship, and it is never raised in place: a capability gained mid-visit is picked up on the next
 * entry. It is lowered only by the platform saying no (a 403 from the viewer), after the Console has asked `/me` again.
 */
export type LibraryMode = 'viewer' | 'audience'

/** The reader a visit starts in: the viewer for someone who holds `resources.view`, the audience reader for everyone else. */
export const modeFor = (current: CurrentAccount): LibraryMode =>
  hasCapability(current, RESOURCES_VIEW) ? 'viewer' : 'audience'

export interface LibraryModeValue {
  mode: LibraryMode
  /**
   * A request of this reader was answered 403. The route stops showing anything of this reader at once, asks `/me` again and only
   * then decides what comes next. Called at most once at a time; the route ignores a second while it is deciding.
   */
  forbidden: (from: LibraryMode) => void
}

export const LibraryModeContext = createContext<LibraryModeValue | null>(null)

export function useLibraryMode(): LibraryModeValue {
  const value = useContext(LibraryModeContext)
  if (value === null) throw new Error('useLibraryMode must be used inside <LibraryRoute>.')
  return value
}

/**
 * Reports a 403 from one of this reader's JSON requests to the route, once, and says whether the failure was one. A page that gets
 * `true` draws nothing of its own: whatever it held is the viewer's, and the route is about to take it away.
 *
 * Only JSON requests can say this. A file is asked for by an `<img>` or a link, and the browser tells the page nothing of why one
 * failed, so a file is never a reason to change mode.
 */
export function useReportForbidden(failure: Failure | null, from: LibraryMode): boolean {
  const { forbidden } = useLibraryMode()
  const refused = failure?.kind === 'forbidden'
  useEffect(() => {
    if (refused) forbidden(from)
  }, [refused, forbidden, from])
  return refused
}
