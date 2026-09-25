import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const STORAGE_KEY = 'flowlife.console.ui'

/** A fresh module instance, so the in-memory singleton reads whatever is in storage right now. */
async function freshModule() {
  vi.resetModules()
  return import('./preferences.ts')
}

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('reading a stored preference', () => {
  it('gives implicit defaults when there is no key at all, and writes nothing just for reading them', async () => {
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'system' })
    expect(localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('falls back to defaults for malformed JSON', async () => {
    localStorage.setItem(STORAGE_KEY, 'not json at all {')
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'system' })
  })

  it('falls back to defaults for the wrong schema version', async () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 2, theme: 'dark' }))
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'system' })
  })

  it('falls back to system for an invalid theme value', async () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, theme: 'purple' }))
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot().theme).toBe('system')
  })

  it('treats an invalid nav value as absent, not as a third state', async () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, theme: 'light', nav: 'sideways' }))
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'light' })
  })

  it('never exposes an unrecognised field, whatever the stored JSON carries', async () => {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ v: 1, theme: 'dark', nav: 'pinned', token: 'abc123', account_id: 42 }),
    )
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'dark', nav: 'pinned' })
  })
})

describe('writing a preference', () => {
  it('a canonical write contains only the approved fields, nothing carried over from a dirty read', async () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, theme: 'light', extra: 'nope' }))
    const preferences = await freshModule()
    preferences.setThemePreference('dark')
    const raw: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')
    expect(raw).toEqual({ v: 1, theme: 'dark' })
  })

  it('persists an explicit choice of "system" too: choosing it differs from a fresh visitor', async () => {
    const preferences = await freshModule()
    preferences.setThemePreference('system')
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')).toEqual({
      v: 1,
      theme: 'system',
    })
  })

  it('a theme write preserves an existing nav preference', async () => {
    const preferences = await freshModule()
    preferences.setNavPreference('pinned')
    preferences.setThemePreference('dark')
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'dark', nav: 'pinned' })
  })

  it('a nav write preserves the existing theme preference', async () => {
    const preferences = await freshModule()
    preferences.setThemePreference('dark')
    preferences.setNavPreference('overlay')
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'dark', nav: 'overlay' })
  })

  it('clearing nav removes the field entirely rather than inventing a third stored state', async () => {
    const preferences = await freshModule()
    preferences.setNavPreference('pinned')
    preferences.clearNavPreference()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'system' })
    const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null') as Record<string, unknown>
    expect(Object.hasOwn(raw, 'nav')).toBe(false)
  })

  it('fails safely when storage is unavailable: the in-memory state still serves this session', async () => {
    const preferences = await freshModule()
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    expect(() => {
      preferences.setThemePreference('dark')
    }).not.toThrow()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'dark' })
  })

  it('reads safely when storage throws on access: defaults, not a crash', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    const preferences = await freshModule()
    expect(preferences.getPreferencesSnapshot()).toEqual({ theme: 'system' })
  })
})

describe('subscribing to changes', () => {
  it('notifies subscribers on a write, and stops after unsubscribing', async () => {
    const preferences = await freshModule()
    const seen: unknown[] = []
    const unsubscribe = preferences.subscribeToPreferences(() => {
      seen.push(preferences.getPreferencesSnapshot())
    })
    preferences.setThemePreference('dark')
    unsubscribe()
    preferences.setThemePreference('light')
    expect(seen).toEqual([{ theme: 'dark' }])
  })
})

describe('theme resolution', () => {
  it('resolves an explicit light preference to light, ignoring the OS', async () => {
    const preferences = await freshModule()
    expect(preferences.resolveTheme('light', true)).toBe('light')
  })

  it('resolves an explicit dark preference to dark, ignoring the OS', async () => {
    const preferences = await freshModule()
    expect(preferences.resolveTheme('dark', false)).toBe('dark')
  })

  it('resolves system against the OS signal it is given', async () => {
    const preferences = await freshModule()
    expect(preferences.resolveTheme('system', true)).toBe('dark')
    expect(preferences.resolveTheme('system', false)).toBe('light')
  })

  it('reads the OS preference through matchMedia, and fails safe if it throws', async () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('dark') }))
    const preferences = await freshModule()
    expect(preferences.systemPrefersDark()).toBe(true)

    vi.stubGlobal('matchMedia', () => {
      throw new Error('not implemented')
    })
    expect(preferences.systemPrefersDark()).toBe(false)
  })

  it('readInitialTheme combines the stored preference and the OS signal, for the pre-render stamp', async () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, theme: 'system' }))
    vi.stubGlobal('matchMedia', () => ({ matches: true }))
    const preferences = await freshModule()
    expect(preferences.readInitialTheme()).toEqual({ preference: 'system', resolved: 'dark' })
  })
})
