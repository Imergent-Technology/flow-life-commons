import { mergeAttributes, type Extensions } from '@tiptap/core'
import { CodeBlock } from '@tiptap/extension-code-block'
import { Link } from '@tiptap/extension-link'
import { OrderedList } from '@tiptap/extension-ordered-list'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import StarterKit from '@tiptap/starter-kit'

import { MAX_SPAN, normaliseLinkHref } from './contract.ts'

// An editor profile is the set of things an editor may produce: the Tiptap extensions, and the
// features the toolbar offers for them. Tiptap is configured to the Resources document profile v1
// (ADR 0037, decisions 26 and 28) and to nothing else; `contract.ts` is the check that it is.
//
// The seam for a narrower editor (Guardian Discussions, later) is the feature list: a profile built
// from fewer features has fewer extensions, and so a smaller schema. Nothing here knows about
// Resources screens, Packs or Cards.
//
// Two constraints from the production Content-Security-Policy (`style-src 'self'`, no inline styles)
// shape every extension below: nothing injects CSS (the editor's own styles are the Console's
// stylesheet, richtext.css, and `RichTextEditor` creates the editor with `injectCSS: false`), and
// tables are not resizable, because column resizing writes inline `style` attributes.

export type EditorFeature =
  | 'history'
  | 'heading'
  | 'bold'
  | 'italic'
  | 'underline'
  | 'code'
  | 'link'
  | 'bulletList'
  | 'orderedList'
  | 'blockquote'
  | 'codeBlock'
  | 'horizontalRule'
  | 'hardBreak'
  | 'table'

export interface EditorProfile {
  name: string
  features: readonly EditorFeature[]
  extensions: Extensions
}

/** Every feature of the Resources document profile v1, in toolbar order. */
export const RESOURCES_FEATURES: readonly EditorFeature[] = [
  'history',
  'heading',
  'bold',
  'italic',
  'underline',
  'code',
  'link',
  'bulletList',
  'orderedList',
  'blockquote',
  'codeBlock',
  'horizontalRule',
  'hardBreak',
  'table',
]

/** The server's fixed default for a link's `rel` (DocumentProfile, the accepted-and-dropped defaults). */
const LINK_REL = 'noopener noreferrer nofollow'

const MAX_START = 1_000_000

/** A whole number from pasted markup, held to the server's bounds; anything else is the default. */
function bounded(value: string | null, fallback: number, max: number): number {
  const parsed = value === null ? Number.NaN : Number.parseInt(value, 10)
  return Number.isFinite(parsed) ? Math.min(Math.max(parsed, 1), max) : fallback
}

/**
 * A link, held to the profile. Its address passes the same rule the server applies (https, http or
 * mailto; nothing relative, no user-information), whether typed, pasted or parsed from pasted HTML.
 * Its `target`, `rel` and `class` are the fixed defaults whatever pasted markup said, because the
 * server refuses any other value rather than correct it.
 */
const ProfileLink = Link.extend({
  addAttributes() {
    // Tiptap 3's link also carries a `title` attribute (emitted as null). The profile has none, and the
    // server refuses an attribute it does not know rather than dropping it, so it is not defined here.
    return {
      href: { default: null, parseHTML: (element: HTMLElement) => element.getAttribute('href') },
      target: { default: '_blank', parseHTML: () => '_blank' },
      rel: { default: LINK_REL, parseHTML: () => LINK_REL },
      class: { default: null, parseHTML: () => null },
    }
  },
}).configure({
  openOnClick: false, // a click in an editor places the caret
  autolink: false, // a link is a deliberate act
  linkOnPaste: true,
  defaultProtocol: 'https',
  protocols: ['mailto'],
  isAllowedUri: (url) => normaliseLinkHref(url) !== null,
})

/** An ordered list has a `start` within the server's bounds and no `type`: pasted `type="a"` is refused there. */
const ProfileOrderedList = OrderedList.extend({
  addAttributes() {
    return {
      start: {
        default: 1,
        parseHTML: (element) => bounded(element.getAttribute('start'), 1, MAX_START),
      },
      type: { default: null, parseHTML: () => null },
    }
  },
})

/** A code block has no language: the server stores none, and refuses a pasted `language-x` class. */
const ProfileCodeBlock = CodeBlock.extend({
  addAttributes() {
    return { language: { default: null, parseHTML: () => null } }
  },
})

// A cell holds blocks but never another table (ADR 0037, decision 26), spans within the server's
// bounds, and never a column width (a width is what resizing would write).
const cellAttributes = {
  colspan: {
    default: 1,
    parseHTML: (element: HTMLElement) => bounded(element.getAttribute('colspan'), 1, MAX_SPAN),
  },
  rowspan: {
    default: 1,
    parseHTML: (element: HTMLElement) => bounded(element.getAttribute('rowspan'), 1, MAX_SPAN),
  },
  colwidth: { default: null, parseHTML: () => null },
}

/** The blocks a table cell may hold: every block the profile has except a table. */
function cellContent(has: (feature: EditorFeature) => boolean): string {
  const blocks = ['paragraph']
  if (has('heading')) blocks.push('heading')
  if (has('bulletList')) blocks.push('bulletList')
  if (has('orderedList')) blocks.push('orderedList')
  if (has('blockquote')) blocks.push('blockquote')
  if (has('horizontalRule')) blocks.push('horizontalRule')
  if (has('codeBlock')) blocks.push('codeBlock')

  return `(${blocks.join(' | ')})+`
}

/**
 * A table that is not resizable and draws no column group. Not being resizable is not enough on its own:
 * Tiptap's table still renders `style="min-width: ..."` on the table and on every `<col>`, set with
 * `setAttribute('style', ...)`, which the production policy (`style-src 'self'`, no 'unsafe-inline')
 * blocks. So is Tiptap's table NodeView (`View`), which sets `style.minWidth` on the table and its columns.
 * A table here has neither: it is a plain `<table><tbody>`, and its columns are the browser's to size.
 */
const ProfileTable = Table.extend({
  addCommands() {
    const commands = this.parent?.()
    return {
      ...commands,
      // A table never goes inside a table (a cell may not hold one). Tiptap's command does not refuse: from
      // inside a cell it tries to fit a table where none may go, and never returns.
      insertTable: (options) => (props) =>
        props.editor.isActive('table') ? false : (commands?.insertTable?.(options)(props) ?? false),
    }
  },
  renderHTML({ HTMLAttributes }) {
    return ['table', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes), ['tbody', 0]]
  },
}).configure({ resizable: false, View: null })

/**
 * The editor profile for a list of features. The base (document, paragraph, text) is always present;
 * every other node, mark and command is there only because a feature names it, so a profile can never
 * accept more than it lists.
 */
export function createEditorProfile(
  name: string,
  features: readonly EditorFeature[],
): EditorProfile {
  const has = (feature: EditorFeature) => features.includes(feature)
  const extensions: Extensions = [
    StarterKit.configure({
      // Pulled out of the starter kit so they can be configured to the profile (above) or left out.
      link: false,
      orderedList: false,
      codeBlock: false,
      // Never part of the profile.
      strike: false,
      trailingNode: false, // would append an empty paragraph the author never wrote
      dropcursor: false, // positions its indicator with inline styles
      // Per feature.
      undoRedo: has('history') ? {} : false,
      listKeymap: has('bulletList') || has('orderedList') ? {} : false,
      heading: has('heading') ? { levels: [2, 3, 4] } : false,
      bold: has('bold') ? {} : false,
      italic: has('italic') ? {} : false,
      underline: has('underline') ? {} : false,
      code: has('code') ? {} : false,
      bulletList: has('bulletList') ? {} : false,
      blockquote: has('blockquote') ? {} : false,
      horizontalRule: has('horizontalRule') ? {} : false,
      hardBreak: has('hardBreak') ? {} : false,
      // listItem serves both list kinds.
      listItem: has('bulletList') || has('orderedList') ? {} : false,
    }),
  ]
  if (has('link')) extensions.push(ProfileLink)
  if (has('orderedList')) extensions.push(ProfileOrderedList)
  if (has('codeBlock')) extensions.push(ProfileCodeBlock)
  if (has('table')) {
    const content = cellContent(has)
    extensions.push(
      ProfileTable,
      TableRow,
      TableHeader.extend({ content, addAttributes: () => cellAttributes }),
      TableCell.extend({ content, addAttributes: () => cellAttributes }),
    )
  }

  return { name, features: [...features], extensions }
}

/** The Resources editor profile: the whole of the document profile v1, and nothing beyond it. */
export const resourcesProfile: EditorProfile = createEditorProfile('resources', RESOURCES_FEATURES)
