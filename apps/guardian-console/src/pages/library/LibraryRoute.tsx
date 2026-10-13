import { useCallback, useEffect, useMemo, useState } from 'react'
import { Outlet } from 'react-router'

import { useAuth, useCurrentAccount } from '../../auth/auth-context.ts'
import { hasCapability, RESOURCES_VIEW } from '../../auth/capabilities.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import {
  LibraryModeContext,
  modeFor,
  type LibraryMode,
  type LibraryModeValue,
} from './libraryMode.ts'

type Phase =
  | { kind: 'ready' }
  /** A request was refused. `/me` is being asked again, and nothing of either reader is on screen. */
  | { kind: 'checking'; from: LibraryMode }
  /** `/me` has answered: what it says decides what comes next. */
  | { kind: 'asked'; from: LibraryMode }

/**
 * The layout of `/resource-library` and `/resource-library/:packId` (ADR 0038, C1, C7). Everyone admitted to the Console reaches it. It
 * chooses the reader, keeps the two apart, and follows the platform when it takes the viewing capability away.
 *
 * WHICH reader: decided once, on entry, from the latest `/me` (`resources.view` or not). Not from an address, a preference, a role, a
 * relationship or the management capability, and never raised while the library is open: a capability gained meanwhile is picked up
 * on the next entry. The two readers are separate components with separate clients and separate state; this never hands one the
 * other's data.
 *
 * LOSING the capability: `/me` is a snapshot and the platform checks the capability on every request, so a viewer can find itself
 * refused (403) while the snapshot still says it may. Then, in this order:
 *   1. the route stops drawing the viewer at once (it is unmounted, which also cancels its requests and drops its answers, filters
 *      and everything it had loaded), and shows nothing but a neutral "checking";
 *   2. `/me` is asked again through the same provider as everywhere else, and nothing is decided until it has answered;
 *   3. if the Console still admits the person and `resources.view` is gone, the audience reader opens at the same address, and a Pack
 *      deep link is resolved again by policy C, where a Draft or ineligible Pack is simply "not found";
 *   4. if `/me` still says the capability is held, the platform and the snapshot disagree and neither is believed: the person is told
 *      their access could not be confirmed and asked to try again. Nothing loops, and nothing privileged is shown meanwhile;
 *   5. if `/me` cannot be answered, the provider replaces the whole Console with its "unavailable" screen, which removes this too;
 *   6. if the Console no longer admits the person, `RequireConsoleAccess` above this refuses the Console and this is not drawn.
 * A 403 from the audience reader means Console admission is gone, never that a Resource is ineligible (that is a 404); it is followed
 * the same way, and never turns into the viewer.
 */
export function LibraryRoute() {
  const current = useCurrentAccount()
  const { refresh } = useAuth()
  const [mode, setMode] = useState<LibraryMode>(() => modeFor(current))
  const [phase, setPhase] = useState<Phase>({ kind: 'ready' })
  // Bumped by "Try again": the reader below is drawn afresh and asks again.
  const [round, setRound] = useState(0)
  // A refusal while one is already being settled is the same refusal: only the first starts the check.
  const forbidden = useCallback((from: LibraryMode) => {
    setPhase((previous) => (previous.kind === 'ready' ? { kind: 'checking', from } : previous))
  }, [])

  // Asking `/me` again is the effect of being in the "checking" phase. Nothing is decided until it has answered, and an answer for a
  // check that has since been left (the person went elsewhere) is dropped.
  useEffect(() => {
    if (phase.kind !== 'checking') return
    let live = true
    const { from } = phase
    void refresh().then(() => {
      if (live) setPhase({ kind: 'asked', from })
    })
    return () => {
      live = false
    }
  }, [phase, refresh])

  // The answer is in: the viewer that was refused is replaced by the audience reader if the capability is truly gone. This is derived
  // while rendering, not synchronised in an effect, so the viewer is never drawn again in between.
  const viewerNow = hasCapability(current, RESOURCES_VIEW)
  const downgrade = phase.kind === 'asked' && phase.from === 'viewer' && !viewerNow
  if (downgrade) {
    setMode('audience')
    setPhase({ kind: 'ready' })
  }

  const value = useMemo<LibraryModeValue>(() => ({ mode, forbidden }), [mode, forbidden])

  if (phase.kind === 'ready') {
    return (
      <LibraryModeContext value={value}>
        <Outlet key={`${mode}:${String(round)}`} />
      </LibraryModeContext>
    )
  }

  // Not ready, and not a downgrade that is about to open the audience reader: either still asking, or the answer did not settle it.
  if (phase.kind === 'asked' && !downgrade) {
    return (
      <Page width="prose">
        <PageHeader title="Resource Library" />
        <Alert tone="warning" focusOnMount>
          Your access to the Resource Library could not be confirmed just now. Nothing is shown
          until it is.
        </Alert>
        <Button
          className="self-start"
          onClick={() => {
            setPhase({ kind: 'ready' })
            setRound((n) => n + 1)
          }}
        >
          Try again
        </Button>
      </Page>
    )
  }

  return (
    <Page width="prose">
      <PageHeader title="Resource Library" />
      <SkeletonRegion label="Checking your access to Resources…" visibleLabel>
        <SkeletonText lines={3} />
      </SkeletonRegion>
    </Page>
  )
}
