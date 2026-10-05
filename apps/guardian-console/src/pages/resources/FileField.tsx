import { FILE_ACCEPT, FILE_HELP } from '../../admin/resourcesWording.ts'
import { cn } from '../../ui/cn.ts'
import { controlStyles } from '../../ui/control-styles.ts'
import { Field } from '../../ui/Field.tsx'

/**
 * Choosing a file for a File Card. The `accept` list only narrows what the file picker offers; it is a convenience and not a
 * check. The server decides what the file really is (from its content, whatever its name or the browser says) and how big it may be.
 */
export function FileField({
  label,
  onChange,
  error,
  required = false,
  inputKey,
}: {
  label: string
  onChange: (file: File | null) => void
  error?: string | undefined
  required?: boolean
  /** Changing it clears the chosen file. */
  inputKey?: string | number
}) {
  return (
    <Field label={label} hint={FILE_HELP} error={error}>
      {(control) => (
        <input
          {...control}
          key={inputKey}
          type="file"
          name="file"
          accept={FILE_ACCEPT}
          // Not the native `required`: the browser's own bubble is not announced the way the Console's message is, and the
          // Console says what is missing itself (beside this input, with the field marked invalid).
          aria-required={required ? true : undefined}
          onChange={(event) => {
            onChange(event.target.files?.item(0) ?? null)
          }}
          className={cn(
            controlStyles,
            'h-auto py-2 file:mr-3 file:rounded-sm file:border file:border-border-strong file:bg-surface file:px-2.5 file:py-1 file:text-label file:font-medium file:text-foreground',
          )}
        />
      )}
    </Field>
  )
}
