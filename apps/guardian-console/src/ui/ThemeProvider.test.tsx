import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useEffect } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { clearNavPreference, setThemePreference } from './preferences.ts'
import { ThemeProvider } from './ThemeProvider.tsx'
import { useTheme } from './theme-context.ts'

/** A controllable stand-in for `matchMedia('(prefers-color-scheme: dark)')`. */
function fakeMediaQuery(initialMatches: boolean) {
  let matches = initialMatches
  const listeners = new Set<(event: { matches: boolean }) => void>()
  const mediaQueryList = {
    get matches() {
      return matches
    },
    addEventListener: (_type: string, listener: (event: { matches: boolean }) => void) => {
      listeners.add(listener)
    },
    removeEventListener: (_type: string, listener: (event: { matches: boolean }) => void) => {
      listeners.delete(listener)
    },
  }
  return {
    mediaQueryList,
    fire: (next: boolean) => {
      matches = next
      for (const listener of listeners) listener({ matches })
    },
    listenerCount: () => listeners.size,
  }
}

let mounts = 0

/** Reads the context and counts its own mounts, so a live update can be told apart from a remount. */
function Consumer() {
  const { preference, resolvedTheme, setTheme } = useTheme()
  useEffect(() => {
    mounts += 1
  }, [])
  return (
    <div>
      <span data-testid="preference">{preference}</span>
      <span data-testid="resolved">{resolvedTheme}</span>
      <button
        onClick={() => {
          setTheme('dark')
        }}
      >
        go dark
      </button>
    </div>
  )
}

beforeEach(() => {
  localStorage.clear()
  clearNavPreference()
  mounts = 0
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.documentElement.removeAttribute('data-theme')
  document.documentElement.style.colorScheme = ''
})

describe('resolving and stamping the theme', () => {
  it('an explicit light preference resolves to light and stamps the document', () => {
    setThemePreference('light')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(screen.getByTestId('resolved')).toHaveTextContent('light')
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(document.documentElement.style.colorScheme).toBe('light')
  })

  it('an explicit dark preference resolves to dark and stamps the document', () => {
    setThemePreference('dark')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(document.documentElement.style.colorScheme).toBe('dark')
  })

  it('system resolves to light when the OS prefers light', () => {
    const { mediaQueryList } = fakeMediaQuery(false)
    vi.stubGlobal('matchMedia', () => mediaQueryList)
    setThemePreference('system')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(screen.getByTestId('resolved')).toHaveTextContent('light')
    expect(document.documentElement.dataset.theme).toBe('light')
  })

  it('system resolves to dark when the OS prefers dark', () => {
    const { mediaQueryList } = fakeMediaQuery(true)
    vi.stubGlobal('matchMedia', () => mediaQueryList)
    setThemePreference('system')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
  })
})

describe('living with the OS', () => {
  it('System mode follows a later OS change live, without remounting', () => {
    const { mediaQueryList, fire } = fakeMediaQuery(false)
    vi.stubGlobal('matchMedia', () => mediaQueryList)
    setThemePreference('system')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(screen.getByTestId('resolved')).toHaveTextContent('light')
    expect(mounts).toBe(1)

    act(() => {
      fire(true)
    })

    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(mounts).toBe(1) // updated live, not by tearing the tree down and remounting it
  })

  it('an explicit preference does not react to a later OS change', () => {
    const { mediaQueryList, fire } = fakeMediaQuery(false)
    vi.stubGlobal('matchMedia', () => mediaQueryList)
    setThemePreference('dark')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )

    act(() => {
      fire(true) // the OS "changing" to prefer dark changes nothing: it was already dark, by choice
    })

    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('switching from System to an explicit preference stops reacting to the OS immediately', () => {
    const { mediaQueryList, fire } = fakeMediaQuery(false)
    vi.stubGlobal('matchMedia', () => mediaQueryList)
    setThemePreference('system')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(screen.getByTestId('resolved')).toHaveTextContent('light')

    act(() => {
      setThemePreference('light') // the same resolved value, but now an explicit choice
    })
    act(() => {
      fire(true)
    })

    expect(screen.getByTestId('resolved')).toHaveTextContent('light') // unaffected by the OS now
  })

  it('resolves System safely when matchMedia is unavailable, rather than crashing', () => {
    vi.stubGlobal('matchMedia', undefined)
    setThemePreference('system')
    expect(() => {
      render(
        <ThemeProvider>
          <Consumer />
        </ThemeProvider>,
      )
    }).not.toThrow()
    expect(screen.getByTestId('resolved')).toHaveTextContent(/^(light|dark)$/)
  })

  it('tears the OS subscription down on unmount', () => {
    const { mediaQueryList, listenerCount } = fakeMediaQuery(false)
    vi.stubGlobal('matchMedia', () => mediaQueryList)
    setThemePreference('system')
    const { unmount } = render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )
    expect(listenerCount()).toBe(1)
    unmount()
    expect(listenerCount()).toBe(0)
  })
})

describe('changing the preference from a consumer', () => {
  it('setTheme persists through the preferences module and updates the document immediately', async () => {
    const user = userEvent.setup()
    setThemePreference('light')
    render(
      <ThemeProvider>
        <Consumer />
      </ThemeProvider>,
    )

    await user.click(screen.getByRole('button', { name: 'go dark' }))

    expect(screen.getByTestId('resolved')).toHaveTextContent('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(JSON.parse(localStorage.getItem('flowlife.console.ui') ?? 'null')).toEqual({
      v: 1,
      theme: 'dark',
    })
  })
})
