import { EditorContent, useEditor } from '@tiptap/react'
import { useEffect, useId, useRef } from 'react'

import { Alert } from '../ui/Alert.tsx'
import { cn } from '../ui/cn.ts'
import {
  canonicalDocument,
  ContentRefusal,
  parseContentDocument,
  type ContentDocument,
} from './contract.ts'
import { documentFromEditor, editorContent, profileEditorProps } from './editorDocument.ts'
import { EditorToolbar } from './EditorToolbar.tsx'
import { resourcesProfile, type EditorProfile } from './profile.ts'
import './editor.css'
import './richtext.css'

/**
 * A rich-text editing surface for a canonical document (ADR 0037, decision 28). It is what the Resources
 * Card form (WP4) will hold, and later a narrower `profile` can serve Guardian Discussions, but it knows
 * nothing of either: a document in, canonical documents out, and Tiptap stays inside this folder.
 *
 * - `value` is a canonical document. It is loaded when the component mounts and again whenever it differs
 *   from what the editor last held, so a parent that stores `onChange`'s document and passes it back sees
 *   no flicker or caret jump. Loading a document never calls `onChange`: opening a Card and closing it
 *   changes nothing.
 * - `onChange` receives the canonical document, in the form the server stores, after every edit. It is
 *   not called when the editor holds something the profile refuses (too long, too deep); `onRefusal`
 *   says so instead, and is called with `null` once it is valid again. The parent decides what to do
 *   with a refusal (it should not save), as it does with any validation state.
 * - `invalid` is the parent's validation state (a server refusal, a rule of its own); it marks the
 *   control, and the parent's message is tied to it with `describedBy`.
 * - `readOnly` keeps the content readable, selectable and focusable, without the toolbar; `disabled`
 *   removes the control from interaction and from the tab order, and says so to assistive technology.
 *
 * Nothing is injected into the page by it: no stylesheet (`injectCSS: false`; its rules are
 * editor.css and richtext.css, so the production policy's `style-src 'self'` holds), no inline style, no HTML.
 */
export function RichTextEditor({
  value,
  onChange,
  onRefusal,
  profile = resourcesProfile,
  label,
  labelledBy,
  describedBy,
  invalid = false,
  readOnly = false,
  disabled = false,
  id,
  className,
}: {
  value: unknown
  onChange: (document: ContentDocument) => void
  onRefusal?: (refusal: ContentRefusal | null) => void
  profile?: EditorProfile
  /** The accessible name of the editing surface. */
  label: string
  /** An element that names it instead (a visible heading), which wins over `label`. */
  labelledBy?: string
  describedBy?: string
  invalid?: boolean
  readOnly?: boolean
  disabled?: boolean
  id?: string
  className?: string
}) {
  const generated = useId()
  const surfaceId = id ?? `${generated}-editor`

  const parsed = parseContentDocument(value)
  const initial = parsed.ok ? parsed.document : null
  const editable = !readOnly && !disabled

  // What the editor last held, as canonical JSON: the difference between an edit it made and a value
  // the parent has since replaced.
  const held = useRef<string | null>(null)
  const latest = useRef({ onChange, onRefusal })
  const refused = useRef(false)
  useEffect(() => {
    latest.current = { onChange, onRefusal }
  })

  const editor = useEditor(
    {
      extensions: profile.extensions,
      content: editorContent(initial ?? { type: 'doc', content: [] }),
      editable,
      injectCSS: false,
      editorProps: {
        ...profileEditorProps,
        attributes: surfaceAttributes(),
      },
      onUpdate: ({ editor: current }) => {
        try {
          const document = documentFromEditor(current)
          const json = JSON.stringify(document)
          if (refused.current) {
            refused.current = false
            latest.current.onRefusal?.(null)
          }
          if (json === held.current) return
          held.current = json
          latest.current.onChange(document)
        } catch (error) {
          if (!(error instanceof ContentRefusal)) throw error
          refused.current = true
          latest.current.onRefusal?.(error)
        }
      },
    },
    [profile],
  )

  // The surface's attributes follow the props: name, description, state.
  useEffect(() => {
    editor.setOptions({
      editorProps: {
        ...profileEditorProps,
        attributes: surfaceAttributes({
          id: surfaceId,
          label,
          labelledBy,
          describedBy,
          invalid,
          readOnly,
          disabled,
        }),
      },
    })
    editor.setEditable(editable, false)
  }, [editor, surfaceId, label, labelledBy, describedBy, invalid, readOnly, disabled, editable])

  // A value the editor did not itself produce replaces its content, without announcing it as an edit.
  useEffect(() => {
    if (initial === null) return
    const json = JSON.stringify(canonicalDocument(initial))
    if (held.current === null) {
      held.current = json // the content the editor was created with
      return
    }
    if (json === held.current) return
    held.current = json
    editor.commands.setContent(editorContent(initial), { emitUpdate: false })
  }, [editor, initial])

  if (initial === null) {
    return <Alert tone="warning">This content can&rsquo;t be edited.</Alert>
  }

  return (
    <div
      className={cn(
        'rich-editor rounded-sm border border-input bg-field text-foreground',
        'focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-ring',
        invalid && 'border-danger ring-1 ring-danger',
        disabled && 'bg-muted text-muted-foreground',
        className,
      )}
    >
      {readOnly ? null : (
        <EditorToolbar
          editor={editor}
          features={profile.features}
          controls={surfaceId}
          disabled={disabled}
        />
      )}
      <EditorContent editor={editor} />
    </div>
  )
}

/**
 * The attributes of the editing surface (the element holding the content). It is a multi-line text box:
 * named, described, and flagged when invalid, read-only or disabled. A read-only surface is focusable
 * so it can be read and scrolled by keyboard; a disabled one is not.
 */
function surfaceAttributes(
  state: {
    id: string
    label: string
    labelledBy: string | undefined
    describedBy: string | undefined
    invalid: boolean
    readOnly: boolean
    disabled: boolean
  } | null = null,
): Record<string, string> {
  const attributes: Record<string, string> = {
    class: 'rich-content min-h-40 px-3 py-2 outline-none',
    role: 'textbox',
    'aria-multiline': 'true',
  }
  if (state === null) return attributes

  attributes.id = state.id
  if (state.labelledBy === undefined) attributes['aria-label'] = state.label
  else attributes['aria-labelledby'] = state.labelledBy
  if (state.describedBy !== undefined) attributes['aria-describedby'] = state.describedBy
  if (state.invalid) attributes['aria-invalid'] = 'true'
  if (state.readOnly) {
    attributes['aria-readonly'] = 'true'
    attributes.tabindex = '0'
  }
  if (state.disabled) attributes['aria-disabled'] = 'true'

  return attributes
}
