import { useSyncExternalStore } from 'react'

import {
  clearNavPreference,
  getPreferencesSnapshot,
  setNavPreference,
  subscribeToPreferences,
  type NavPreference,
} from './preferences.ts'

export interface NavPreferenceValue {
  /**
   * The operator's explicit choice, or `undefined` while none has been made — the shell's responsive
   * default decides while it is absent (design spec S6). This hook never derives one from the
   * viewport: it owns preference state, not shell layout. Resolving it against a breakpoint is WP4's.
   */
  nav: NavPreference | undefined
  setPinned: () => void
  setOverlay: () => void
  /** Back to "no explicit preference". */
  clear: () => void
}

/** The drawer preference seam WP4 builds its breakpoint resolution on top of. */
export function useNavPreference(): NavPreferenceValue {
  const preferences = useSyncExternalStore(subscribeToPreferences, getPreferencesSnapshot)
  return {
    nav: preferences.nav,
    setPinned: () => {
      setNavPreference('pinned')
    },
    setOverlay: () => {
      setNavPreference('overlay')
    },
    clear: clearNavPreference,
  }
}
