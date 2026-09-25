import type { ReactNode } from 'react'

import { Button } from './Button.tsx'

/** The primary submit button of a form: a `Button` that swaps its label while the request runs. */
export function SubmitButton({
  pending,
  pendingLabel,
  children,
}: {
  pending: boolean
  pendingLabel: string
  children: ReactNode
}) {
  return (
    <Button type="submit" variant="primary" pending={pending} pendingLabel={pendingLabel}>
      {children}
    </Button>
  )
}
