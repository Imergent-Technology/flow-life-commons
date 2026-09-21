import type { AccountStatus } from '../api/admin.ts'

const wording: Record<AccountStatus, { label: string; style: string }> = {
  invited: { label: 'Invited', style: 'border-amber-300 bg-amber-50 text-amber-900' },
  active: { label: 'Active', style: 'border-emerald-300 bg-emerald-50 text-emerald-900' },
  disabled: { label: 'Disabled', style: 'border-slate-400 bg-slate-100 text-slate-800' },
}

/** An Account's status, in words (never colour alone). */
export function StatusBadge({ status }: { status: AccountStatus }) {
  const { label, style } = wording[status]
  return (
    <span className={`inline-block rounded-full border px-2 py-0.5 text-xs font-medium ${style}`}>
      {label}
    </span>
  )
}
