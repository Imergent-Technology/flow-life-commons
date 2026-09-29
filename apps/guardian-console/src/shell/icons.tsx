import type { ReactNode } from 'react'

import type { IconName } from './navigation.ts'

/** Small inline glyphs, decorative: every control that uses one also carries its words. */
function Svg({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className ?? 'size-5 shrink-0'}
    >
      {children}
    </svg>
  )
}

export function SectionIcon({ name }: { name: IconName }) {
  return name === 'home' ? (
    <Svg>
      <path d="M4 11 12 4l8 7" />
      <path d="M6 10v9h12v-9" />
      <path d="M10 19v-5h4v5" />
    </Svg>
  ) : (
    <Svg>
      <circle cx="9" cy="8.5" r="3" />
      <path d="M3.5 19c.4-3 2.4-4.5 5.5-4.5s5.1 1.5 5.5 4.5" />
      <path d="M16 6.2a3 3 0 0 1 0 5.6" />
      <path d="M17.5 14.7c1.7.5 2.8 1.9 3 4.3" />
    </Svg>
  )
}

export function MenuIcon() {
  return (
    <Svg>
      <path d="M4 7h16M4 12h16M4 17h16" />
    </Svg>
  )
}

export function CloseIcon() {
  return (
    <Svg>
      <path d="m6 6 12 12M18 6 6 18" />
    </Svg>
  )
}

export function PinIcon() {
  return (
    <Svg className="size-4 shrink-0">
      <path d="m15 4 5 5-3 1-3.5 3.5.5 4-1 1-3.5-3.5L5 19" />
      <path d="m9 8.5 3-1" />
    </Svg>
  )
}
