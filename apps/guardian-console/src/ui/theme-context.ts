import { createContext, useContext } from 'react'

import type { ResolvedTheme, ThemePreference } from './preferences.ts'

export interface ThemeContextValue {
  /** What the operator has chosen, or the implicit default (`system`). */
  preference: ThemePreference
  /** What is actually painted: `system` resolved against the OS; `light`/`dark` taken as given. */
  resolvedTheme: ResolvedTheme
  setTheme: (preference: ThemePreference) => void
}

export const ThemeContext = createContext<ThemeContextValue | null>(null)

export function useTheme(): ThemeContextValue {
  const value = useContext(ThemeContext)
  if (value === null) throw new Error('useTheme must be used inside <ThemeProvider>.')
  return value
}
