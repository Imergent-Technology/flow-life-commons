import { useEffect, useRef } from 'react'

/**
 * The behaviour every page h1 shares: it names the document and takes focus when the page appears, so a
 * keyboard or screen-reader user is told where they are after a client-side navigation (which the browser
 * does not announce on its own). One place, so `PageHeading` and `PageHeader` cannot drift apart.
 */
export function usePageHeading(title: string) {
  const ref = useRef<HTMLHeadingElement>(null)
  useEffect(() => {
    document.title = `${title} · Flow Life Commons`
    ref.current?.focus()
  }, [title])
  return ref
}
