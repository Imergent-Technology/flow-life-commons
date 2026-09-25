import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { clearNavPreference, setThemePreference } from './preferences.ts'
import { useNavPreference } from './useNavPreference.ts'

// The preferences module is a singleton: localStorage.clear() alone would not undo an in-memory write
// from an earlier test, so state is reset through the same public API a caller would use.
beforeEach(() => {
  localStorage.clear()
  clearNavPreference()
  setThemePreference('system')
})

afterEach(() => {
  localStorage.clear()
})

describe('the nav preference seam', () => {
  it('is undefined when the operator has made no explicit choice: no default is invented', () => {
    const { result } = renderHook(() => useNavPreference())
    expect(result.current.nav).toBeUndefined()
  })

  it('setPinned records an explicit pin, which persists through the one preferences key', () => {
    const { result } = renderHook(() => useNavPreference())
    act(() => {
      result.current.setPinned()
    })
    expect(result.current.nav).toBe('pinned')
    expect(JSON.parse(localStorage.getItem('flowlife.console.ui') ?? 'null')).toEqual({
      v: 1,
      theme: 'system',
      nav: 'pinned',
    })
  })

  it('setOverlay records an explicit overlay preference', () => {
    const { result } = renderHook(() => useNavPreference())
    act(() => {
      result.current.setOverlay()
    })
    expect(result.current.nav).toBe('overlay')
  })

  it('clear returns to "no explicit preference", not a third stored state', () => {
    const { result } = renderHook(() => useNavPreference())
    act(() => {
      result.current.setPinned()
    })
    act(() => {
      result.current.clear()
    })
    expect(result.current.nav).toBeUndefined()
    const raw = JSON.parse(localStorage.getItem('flowlife.console.ui') ?? 'null') as Record<
      string,
      unknown
    >
    expect(Object.hasOwn(raw, 'nav')).toBe(false)
  })

  it('preserves the theme preference across a nav write, and vice versa', () => {
    setThemePreference('dark')
    const { result } = renderHook(() => useNavPreference())
    act(() => {
      result.current.setPinned()
    })
    expect(JSON.parse(localStorage.getItem('flowlife.console.ui') ?? 'null')).toEqual({
      v: 1,
      theme: 'dark',
      nav: 'pinned',
    })
  })

  it('two hook instances observe the same write: this is one preference, not two independent states', () => {
    const a = renderHook(() => useNavPreference())
    const b = renderHook(() => useNavPreference())
    act(() => {
      a.result.current.setOverlay()
    })
    expect(b.result.current.nav).toBe('overlay')
  })

  it('never writes or reads anything derived from the viewport: it is not called at all', () => {
    // There is no viewport argument to give it, and no window-size dependency in its implementation
    // for a test to defeat — the type of useNavPreference() takes nothing, which is the guarantee.
    const { result } = renderHook(() => useNavPreference())
    expect(result.current.nav).toBeUndefined()
    // Confirms the absence of a preference is not silently replaced by a resolved default here.
    expect(Object.keys(result.current)).toEqual(['nav', 'setPinned', 'setOverlay', 'clear'])
  })
})
