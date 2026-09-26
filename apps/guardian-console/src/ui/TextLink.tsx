import type { ComponentProps } from 'react'
import { Link } from 'react-router'

import { cn, focusRing } from './cn.ts'

/** How every in-text link looks: the action colour, underlined, so it is never told from text by colour alone. */
const textLinkStyles = cn(
  'rounded-xs text-primary underline underline-offset-2 hover:text-primary-hover',
  focusRing,
)

/** A router link in running text or a footer. A link that should look like a button uses `buttonVariants` instead. */
export function TextLink({ className, ...props }: ComponentProps<typeof Link>) {
  return <Link {...props} className={cn(textLinkStyles, className)} />
}
