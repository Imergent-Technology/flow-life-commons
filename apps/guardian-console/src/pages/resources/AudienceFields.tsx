import { audienceLabel, MEMBER_DELIVERY_NOTE } from '../../admin/resourcesWording.ts'
import { AUDIENCES, type Audience } from '../../api/resources.ts'
import { Checkbox } from '../../ui/Checkbox.tsx'

/**
 * A group of audience checkboxes. A Pack's audiences are combined by OR: anyone who is in any of them is in. `options` is
 * every audience that may be chosen here (all of them for a Pack; only the Pack's own for a narrowed Card), and the server holds
 * the rules either way. Choosing Members carries a plain note that nothing delivers to Members yet.
 */
export function AudienceFields({
  legend,
  options = AUDIENCES,
  value,
  onChange,
  disabled = false,
  error,
}: {
  legend: string
  options?: readonly Audience[]
  value: readonly Audience[]
  onChange: (next: Audience[]) => void
  disabled?: boolean
  error?: string | undefined
}) {
  const showNote = options.includes('member')
  return (
    <fieldset className="flex flex-col gap-2" disabled={disabled}>
      <legend className="text-label font-medium text-foreground">{legend}</legend>
      {options.map((audience) => (
        <Checkbox
          key={audience}
          label={audienceLabel(audience)}
          checked={value.includes(audience)}
          onChange={(event) => {
            const next = event.target.checked
              ? [...value, audience]
              : value.filter((chosen) => chosen !== audience)
            onChange(AUDIENCES.filter((candidate) => next.includes(candidate)))
          }}
        />
      ))}
      {showNote ? <p className="text-meta text-muted-foreground">{MEMBER_DELIVERY_NOTE}</p> : null}
      {error !== undefined ? (
        <p role="alert" className="text-label text-danger">
          {error}
        </p>
      ) : null}
    </fieldset>
  )
}
