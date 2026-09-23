import { useId } from 'react'

import type { MembershipTermState } from '../../admin/membershipTerm.ts'
import type { FieldErrors } from '../../api/membership.ts'

/**
 * The two fields every Membership grant needs: when it starts, and when (or whether) it ends. Open-ended access is an
 * unchecked box by default (ADR 0028: never the accidental result of leaving the end date blank), and checking it hides
 * the end field rather than leaving a disabled one behind that could be confused for a value.
 */
export function MembershipTermFields({
  value,
  onChange,
  errors = {},
  disabled = false,
}: {
  value: MembershipTermState
  onChange: (next: MembershipTermState) => void
  errors?: FieldErrors | undefined
  disabled?: boolean
}) {
  const startsId = useId()
  const endsId = useId()
  const openEndedId = useId()
  const startErrors = errors.starts_at
  const endErrors = errors.ends_at

  return (
    <>
      <div className="flex flex-col gap-1">
        <label htmlFor={startsId} className="text-sm font-medium text-slate-800">
          Starts at
        </label>
        <input
          id={startsId}
          type="datetime-local"
          required
          disabled={disabled}
          value={value.startsAt}
          onChange={(event) => {
            onChange({ ...value, startsAt: event.target.value })
          }}
          aria-invalid={startErrors !== undefined && startErrors.length > 0}
          className="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900 disabled:bg-slate-100 aria-invalid:border-red-600"
        />
        {startErrors !== undefined && startErrors.length > 0 ? (
          <p className="text-sm text-red-700">{startErrors.join(' ')}</p>
        ) : null}
      </div>

      <label htmlFor={openEndedId} className="flex items-center gap-2 text-sm text-slate-800">
        <input
          id={openEndedId}
          type="checkbox"
          disabled={disabled}
          checked={value.openEnded}
          onChange={(event) => {
            onChange({ ...value, openEnded: event.target.checked })
          }}
        />
        Open-ended access (no end date)
      </label>

      {value.openEnded ? (
        <p className="text-sm text-slate-600">This access will not expire on its own.</p>
      ) : (
        <div className="flex flex-col gap-1">
          <label htmlFor={endsId} className="text-sm font-medium text-slate-800">
            Ends at
          </label>
          <input
            id={endsId}
            type="datetime-local"
            required
            disabled={disabled}
            value={value.endsAt}
            onChange={(event) => {
              onChange({ ...value, endsAt: event.target.value })
            }}
            aria-invalid={endErrors !== undefined && endErrors.length > 0}
            className="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900 disabled:bg-slate-100 aria-invalid:border-red-600"
          />
          {endErrors !== undefined && endErrors.length > 0 ? (
            <p className="text-sm text-red-700">{endErrors.join(' ')}</p>
          ) : null}
        </div>
      )}
    </>
  )
}
