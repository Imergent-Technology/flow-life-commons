import { useId, type ReactNode } from 'react'

import { LazyRichTextEditor } from '../../richtext/LazyRichTextEditor.tsx'
import type { ContentDocument, ContentRefusal } from '../../richtext/contract.ts'
import { StatusIcon } from '../../ui/StatusIcon.tsx'

/**
 * A Card's content: the rich-text editor (loaded when this field first appears, not with the page) under a visible label and an
 * optional hint. The editor speaks only the Resources document profile and hands back canonical documents; what the server then
 * makes of one is its own judgement (`invalid_content`, naming where), shown here as the field's error.
 */
export function CardContentField({
  label,
  hint,
  value,
  onChange,
  onRefusal,
  error,
}: {
  label: string
  hint?: ReactNode
  /** A canonical document (the Card's, or the person's edit of it). */
  value: unknown
  onChange: (document: ContentDocument) => void
  onRefusal: (refusal: ContentRefusal | null) => void
  error?: string | undefined
}) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
    .filter(Boolean)
    .join(' ')

  return (
    <div className="flex flex-col gap-1">
      <span id={`${id}-label`} className="text-label font-medium text-foreground">
        {label}
      </span>
      {hint === undefined ? null : (
        <div id={hintId} className="text-meta text-muted-foreground">
          {hint}
        </div>
      )}
      <LazyRichTextEditor
        value={value}
        onChange={onChange}
        onRefusal={onRefusal}
        label={label}
        labelledBy={`${id}-label`}
        {...(describedBy !== '' && { describedBy })}
        invalid={error !== undefined}
      />
      {error === undefined ? null : (
        <p id={errorId} className="flex items-start gap-1.5 text-label text-danger">
          <StatusIcon kind="error" className="mt-0.5 size-4 shrink-0" />
          <span>{error}</span>
        </p>
      )}
    </div>
  )
}
