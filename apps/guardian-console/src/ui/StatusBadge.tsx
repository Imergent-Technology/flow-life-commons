import type { AccountStatus } from '../api/admin.ts'
import { Badge } from './Badge.tsx'

const wording: Record<
  AccountStatus,
  { label: string; variant: 'warning' | 'success' | 'neutral' }
> = {
  invited: { label: 'Invited', variant: 'warning' },
  active: { label: 'Active', variant: 'success' },
  disabled: { label: 'Disabled', variant: 'neutral' },
}

/** An Account's status, in words and a shape (never colour alone). */
export function StatusBadge({ status }: { status: AccountStatus }) {
  const { label, variant } = wording[status]
  return <Badge variant={variant}>{label}</Badge>
}
