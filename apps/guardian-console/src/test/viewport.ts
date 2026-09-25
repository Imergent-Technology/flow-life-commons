import { act } from '@testing-library/react'
import { vi } from 'vitest'

interface Query {
  matches: () => boolean
  listeners: Set<() => void>
  last: boolean
}

let width = 1280
const queries = new Map<string, Query>()

function evaluate(text: string): boolean {
  const min = /^\(min-width:\s*(\d+)px\)$/.exec(text)
  if (min?.[1] !== undefined) return width >= Number(min[1])
  return false // dark colour scheme, coarse pointers, reduced motion: none of them, unless a test says otherwise
}

/**
 * Installs a `matchMedia` that answers the shell's `min-width` queries for a chosen viewport width, and tells
 * listeners when a query flips. jsdom has none. Call `vi.unstubAllGlobals()` after.
 */
export function installViewport(initialWidth: number): void {
  width = initialWidth
  queries.clear()
  vi.stubGlobal('matchMedia', (text: string) => {
    let query = queries.get(text)
    if (query === undefined) {
      const created: Query = {
        matches: () => evaluate(text),
        listeners: new Set(),
        last: evaluate(text),
      }
      queries.set(text, created)
      query = created
    }
    const shared = query
    return {
      get matches() {
        return shared.matches()
      },
      media: text,
      addEventListener: (_type: string, listener: () => void) => {
        shared.listeners.add(listener)
      },
      removeEventListener: (_type: string, listener: () => void) => {
        shared.listeners.delete(listener)
      },
    }
  })
}

/** Resizes the window: every query that flips notifies its listeners, as a browser would. */
export function resizeTo(next: number): void {
  // Let pending effects run first, so every component has subscribed: a change that lands before then is
  // only noticed a tick later, which is right in a browser and a race in an assertion that follows at once.
  act(() => undefined)
  width = next
  act(() => {
    for (const [text, query] of queries) {
      const now = evaluate(text)
      if (now === query.last) continue
      query.last = now
      for (const listener of query.listeners) listener()
    }
  })
}
