/**
 * The Console's one narrowly scoped browser-storage boundary (ADR 0030; design spec S9).
 *
 * ONE `localStorage` key, `flowlife.console.ui`, holding a schema version, the theme mode, and the
 * navigation drawer choice, and NOTHING else — no identity, no account or session information, no
 * capabilities, no API or business data, no credentials, no secrets. That is a device/interface fact,
 * not an account fact, so it survives sign-out; the HttpOnly session cookie remains the only authority
 * over who is signed in (ADR 0016). Every other file reaches this state through the exports below —
 * the guardrail in guardrails.test.ts makes this the only file allowed to name `localStorage`.
 *
 * `nav` is optional by design: it is absent until the operator explicitly pins or unpins the drawer,
 * and while absent the shell's responsive default decides (design spec S6). This module never invents
 * a nav preference on the operator's behalf, and never writes anything merely because the module ran —
 * a fresh visitor leaves no entry until an explicit choice needs one.
 */

export type ThemePreference = 'system' | 'light' | 'dark'
export type NavPreference = 'pinned' | 'overlay'
export type ResolvedTheme = 'light' | 'dark'

/** The whole permitted surface. A fourth field is not representable. */
export interface UiPreferences {
  theme: ThemePreference
  nav?: NavPreference
}

const STORAGE_KEY = 'flowlife.console.ui'
const SCHEMA_VERSION = 1
const DEFAULTS: UiPreferences = { theme: 'system' }

function isThemePreference(value: unknown): value is ThemePreference {
  return value === 'system' || value === 'light' || value === 'dark'
}

function isNavPreference(value: unknown): value is NavPreference {
  return value === 'pinned' || value === 'overlay'
}

/**
 * Anything unrecognised, malformed or from another schema version falls back to the defaults. Every
 * unknown field is dropped: it never becomes observable application state, and the next legitimate
 * write does not carry it forward. Never throws: private mode or blocked storage just means defaults.
 */
function readStored(): UiPreferences {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw === null) return DEFAULTS

    const parsed: unknown = JSON.parse(raw)
    if (typeof parsed !== 'object' || parsed === null) return DEFAULTS

    const record = parsed as Record<string, unknown>
    if (record.v !== SCHEMA_VERSION) return DEFAULTS

    const theme = isThemePreference(record.theme) ? record.theme : DEFAULTS.theme
    const nav = isNavPreference(record.nav) ? record.nav : undefined
    return nav === undefined ? { theme } : { theme, nav }
  } catch {
    return DEFAULTS
  }
}

/**
 * Writes exactly the allowed fields, canonically. A failure here (quota, blocked storage) never
 * throws outward: the in-memory store below still serves the rest of this session correctly, it just
 * will not survive a reload.
 */
function writeStored(preferences: UiPreferences): void {
  try {
    const canonical =
      preferences.nav === undefined
        ? { v: SCHEMA_VERSION, theme: preferences.theme }
        : { v: SCHEMA_VERSION, theme: preferences.theme, nav: preferences.nav }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(canonical))
  } catch {
    // Private mode, blocked storage, or quota. The current in-memory state (below) is unaffected.
  }
}

// ---------------------------------------------------------------------------------------------------
// A tiny subscribable store, so every consumer (ThemeProvider, useNavPreference, and however many of
// each mount at once) observes the same value and the same change, without a second, independent
// notion of "what the preference currently is". Reading storage happens once, at module load; nothing
// re-reads it afterward, so a change from another tab is not something this module claims to track.
// ---------------------------------------------------------------------------------------------------

type Listener = () => void

let current: UiPreferences = readStored()
const listeners = new Set<Listener>()

function notify(): void {
  for (const listener of listeners) listener()
}

function commit(next: UiPreferences): void {
  current = next
  writeStored(next)
  notify()
}

/** For `useSyncExternalStore`. Returns the same reference until something actually changes. */
export function getPreferencesSnapshot(): UiPreferences {
  return current
}

/** For `useSyncExternalStore`. */
export function subscribeToPreferences(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** An explicit theme choice. Persisted even when it names the default (`system`): choosing it is
 * still a choice, distinct from a fresh visitor who has chosen nothing yet. */
export function setThemePreference(theme: ThemePreference): void {
  commit(current.nav === undefined ? { theme } : { theme, nav: current.nav })
}

/** An explicit pin or unpin. */
export function setNavPreference(nav: NavPreference): void {
  commit({ theme: current.theme, nav })
}

/** Back to "no explicit preference": the shell's responsive default decides again (design spec S6). */
export function clearNavPreference(): void {
  commit({ theme: current.theme })
}

/** `light` or `dark`, resolving `system` against the OS preference passed in. */
export function resolveTheme(
  preference: ThemePreference,
  systemPrefersDark: boolean,
): ResolvedTheme {
  if (preference === 'light') return 'light'
  if (preference === 'dark') return 'dark'
  return systemPrefersDark ? 'dark' : 'light'
}

/** `matchMedia('(prefers-color-scheme: dark)').matches`, or `false` if it cannot be read. */
export function systemPrefersDark(): boolean {
  try {
    return globalThis.matchMedia('(prefers-color-scheme: dark)').matches
  } catch {
    return false
  }
}

/**
 * Everything `main.tsx` needs to stamp the document before the first render, computed synchronously
 * and from the same parsing and resolution this module uses everywhere else — never a second,
 * independent read of storage just for bootstrap.
 */
export function readInitialTheme(): { preference: ThemePreference; resolved: ResolvedTheme } {
  const preference = readStored().theme
  return { preference, resolved: resolveTheme(preference, systemPrefersDark()) }
}

/**
 * Where CSS and native controls read the resolved theme (design spec S9, "Theme provider"). Used both
 * by the pre-render stamp in `main.tsx` and by `ThemeProvider` afterward, so there is one place that
 * ever writes it, not two implementations that could drift.
 */
export function applyResolvedTheme(resolved: ResolvedTheme): void {
  document.documentElement.dataset.theme = resolved
  document.documentElement.style.colorScheme = resolved
}
