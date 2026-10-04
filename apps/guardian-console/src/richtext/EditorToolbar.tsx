import type { ChainedCommands, Editor } from '@tiptap/core'
import { useEditorState } from '@tiptap/react'
import {
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type ReactNode,
} from 'react'

import { Button } from '../ui/Button.tsx'
import { cn } from '../ui/cn.ts'
import { Field } from '../ui/Field.tsx'
import { Input } from '../ui/Input.tsx'
import { normaliseLinkHref } from './contract.ts'
import type { EditorFeature } from './profile.ts'

type Command = (chain: ChainedCommands) => ChainedCommands

interface ToolbarItem {
  /** The profile feature this item needs; it is not shown, and its command never touched, without it. */
  feature: EditorFeature
  id: string
  /** Visible text. */
  text: ReactNode
  /** The accessible name. */
  label: string
  /** The toggle's `aria-pressed` source, when the item is a toggle. */
  active?: (editor: Editor) => boolean
  command: Command
}

interface ToolbarGroup {
  label: string
  items: ToolbarItem[]
}

const item = (
  feature: EditorFeature,
  id: string,
  text: ReactNode,
  label: string,
  command: Command,
  active?: (editor: Editor) => boolean,
): ToolbarItem => ({
  feature,
  id,
  text,
  label,
  command,
  ...(active === undefined ? {} : { active }),
})

const heading = (level: 2 | 3 | 4) =>
  item(
    'heading',
    `heading-${String(level)}`,
    `H${String(level)}`,
    `Heading ${String(level)}`,
    (chain) => chain.toggleHeading({ level }),
    (editor) => editor.isActive('heading', { level }),
  )

/** The groups, in toolbar order. An item is shown only when the profile has its feature, and a group only when it has an item. */
const GROUPS: ToolbarGroup[] = [
  {
    label: 'History',
    items: [
      item('history', 'undo', 'Undo', 'Undo', (chain) => chain.undo()),
      item('history', 'redo', 'Redo', 'Redo', (chain) => chain.redo()),
    ],
  },
  { label: 'Headings', items: [heading(2), heading(3), heading(4)] },
  {
    label: 'Text style',
    items: [
      item(
        'bold',
        'bold',
        'B',
        'Bold',
        (c) => c.toggleBold(),
        (e) => e.isActive('bold'),
      ),
      item(
        'italic',
        'italic',
        'I',
        'Italic',
        (c) => c.toggleItalic(),
        (e) => e.isActive('italic'),
      ),
      item(
        'underline',
        'underline',
        'U',
        'Underline',
        (c) => c.toggleUnderline(),
        (e) => e.isActive('underline'),
      ),
      item(
        'code',
        'code',
        'Code',
        'Inline code',
        (c) => c.toggleCode(),
        (e) => e.isActive('code'),
      ),
    ],
  },
  {
    label: 'Lists',
    items: [
      item(
        'bulletList',
        'bulletList',
        'Bullets',
        'Bulleted list',
        (c) => c.toggleBulletList(),
        (e) => e.isActive('bulletList'),
      ),
      item(
        'orderedList',
        'orderedList',
        'Numbers',
        'Numbered list',
        (c) => c.toggleOrderedList(),
        (e) => e.isActive('orderedList'),
      ),
    ],
  },
  {
    label: 'Blocks',
    items: [
      item(
        'blockquote',
        'blockquote',
        'Quote',
        'Block quote',
        (c) => c.toggleBlockquote(),
        (e) => e.isActive('blockquote'),
      ),
      item(
        'codeBlock',
        'codeBlock',
        'Code block',
        'Code block',
        (c) => c.toggleCodeBlock(),
        (e) => e.isActive('codeBlock'),
      ),
      item('horizontalRule', 'rule', 'Rule', 'Horizontal rule', (c) => c.setHorizontalRule()),
      item('hardBreak', 'break', 'Break', 'Line break', (c) => c.setHardBreak()),
    ],
  },
]

const TABLE_ITEMS: ToolbarItem[] = [
  item('table', 'table-insert', 'Table', 'Insert table', (c) =>
    c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }),
  ),
  item('table', 'table-row-after', 'Row below', 'Add row below', (c) => c.addRowAfter()),
  item('table', 'table-column-after', 'Column after', 'Add column after', (c) =>
    c.addColumnAfter(),
  ),
  item('table', 'table-header-row', 'Header row', 'Toggle header row', (c) => c.toggleHeaderRow()),
  item('table', 'table-row-delete', 'Delete row', 'Delete row', (c) => c.deleteRow()),
  item('table', 'table-column-delete', 'Delete column', 'Delete column', (c) => c.deleteColumn()),
  item('table', 'table-delete', 'Delete table', 'Delete table', (c) => c.deleteTable()),
]

const LINK_ITEM_ID = 'link'

/**
 * The editor's toolbar: an ARIA toolbar (one tab stop, arrow keys, Home and End move within it), every
 * button named, every toggle exposing `aria-pressed`. A command that cannot run now (nothing to undo, a
 * table inside a table) is `aria-disabled` and stays in the arrow-key order, so focus is never lost to
 * a button that has just disabled itself; the whole toolbar is `disabled` only when the editor is.
 * Each command returns focus to the editor.
 */
export function EditorToolbar({
  editor,
  features,
  controls,
  disabled,
}: {
  editor: Editor
  features: readonly EditorFeature[]
  /** The id of the editing surface this toolbar controls. */
  controls: string
  disabled: boolean
}) {
  const state = useEditorState({
    editor,
    selector: ({ editor: current }) => {
      const pressed: Record<string, boolean> = {}
      const can: Record<string, boolean> = {}
      const all = [...GROUPS.flatMap((group) => group.items), ...TABLE_ITEMS].filter((entry) =>
        features.includes(entry.feature),
      )
      for (const entry of all) {
        if (entry.active !== undefined) pressed[entry.id] = entry.active(current)
        can[entry.id] = commandAvailable(current, entry.command)
      }
      const attrs = current.getAttributes('link') as { href?: unknown }
      return {
        pressed,
        can,
        inTable: current.isActive('table'),
        linkActive: current.isActive('link'),
        linkHref: typeof attrs.href === 'string' ? attrs.href : '',
      }
    },
  })

  const has = (feature: EditorFeature) => features.includes(feature)
  const groups = GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((entry) => has(entry.feature)),
  })).filter((group) => group.items.length > 0)
  const showTable = has('table')
  const showLink = has('link')

  const barRef = useRef<HTMLDivElement>(null)
  const [stop, setStop] = useState<string | null>(null)
  const [linkOpen, setLinkOpen] = useState(false)

  function buttons(): HTMLButtonElement[] {
    return Array.from(
      barRef.current?.querySelectorAll<HTMLButtonElement>('button[data-item]') ?? [],
    )
  }

  function onKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    const all = buttons()
    const index = all.findIndex((button) => button === document.activeElement)
    if (index === -1) return
    const last = all.length - 1
    const next =
      event.key === 'ArrowRight'
        ? index === last
          ? 0
          : index + 1
        : event.key === 'ArrowLeft'
          ? index === 0
            ? last
            : index - 1
          : event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? last
              : null
    if (next === null) return
    event.preventDefault()
    all[next]?.focus()
  }

  const linkItem = item('link', LINK_ITEM_ID, 'Link', 'Link', (c) => c)
  const tableItems = TABLE_ITEMS.filter((entry) => entry.id === 'table-insert' || state.inTable)
  // The one tab stop: the last button focused, or the first when that one is no longer shown.
  const shown = [
    ...groups.flatMap((group) => group.items.map((entry) => entry.id)),
    ...(showLink ? [LINK_ITEM_ID] : []),
    ...(showTable ? tableItems.map((entry) => entry.id) : []),
  ]
  const tabStopId = stop !== null && shown.includes(stop) ? stop : shown[0]

  function render(entry: ToolbarItem, extra?: { pressed?: boolean; onActivate?: () => void }) {
    // The link button is always available; the others ask the editor whether their command can run now.
    const unavailable = state.can[entry.id] === false
    const pressed = extra?.pressed ?? state.pressed[entry.id]
    const tabStop = tabStopId === entry.id
    return (
      <Button
        key={entry.id}
        data-item={entry.id}
        variant="ghost"
        size="sm"
        disabled={disabled}
        aria-label={entry.label}
        aria-pressed={pressed}
        aria-disabled={!disabled && unavailable ? true : undefined}
        tabIndex={tabStop ? 0 : -1}
        className="aria-pressed:bg-accent aria-pressed:text-accent-foreground"
        onFocus={() => {
          setStop(entry.id)
        }}
        onClick={() => {
          if (unavailable) return
          if (extra?.onActivate !== undefined) extra.onActivate()
          else entry.command(editor.chain().focus()).run()
        }}
      >
        <span
          aria-hidden="true"
          className={cn(
            entry.id === 'bold' && 'font-bold',
            entry.id === 'italic' && 'italic',
            entry.id === 'underline' && 'underline',
          )}
        >
          {entry.text}
        </span>
      </Button>
    )
  }

  return (
    <div className="border-b border-border">
      <div
        ref={barRef}
        role="toolbar"
        aria-label="Formatting"
        aria-controls={controls}
        aria-orientation="horizontal"
        onKeyDown={onKeyDown}
        className="flex flex-wrap items-center gap-x-3 gap-y-1 p-1.5"
      >
        {groups.map((group) => (
          <div
            key={group.label}
            role="group"
            aria-label={group.label}
            className="flex items-center gap-0.5"
          >
            {group.items.map((entry) => render(entry))}
          </div>
        ))}
        {showLink ? (
          <div role="group" aria-label="Link" className="flex items-center gap-0.5">
            {render(linkItem, {
              pressed: state.linkActive,
              onActivate: () => {
                setLinkOpen(true)
              },
            })}
          </div>
        ) : null}
        {showTable ? (
          <div role="group" aria-label="Table" className="flex items-center gap-0.5">
            {tableItems.map((entry) => render(entry))}
          </div>
        ) : null}
      </div>
      {linkOpen && showLink ? (
        <LinkPanel
          initial={state.linkActive ? state.linkHref : ''}
          editing={state.linkActive}
          onApply={(href) => {
            applyLink(editor, href)
            setLinkOpen(false)
          }}
          onRemove={() => {
            editor.chain().focus().extendMarkRange('link').unsetLink().run()
            setLinkOpen(false)
          }}
          onCancel={() => {
            setLinkOpen(false)
            editor.commands.focus()
          }}
        />
      ) : null}
    </div>
  )
}

function commandAvailable(editor: Editor, command: Command): boolean {
  return command(editor.can().chain()).run()
}

/**
 * Applies a link to the selection (the whole link, when the caret is inside one). With nothing
 * selected, the address itself becomes the link text, so the act always does something visible.
 */
function applyLink(editor: Editor, href: string): void {
  const chain = editor.chain().focus()
  if (editor.isActive('link')) {
    chain.extendMarkRange('link').setLink({ href }).run()
  } else if (editor.state.selection.empty) {
    chain
      .insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] })
      .run()
  } else {
    chain.setLink({ href }).run()
  }
}

function LinkPanel({
  initial,
  editing,
  onApply,
  onRemove,
  onCancel,
}: {
  initial: string
  editing: boolean
  onApply: (href: string) => void
  onRemove: () => void
  onCancel: () => void
}) {
  const [value, setValue] = useState(initial)
  const [error, setError] = useState<string | undefined>(undefined)
  const input = useRef<HTMLInputElement>(null)

  // Opening the panel moves focus into it: the one deliberate focus move besides returning to the editor.
  useEffect(() => {
    input.current?.focus()
  }, [])

  function apply() {
    const href = normaliseLinkHref(value)
    if (href === null) {
      setError(
        'Enter a web address (https or http), or an email address as mailto:you@example.org.',
      )
      input.current?.focus()
      return
    }
    onApply(href)
  }

  return (
    <div
      role="group"
      aria-label="Edit link"
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          // Closes the panel only, not a dialog the editor may be inside.
          event.preventDefault()
          event.stopPropagation()
          onCancel()
        }
      }}
      className="flex flex-col gap-2 border-t border-border bg-muted/50 p-2"
    >
      <Field label="Link address" error={error}>
        {(control) => (
          <Input
            {...control}
            ref={input}
            type="text"
            inputMode="url"
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            value={value}
            onChange={(event) => {
              setValue(event.target.value)
              setError(undefined)
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                // Not a submit of any form the editor sits in.
                event.preventDefault()
                apply()
              }
            }}
          />
        )}
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="primary" onClick={apply}>
          Apply link
        </Button>
        {editing ? (
          <Button size="sm" onClick={onRemove}>
            Remove link
          </Button>
        ) : null}
        <Button size="sm" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  )
}
