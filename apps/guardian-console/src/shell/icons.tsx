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
  if (name === 'contacts') {
    return (
      <Svg>
        <rect x="3.5" y="5" width="17" height="14" rx="2" />
        <circle cx="9" cy="10.5" r="2" />
        <path d="M5.8 16c.4-1.6 1.6-2.4 3.2-2.4s2.8.8 3.2 2.4" />
        <path d="M14.5 10h3.5M14.5 13.5H18" />
      </Svg>
    )
  }
  if (name === 'resources') {
    return (
      <Svg>
        <path d="M5 5.5A1.5 1.5 0 0 1 6.5 4H19v13H6.5A1.5 1.5 0 0 0 5 18.5z" />
        <path d="M5 18.5A1.5 1.5 0 0 0 6.5 20H19v-3" />
        <path d="M9 8h6M9 11.5h4" />
      </Svg>
    )
  }
  if (name === 'discussions') {
    return (
      <Svg>
        <path d="M4.5 6.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H11l-4 3.5v-3.5H6.5a2 2 0 0 1-2-2z" />
        <path d="M8.5 8.5h7M8.5 11.5h4.5" />
      </Svg>
    )
  }
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
