import type { MembershipTermState } from '../../admin/membershipTerm.ts'
import type { FieldErrors } from '../../api/membership.ts'
import { Checkbox } from '../../ui/Checkbox.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'

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
  const startErrors = errors.starts_at
  const endErrors = errors.ends_at

  return (
    <>
      <div className="w-full sm:w-64">
        <Field
          label="Starts at"
          error={
            startErrors !== undefined && startErrors.length > 0 ? startErrors.join(' ') : undefined
          }
        >
          {(control) => (
            <Input
              {...control}
              type="datetime-local"
              required
              disabled={disabled}
              value={value.startsAt}
              onChange={(event) => {
                onChange({ ...value, startsAt: event.target.value })
              }}
            />
          )}
        </Field>
      </div>

      <Checkbox
        label="Open-ended access (no end date)"
        disabled={disabled}
        checked={value.openEnded}
        onChange={(event) => {
          onChange({ ...value, openEnded: event.target.checked })
        }}
      />

      {value.openEnded ? (
        <p className="text-label text-muted-foreground">This access will not expire on its own.</p>
      ) : (
        <div className="w-full sm:w-64">
          <Field
            label="Ends at"
            error={
              endErrors !== undefined && endErrors.length > 0 ? endErrors.join(' ') : undefined
            }
          >
            {(control) => (
              <Input
                {...control}
                type="datetime-local"
                required
                disabled={disabled}
                value={value.endsAt}
                onChange={(event) => {
                  onChange({ ...value, endsAt: event.target.value })
                }}
              />
            )}
          </Field>
        </div>
      )}
    </>
  )
}
