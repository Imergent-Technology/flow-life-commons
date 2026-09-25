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

/** Two lines, the first dominant: the product, then this interface onto it. The software is not called "Sanctuary". */
export function Wordmark() {
  return (
    <div className="flex min-w-0 flex-col leading-tight">
      <span className="truncate font-display text-section font-medium text-foreground">
        Flow Life Commons
      </span>
      <span className="truncate text-meta text-muted-foreground">Guardian Console</span>
    </div>
  )
}
