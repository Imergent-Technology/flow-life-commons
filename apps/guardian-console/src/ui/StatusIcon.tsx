/** Small inline glyphs, so a message carries a shape as well as a colour. Decorative: the words carry the meaning. */
export type StatusIconKind = 'error' | 'success' | 'warning' | 'info'

const paths: Record<StatusIconKind, string> = {
  error: 'M12 7.5v5.5M12 16.5h.01',
  success: 'M8 12.5l3 3 5-6',
  warning: 'M12 8v4.5M12 16h.01',
  info: 'M12 11v5.5M12 7.5h.01',
}

export function StatusIcon({ kind, className }: { kind: StatusIconKind; className?: string }) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className ?? 'size-4 shrink-0'}
    >
      {kind === 'warning' ? (
        <path d="M12 3.5 22 20.5H2L12 3.5Z" />
      ) : kind === 'success' ? (
        <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z" />
      ) : (
        <circle cx="12" cy="12" r="9" />
      )}
      <path d={paths[kind]} />
    </svg>
  )
}
