import type { Editor, EditorOptions, JSONContent } from '@tiptap/core'
import { Fragment, Slice, type Node as ProseMirrorNode } from '@tiptap/pm/model'

import { canonicalDocument, type ContentDocument } from './contract.ts'

// The two conversions between the canonical document and what Tiptap holds.

/**
 * What to hand the editor for a canonical document. The editor's schema needs at least one block, so
 * the empty document is shown as one empty paragraph.
 */
export function editorContent(document: ContentDocument): JSONContent {
  return document.content.length === 0
    ? { type: 'doc', content: [{ type: 'paragraph' }] }
    : document
}

/**
 * The canonical document for what the editor holds: the editor's JSON, run through the profile, so it
 * has the fixed default attributes dropped and the marks in the fixed order, exactly as the server
 * stores it. An editor holding nothing but one empty paragraph is the empty document, which is what
 * `editorContent` showed it as.
 *
 * @throws ContentRefusal when the editor holds something outside the profile (too long, too deep)
 */
export function documentFromEditor(editor: Editor): ContentDocument {
  const json = withoutHardBreakMarks(editor.getJSON())
  // A document always holds a block, so the editor's JSON always has content.
  const [only, ...rest] = json.content ?? []
  if (rest.length === 0 && only?.type === 'paragraph' && only.content === undefined) {
    return { type: 'doc', content: [] }
  }

  return canonicalDocument(json)
}

/**
 * The editor's JSON with no marks on any hard break. Tiptap lets a mark sit on a hard break (a pasted
 * `<strong>one<br>two</strong>` is one, and so is anything a command or a drop puts there), but the profile has
 * none: the server refuses a `marks` key on a hard break rather than drop it. A mark on a line break has no
 * meaning (it draws nothing), so it is removed here, on the way out, and only here: every path that can put one in
 * the editor comes out through this function, and the marks of the text either side of the break are untouched.
 */
function withoutHardBreakMarks(node: JSONContent): JSONContent {
  const content = node.content?.map(withoutHardBreakMarks)
  if (node.type === 'hardBreak') {
    const plain = { ...node }
    delete plain.marks
    return content === undefined ? plain : { ...plain, content }
  }

  return content === undefined ? node : { ...node, content }
}

/**
 * Pasted content, held to what the profile allows. Tiptap's schema already turns pasted HTML into the
 * configured nodes and marks and drops the rest (an unsupported tag keeps its text), and the profile's
 * extensions pin the attributes. What the schema cannot know is the profile's rule on characters: a
 * control character, a line or paragraph separator, or a lone surrogate would be refused by the server
 * (and a line feed is a line break only inside code), so they are removed here, as pasted, rather than
 * left to fail on save.
 */
export function cleanPastedSlice(slice: Slice): Slice {
  return new Slice(cleanFragment(slice.content, false), slice.openStart, slice.openEnd)
}

function cleanFragment(fragment: Fragment, inCode: boolean): Fragment {
  const nodes: ProseMirrorNode[] = []
  fragment.forEach((node) => {
    if (node.isText) {
      const text = cleanText(node.text ?? '', inCode)
      if (text !== '') nodes.push(node.type.schema.text(text, node.marks))
    } else {
      nodes.push(node.copy(cleanFragment(node.content, node.type.name === 'codeBlock')))
    }
  })

  return Fragment.fromArray(nodes)
}

function cleanText(text: string, inCode: boolean): string {
  const kept = inCode ? text : text.replace(/\n/g, ' ')
  return kept.replace(
    inCode ? /[^\P{Cc}\t\n]|[\p{Cs}\p{Zl}\p{Zp}]/gu : /[^\P{Cc}\t]|[\p{Cs}\p{Zl}\p{Zp}]/gu,
    '',
  )
}

/** The editor props every profile editor has, whatever it labels itself: paste held to the profile. */
export const profileEditorProps: EditorOptions['editorProps'] = {
  transformPasted: cleanPastedSlice,
}
