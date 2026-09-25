import { Badge } from '../../ui/Badge.tsx'

/** A membership record's derived state, in words and a shape (never colour alone). */
export function MembershipStateBadge({ active }: { active: boolean }) {
  return active ? (
    <Badge variant="success">Active</Badge>
  ) : (
    <Badge variant="neutral">Inactive</Badge>
  )
}
