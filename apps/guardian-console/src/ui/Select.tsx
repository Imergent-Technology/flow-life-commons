import type { ComponentProps } from 'react'

import { cn } from './cn.ts'
import { controlStyles } from './control-styles.ts'

/** A native `<select>`, with the browser's own list and keyboard behaviour. */
export function Select({ className, ...props }: ComponentProps<'select'>) {
  return <select {...props} className={cn(controlStyles, 'pr-8', className)} />
}
