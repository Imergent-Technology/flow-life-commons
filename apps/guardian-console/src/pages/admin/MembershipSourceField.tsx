import type { MembershipSource } from '../../api/membership.ts'
import { Field } from '../../ui/Field.tsx'
import { Select } from '../../ui/Select.tsx'

/** Where a grant came from: the two sources this Console's forms may choose (the server may know others). */
export function MembershipSourceField({
  value,
  onChange,
}: {
  value: MembershipSource
  onChange: (next: MembershipSource) => void
}) {
  return (
    <div className="w-full sm:w-64">
      <Field label="Source">
        {(control) => (
          <Select
            {...control}
            value={value}
            onChange={(event) => {
              onChange(event.target.value as MembershipSource)
            }}
          >
            <option value="operator">Operator</option>
            <option value="luma_legacy">Luma legacy</option>
          </Select>
        )}
      </Field>
    </div>
  )
}
