import type { NavPreference } from '../ui/preferences.ts'
import { useNavPreference } from '../ui/useNavPreference.ts'
import { useViewportBand, type Band } from './viewport.ts'

export type DrawerMode = 'sheet' | 'pinned' | 'overlay'

export interface ResolvedDrawer {
  mode: DrawerMode
  /** `operator` when the mode is their explicit choice; `viewport` when the width decided. */
  source: 'operator' | 'viewport'
}

/**
 * Pin only when enough workspace remains. Below the rail breakpoint the modal sheet wins outright and the
 * stored preference is not consulted (it is neither used nor cleared). Above it, an explicit choice is honoured
 * at every width; absent one, the viewport decides. Pure: it reads and writes no storage.
 */
export function resolveDrawer(band: Band, nav: NavPreference | undefined): ResolvedDrawer {
  if (band === 'mobile') return { mode: 'sheet', source: 'viewport' }
  if (nav !== undefined) return { mode: nav, source: 'operator' }
  return { mode: band === 'wide' ? 'pinned' : 'overlay', source: 'viewport' }
}

export function useDrawerMode(): ResolvedDrawer & { band: Band } {
  const band = useViewportBand()
  const { nav } = useNavPreference()
  return { band, ...resolveDrawer(band, nav) }
}
