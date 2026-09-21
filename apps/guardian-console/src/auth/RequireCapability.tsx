import type { ReactNode } from 'react'

import { PageHeading } from '../ui/PageHeading.tsx'
import { useCurrentAccount } from './auth-context.ts'
import { hasCapability } from './capabilities.ts'

/**
 * A section that needs a capability. Someone without it sees a plain "not permitted" page inside the Console, not a
 * redirect. This decides what to PRESENT: the server refuses the section's requests on its own account, whatever this shows.
 */
export function RequireCapability({
  capability,
  children,
}: {
  capability: string
  children: ReactNode
}) {
  const current = useCurrentAccount()
  if (hasCapability(current, capability)) return children

  return (
    <div className="flex max-w-md flex-col gap-3">
      <PageHeading title="Not permitted" />
      <p className="text-slate-700">Your account cannot use this part of the Console.</p>
    </div>
  )
}
