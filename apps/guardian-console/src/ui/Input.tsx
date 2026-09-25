import type { ComponentProps } from 'react'

import { cn } from './cn.ts'
import { controlStyles } from './control-styles.ts'

/** A native `<input>`: every attribute passes straight through, so credential and autocomplete hints stay the caller's. */
export function Input({ className, ...props }: ComponentProps<'input'>) {
  return <input {...props} className={cn(controlStyles, className)} />
}
