import { useEffect, useMemo, useSyncExternalStore, type ReactNode } from 'react'

import {
  applyResolvedTheme,
  getPreferencesSnapshot,
  resolveTheme,
  setThemePreference,
  subscribeToPreferences,
  systemPrefersDark,
} from './preferences.ts'
import { ThemeContext, type ThemeContextValue } from './theme-context.ts'

/**
 * Resolves the theme and keeps the document stamped as it changes, and exposes both to the account menu,
 * which is what lets an operator change it. The pre-render stamp in `main.tsx` already applied the correct
 * value before this component exists; this keeps it correct afterward — an explicit choice, or (in
 * System mode) the OS changing live underneath the operator.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const preferences = useSyncExternalStore(subscribeToPreferences, getPreferencesSnapshot)
  // Subscribed unconditionally: `resolveTheme` below ignores this value for an explicit light/dark
  // preference, so a Light or Dark choice never REACTS to it regardless. Keeping one subscription
  // model (useSyncExternalStore, exactly as the preferences store above uses) rather than mounting and
  // unmounting a listener by hand is what keeps this free of the render/effect timing a manual
  // subscribe-in-an-effect invites.
  const osPrefersDark = useSystemPrefersDark()
  const resolvedTheme = resolveTheme(preferences.theme, osPrefersDark)

  useEffect(() => {
    applyResolvedTheme(resolvedTheme)
  }, [resolvedTheme])

  const value = useMemo<ThemeContextValue>(
    () => ({ preference: preferences.theme, resolvedTheme, setTheme: setThemePreference }),
    [preferences.theme, resolvedTheme],
  )

  return <ThemeContext value={value}>{children}</ThemeContext>
}

/**
 * Never throws, matching `systemPrefersDark`'s own defensiveness: an environment with no
 * `matchMedia` (an old browser, or a test that has not stubbed it) resolves System once, from
 * `systemPrefersDark`'s own safe default, and simply never updates live rather than crashing the app.
 */
function subscribeToSystemScheme(onChange: () => void): () => void {
  try {
    const media = matchMedia('(prefers-color-scheme: dark)')
    media.addEventListener('change', onChange)
    return () => {
      media.removeEventListener('change', onChange)
    }
  } catch {
    return () => undefined
  }
}

/** The OS signal, live. Torn down on unmount, exactly as `useSyncExternalStore` guarantees. */
function useSystemPrefersDark(): boolean {
  return useSyncExternalStore(subscribeToSystemScheme, systemPrefersDark)
}
