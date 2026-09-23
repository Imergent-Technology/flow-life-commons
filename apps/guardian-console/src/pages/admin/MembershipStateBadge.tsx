/** A membership record's derived state, in words (never colour alone). */
export function MembershipStateBadge({ active }: { active: boolean }) {
  return (
    <span
      className={`inline-block rounded-full border px-2 py-0.5 text-xs font-medium ${
        active
          ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
          : 'border-slate-400 bg-slate-100 text-slate-800'
      }`}
    >
      {active ? 'Active' : 'Inactive'}
    </span>
  )
}
