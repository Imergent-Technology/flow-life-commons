import { clsx, type ClassValue } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

/**
 * Stock tailwind-merge does not know this project's own scales (theme/tokens.css), and guesses wrongly:
 * it reads `text-meta` as a colour, so `cn('text-meta', 'text-foreground')` would silently drop the size.
 * Registering the names here is what keeps a caller's `className` able to override a primitive's default.
 */
const twMerge = extendTailwindMerge({
  extend: {
    theme: {
      text: ['title', 'dialog-title', 'section', 'body', 'label', 'meta'],
      radius: ['pill'],
      shadow: ['panel', 'pop'],
      container: ['page-prose', 'page-form', 'page-detail'],
    },
  },
})

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs))
}

/** The focus ring every interactive primitive shares: 2px, offset, in the theme's `ring` colour. */
export const focusRing =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
