import { badgeUrl } from './brand.ts'

/** The Flow Life badge. Decorative where a wordmark sits beside it; `label` names it where it stands alone. */
export function Badge({ className, label }: { className: string; label?: string }) {
  return (
    <img
      src={badgeUrl}
      alt={label ?? ''}
      decoding="async"
      className={`${className} shrink-0 rounded-pill`}
    />
  )
}

/**
 * The product name, and optionally a second, smaller line naming the interface onto it (the software is
 * not called "Sanctuary"). The shared credential surfaces (sign-in, invitation, password reset — anything
 * inside `AuthLayout`) pass no `subtitle`: a Member and a Guardian both reach those pages, so neither may
 * claim to be a surface the visitor might not be entering. The signed-in Guardian Console shell passes
 * `subtitle="Guardian Console"`, since by the time it renders that is genuinely where the person is.
 */
export function Wordmark({ subtitle }: { subtitle?: string } = {}) {
  return (
    <div className="flex min-w-0 flex-col leading-tight">
      <span className="truncate font-display text-section font-medium text-foreground">
        Flow Life Commons
      </span>
      {subtitle !== undefined ? (
        <span className="truncate text-meta text-muted-foreground">{subtitle}</span>
      ) : null}
    </div>
  )
}
