import { useId } from 'react'

import type { SummaryMode } from '../../api/resources.ts'
import { Field } from '../../ui/Field.tsx'
import { Textarea } from '../../ui/Textarea.tsx'
import { CARD_SUMMARY_MAX } from './cardLimits.ts'

/**
 * How a Card's summary is made (ADR 0037, decisions 34-37): automatically from its content, or written by hand. The automatic
 * one is the SERVER's, worked out when the content is saved, so what it will say is not guessed here: the Card's current
 * summary is shown as it was last saved. A summary written by hand is never overwritten by later edits to the content.
 */
export function SummaryField({
  mode,
  onModeChange,
  text,
  onTextChange,
  saved,
  error,
}: {
  mode: SummaryMode
  onModeChange: (mode: SummaryMode) => void
  text: string
  onTextChange: (text: string) => void
  /** The summary as last saved, if there is one yet. */
  saved?: string | undefined
  error?: string | undefined
}) {
  const name = useId()
  return (
    <div className="flex flex-col gap-2">
      <fieldset className="flex flex-col gap-2">
        <legend className="text-label font-medium text-foreground">Summary</legend>
        <label className="flex items-start gap-2.5 text-body text-foreground">
          <input
            type="radio"
            name={name}
            checked={mode === 'derived'}
            onChange={() => {
              onModeChange('derived')
            }}
            className="mt-0.5 size-4 shrink-0 accent-primary"
          />
          <span>
            Summarise it from the content automatically
            {saved !== undefined && saved !== '' ? (
              <span className="block text-meta wrap-anywhere text-muted-foreground">
                Currently: {saved}
              </span>
            ) : null}
          </span>
        </label>
        <label className="flex items-start gap-2.5 text-body text-foreground">
          <input
            type="radio"
            name={name}
            checked={mode === 'custom'}
            onChange={() => {
              onModeChange('custom')
            }}
            className="mt-0.5 size-4 shrink-0 accent-primary"
          />
          <span>Write my own summary</span>
        </label>
      </fieldset>
      {mode === 'custom' ? (
        <Field
          label="Your summary"
          hint={`A line or two, up to ${String(CARD_SUMMARY_MAX)} characters. Later edits to the content never change it.`}
          error={error}
        >
          {(control) => (
            <Textarea
              {...control}
              name="summary"
              rows={3}
              maxLength={CARD_SUMMARY_MAX}
              value={text}
              onChange={(event) => {
                onTextChange(event.target.value)
              }}
            />
          )}
        </Field>
      ) : null}
    </div>
  )
}
