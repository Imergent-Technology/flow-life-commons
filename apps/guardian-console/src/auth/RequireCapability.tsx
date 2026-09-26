import type { ReactNode } from 'react'

import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
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
    <Page width="prose">
      <PageHeader
        title="Not permitted"
        description="Your account cannot use this part of the Console."
      />
    </Page>
  )
}
