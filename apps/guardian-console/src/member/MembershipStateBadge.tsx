import { Badge } from '../ui/Badge.tsx'

/**
 * Whether membership is currently active, in words and a shape (never colour alone). A small local
 * component, not a reuse of the admin `MembershipStateBadge`: the same idea, but this surface must not
 * import anything from `pages/admin` (see `import-boundary.test.ts`).
 */
export function MembershipStateBadge({ active }: { active: boolean }) {
  return active ? (
    <Badge variant="success">Active</Badge>
  ) : (
    <Badge variant="neutral">Inactive</Badge>
  )
}
