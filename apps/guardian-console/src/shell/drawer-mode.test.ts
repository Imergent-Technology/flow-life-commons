import { renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { clearNavPreference, setNavPreference } from '../ui/preferences.ts'
import { installViewport, resizeTo } from '../test/viewport.ts'
import { resolveDrawer, useDrawerMode } from './drawer-mode.ts'
import { PIN_QUERY, RAIL_QUERY, useViewportBand } from './viewport.ts'

const STORAGE_KEY = 'flowlife.console.ui'

beforeEach(() => {
  localStorage.clear()
  clearNavPreference()
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('resolveDrawer', () => {
  it.each([
    ['mobile', undefined, 'sheet', 'viewport'],
    ['mobile', 'pinned', 'sheet', 'viewport'],
    ['mobile', 'overlay', 'sheet', 'viewport'],
    ['rail', undefined, 'overlay', 'viewport'],
    ['wide', undefined, 'pinned', 'viewport'],
    ['rail', 'pinned', 'pinned', 'operator'],
    ['wide', 'overlay', 'overlay', 'operator'],
  ] as const)('%s with %s stored is %s (from the %s)', (band, nav, mode, source) => {
    expect(resolveDrawer(band, nav)).toEqual({ mode, source })
  })
})

describe('the viewport bands', () => {
  it('states both breakpoints', () => {
    expect(RAIL_QUERY).toBe('(min-width: 1024px)')
    expect(PIN_QUERY).toBe('(min-width: 1200px)')
  })

  it.each([
    [375, 'mobile'],
    [1023, 'mobile'],
    [1024, 'rail'],
    [1199, 'rail'],
    [1200, 'wide'],
    [1680, 'wide'],
  ] as const)('%ipx is %s', (width, band) => {
    installViewport(width)
    expect(renderHook(() => useViewportBand()).result.current).toBe(band)
  })

  it('follows a resize live', () => {
    installViewport(1300)
    const { result } = renderHook(() => useViewportBand())
    expect(result.current).toBe('wide')
    resizeTo(1100)
    expect(result.current).toBe('rail')
    resizeTo(900)
    expect(result.current).toBe('mobile')
    resizeTo(1250)
    expect(result.current).toBe('wide')
  })

  it('falls back to the desktop shell where there is no matchMedia at all', () => {
    expect(renderHook(() => useViewportBand()).result.current).toBe('wide')
  })
})

describe('useDrawerMode', () => {
  it('lets the viewport decide while no preference is stored, and reports that', () => {
    installViewport(1100)
    const { result } = renderHook(() => useDrawerMode())
    expect(result.current).toEqual({ band: 'rail', mode: 'overlay', source: 'viewport' })
    resizeTo(1300)
    expect(result.current).toEqual({ band: 'wide', mode: 'pinned', source: 'viewport' })
  })

  it('honours an explicit preference at every desktop width, and is not moved by a resize', () => {
    setNavPreference('overlay')
    installViewport(1300)
    const { result } = renderHook(() => useDrawerMode())
    expect(result.current).toMatchObject({ mode: 'overlay', source: 'operator' })
    resizeTo(1100)
    expect(result.current).toMatchObject({ mode: 'overlay', source: 'operator' })
  })

  it('never writes storage when a breakpoint is crossed, and leaves an absent preference absent', () => {
    installViewport(1300)
    const before = localStorage.getItem(STORAGE_KEY)
    const write = vi.spyOn(Storage.prototype, 'setItem')
    const remove = vi.spyOn(Storage.prototype, 'removeItem')
    renderHook(() => useDrawerMode())
    for (const width of [1100, 900, 375, 1024, 1023, 1199, 1200, 1500]) resizeTo(width)

    expect(write).not.toHaveBeenCalled()
    expect(remove).not.toHaveBeenCalled()
    expect(localStorage.getItem(STORAGE_KEY)).toBe(before)
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')).not.toHaveProperty('nav')
  })

  it('ignores a stored preference below the rail breakpoint without clearing it', () => {
    setNavPreference('pinned')
    const stored = localStorage.getItem(STORAGE_KEY)
    installViewport(1300)
    const { result } = renderHook(() => useDrawerMode())
    expect(result.current.mode).toBe('pinned')

    resizeTo(600)
    expect(result.current).toEqual({ band: 'mobile', mode: 'sheet', source: 'viewport' })
    expect(localStorage.getItem(STORAGE_KEY)).toBe(stored)

    resizeTo(1300)
    expect(result.current).toMatchObject({ mode: 'pinned', source: 'operator' })
    expect(localStorage.getItem(STORAGE_KEY)).toBe(stored)
  })
})
