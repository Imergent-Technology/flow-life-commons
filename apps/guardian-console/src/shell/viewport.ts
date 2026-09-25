import { useSyncExternalStore } from 'react'

/**
 * The shell's two breakpoints (design spec S6), kept in step by hand with `--bp-rail` and `--bp-pin` in
 * theme/tokens.css: CSS cannot interpolate a variable into a media query. The principle they serve is fixed
 * (pin only when enough workspace remains); `--bp-pin` is the one QA may tune.
 */
export const RAIL_QUERY = '(min-width: 1024px)'
export const PIN_QUERY = '(min-width: 1200px)'

/** below the rail breakpoint: mobile bar + modal sheet · rail: desktop rail, drawer overlays · wide: drawer pins. */
export type Band = 'mobile' | 'rail' | 'wide'

function readBand(): Band {
  try {
    if (!matchMedia(RAIL_QUERY).matches) return 'mobile'
    return matchMedia(PIN_QUERY).matches ? 'wide' : 'rail'
  } catch {
    // No matchMedia (an old browser, or a bare test environment): the desktop shell, the safest to render.
    return 'wide'
  }
}

function subscribe(onChange: () => void): () => void {
  try {
    const queries = [matchMedia(RAIL_QUERY), matchMedia(PIN_QUERY)]
    for (const query of queries) query.addEventListener('change', onChange)
    return () => {
      for (const query of queries) query.removeEventListener('change', onChange)
    }
  } catch {
    return () => undefined
  }
}

/** The viewport band, live. Crossing a breakpoint re-derives the layout and writes nothing. */
export function useViewportBand(): Band {
  return useSyncExternalStore(subscribe, readBand)
}
