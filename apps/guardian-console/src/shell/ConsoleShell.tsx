import { useCallback, useMemo, useState } from 'react'
import { Outlet, useLocation } from 'react-router'

import { useCurrentAccount } from '../auth/auth-context.ts'
import { hasCapability } from '../auth/capabilities.ts'
import { StepUpProvider } from '../auth/StepUpProvider.tsx'
import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import { useNavPreference } from '../ui/useNavPreference.ts'
import { useSignOut } from '../ui/useSignOut.ts'
import { AccountMenu } from './AccountMenu.tsx'
import { BreadcrumbLeafContext } from './breadcrumb-leaf.ts'
import { Breadcrumbs } from './Breadcrumbs.tsx'
import { Badge, Wordmark } from './Brand.tsx'
import { DrawerPanel } from './DrawerPanel.tsx'
import { useDrawerMode } from './drawer-mode.ts'
import { MenuIcon } from './icons.tsx'
import { NavSheet } from './NavSheet.tsx'
import { breadcrumbs, locate, navigation, visibleNavigation } from './navigation.ts'
import { OverlayDrawer } from './OverlayDrawer.tsx'
import { Rail } from './Rail.tsx'

const gutter = 'px-[clamp(1rem,2.5vw,2rem)]'
const MAIN_ID = 'main-content'

/**
 * The signed-in frame (ADR 0030, design spec S6): an attached rail with a secondary drawer above the rail
 * breakpoint, a top bar and modal sheet below it. Everything navigational is derived from `navigation`, filtered
 * by the operator's capabilities.
 *
 * Width and scroll: the shell imposes no maximum width on the page (a page declares its own), and the DOCUMENT
 * scrolls. The rail and a pinned drawer are sticky columns, so browser scroll restoration, find-in-page and focus
 * behave as they always have. The top bar scrolls away with the page.
 *
 * Preferences: this reads the stored drawer choice and never writes it. Crossing a breakpoint only re-derives the
 * layout; only the pin controls store a choice.
 */
export function ConsoleShell() {
  const current = useCurrentAccount()
  const { pathname } = useLocation()
  const { band, mode } = useDrawerMode()
  const { setPinned, setOverlay } = useNavPreference()
  const signOut = useSignOut()

  const sections = useMemo(
    () => visibleNavigation(navigation, (capability) => hasCapability(current, capability)),
    [current],
  )
  const where = locate(sections, pathname)
  const [leaf, setLeaf] = useState<string | undefined>(undefined)
  const crumbs = breadcrumbs(where, leaf)

  const [sheetOpen, setSheetOpen] = useState(false)
  const [overlayId, setOverlayId] = useState<string | null>(null)

  // Derived, not synchronised in an effect: a route change, or a viewport that no longer has that surface,
  // ends it. (React's own pattern for state that follows other state.)
  const [seenPath, setSeenPath] = useState(pathname)
  if (seenPath !== pathname) {
    setSeenPath(pathname)
    setSheetOpen(false)
    setOverlayId(null)
  }
  if (mode !== 'sheet' && sheetOpen) setSheetOpen(false)
  if (mode !== 'overlay' && overlayId !== null) setOverlayId(null)

  const closeOverlay = useCallback(() => {
    setOverlayId(null)
  }, [])
  const closeSheet = useCallback(() => {
    setSheetOpen(false)
  }, [])

  const drawerSection =
    mode === 'pinned' && where?.section.groups !== undefined ? where.section : undefined
  const overlaySection = sections.find((section) => section.id === overlayId)

  const account = <AccountMenu current={current} compact={band === 'mobile'} signOut={signOut} />

  const content = (
    <main id={MAIN_ID} tabIndex={-1} className={`min-w-0 flex-1 pt-2 pb-10 outline-none ${gutter}`}>
      {signOut.error ? (
        <div className="mb-4">
          <Alert key={signOut.error.attempt} tone="error" focusOnMount>
            {signOut.error.message}
          </Alert>
        </div>
      ) : null}
      <StepUpProvider>
        <Outlet />
      </StepUpProvider>
    </main>
  )

  const skipLink = (
    <a
      href={`#${MAIN_ID}`}
      onClick={(event) => {
        event.preventDefault()
        document.getElementById(MAIN_ID)?.focus()
      }}
      className="sr-only rounded-sm bg-surface-raised px-3 py-2 text-label font-medium text-foreground shadow-pop focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50"
    >
      Skip to main content
    </a>
  )

  return (
    <BreadcrumbLeafContext value={setLeaf}>
      {skipLink}
      {mode === 'sheet' ? (
        <div className="flex min-h-dvh flex-col">
          <header className={`flex h-(--shell-topbar) shrink-0 items-center gap-3 ${gutter}`}>
            <Button
              variant="ghost"
              aria-haspopup="dialog"
              aria-expanded={sheetOpen}
              aria-label="Navigation menu"
              onClick={() => {
                setSheetOpen(true)
              }}
              className="w-(--control-md) px-0"
            >
              <MenuIcon />
            </Button>
            <Badge className="size-(--logo-mobile)" />
            <div className="hidden min-w-0 min-[420px]:block">
              <Wordmark />
            </div>
            <div className="ml-auto">{account}</div>
          </header>
          {crumbs.length > 0 ? (
            // The same trail the desktop top bar shows, below the bar where a phone has room for it: the way back
            // up from a detail or form page, which the navigation sheet alone would make a menu away.
            <div className={`pb-2 ${gutter}`}>
              <Breadcrumbs crumbs={crumbs} />
            </div>
          ) : null}
          {content}
          {sheetOpen ? (
            <NavSheet
              sections={sections}
              location={where}

              onClose={closeSheet}
            />
          ) : null}
        </div>
      ) : (
        <div className="flex min-h-dvh">
          <Rail
            sections={sections}
            currentSectionId={where?.section.id}
            drawer={mode}
            openSectionId={overlayId}
            onToggle={(id) => {
              setOverlayId((open) => (open === id ? null : id))
            }}
          />
          {overlaySection === undefined ? null : (
            <OverlayDrawer
              section={overlaySection}
              location={where}
              onNavigate={closeOverlay}
              onPinChange={setPinned}
              onClose={closeOverlay}
            />
          )}
          {drawerSection === undefined ? null : (
            <DrawerPanel
              section={drawerSection}
              location={where}
              variant="pinned"
              onNavigate={closeOverlay}
              onPinChange={setOverlay}
            />
          )}
          <div className="flex min-w-0 flex-1 flex-col">
            <header
              className={`flex h-(--shell-topbar) shrink-0 items-center justify-between gap-4 ${gutter}`}
            >
              <Breadcrumbs crumbs={crumbs} />
              <div className="ml-auto">{account}</div>
            </header>
            {content}
          </div>
        </div>
      )}
    </BreadcrumbLeafContext>
  )
}
