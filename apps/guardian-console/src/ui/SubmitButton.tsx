import type { ReactNode } from 'react'

import { Button } from './Button.tsx'
import type { ButtonVariants } from './button-variants.ts'

/** The primary submit button of a form: a `Button` that swaps its label while the request runs. */
export function SubmitButton({
  pending,
  pendingLabel,
  size,
  fullWidth = false,
  children,
}: {
  pending: boolean
  pendingLabel: string
  size?: ButtonVariants['size']
  fullWidth?: boolean
  children: ReactNode
}) {
  return (
    <Button
      type="submit"
      variant="primary"
      size={size}
      pending={pending}
      pendingLabel={pendingLabel}
      className={fullWidth ? 'w-full' : 'self-start'}
    >
      {children}
    </Button>
  )
}
