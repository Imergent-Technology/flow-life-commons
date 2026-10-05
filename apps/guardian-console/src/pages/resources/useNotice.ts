import { useLocation } from 'react-router'

/**
 * A line a previous screen left for this one in the navigation state ("The Card was deleted."), if it did. It is plain text the
 * Console itself wrote, never anything from the server, and it is gone as soon as the person moves on.
 */
export function useNotice(): string | null {
  const state: unknown = useLocation().state
  if (typeof state !== 'object' || state === null || !('notice' in state)) return null
  return typeof state.notice === 'string' ? state.notice : null
}
