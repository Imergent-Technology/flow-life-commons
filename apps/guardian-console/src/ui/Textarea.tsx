import type { ComponentProps } from 'react'

import { cn } from './cn.ts'
import { controlStyles } from './control-styles.ts'

/** A native `<textarea>` in the shared control look: several lines tall, resizable downward only. */
export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
  return (
    <textarea
      {...props}
      className={cn(controlStyles, 'min-h-24 resize-y py-2 leading-normal', className)}
    />
  )
}
