import { useEffect, useRef } from 'react'

/**
 * The page's h1. It names the document and takes focus when the page appears, so a keyboard or
 * screen-reader user is told where they are after a client-side navigation (which the browser does not
 * announce on its own).
 */
export function PageHeading({ title, className = '' }: { title: string; className?: string }) {
  const ref = useRef<HTMLHeadingElement>(null)
  useEffect(() => {
    document.title = `${title} · Flow Life Guardian Console`
    ref.current?.focus()
  }, [title])

  return (
    <h1
      ref={ref}
      tabIndex={-1}
      className={`text-2xl font-semibold tracking-tight outline-none ${className}`}
    >
      {title}
    </h1>
  )
}
