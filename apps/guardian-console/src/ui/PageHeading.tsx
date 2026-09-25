import { usePageHeading } from './usePageHeading.ts'

/**
 * The page's h1, as the pages use it today. Kept until they move to `PageHeader` (WP5); both share
 * `usePageHeading`, so the focus and document-title behaviour is the same.
 */
export function PageHeading({ title, className = '' }: { title: string; className?: string }) {
  const ref = usePageHeading(title)

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
