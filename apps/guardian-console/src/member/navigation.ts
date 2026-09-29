/**
 * The one definition of where a Member can go. There is no capability filtering here (unlike Guardian's
 * `navigation.ts`): every `/my/*` destination is available to any authenticated Account, so there is
 * nothing to derive from capabilities yet — see docs/architecture/member-access.md on why a Member-only
 * capability is not invented here speculatively.
 */
export interface MemberNavItem {
  label: string
  to: string
}

export const memberNavigation: readonly MemberNavItem[] = [
  { label: 'Home', to: '/my' },
  { label: 'Membership', to: '/my/membership' },
  { label: 'Security', to: '/my/security' },
]
