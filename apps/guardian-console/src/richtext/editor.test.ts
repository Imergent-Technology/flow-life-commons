import { Editor } from '@tiptap/core'
import { afterEach, describe, expect, it } from 'vitest'

import { pasteInto } from '../test/paste'
import { validFixtures } from '../test/resourceFixtures'
import { watchStyleWrites } from '../test/styleWatch'
import {
  canonicalDocument,
  type ContentDocument,
  type ContentMark,
  type ContentNode,
} from './contract'
import { documentFromEditor, editorContent, profileEditorProps } from './editorDocument'
import { createEditorProfile, resourcesProfile, type EditorProfile } from './profile'

// Tiptap, configured to the Resources profile, held to the contract: what it is given comes back
// unchanged, and what it produces is what the server accepts.

const editors: Editor[] = []

function create(content: ContentDocument, profile: EditorProfile = resourcesProfile): Editor {
  const editor = new Editor({
    extensions: profile.extensions,
    content: editorContent(content),
    injectCSS: false,
    editorProps: profileEditorProps,
  })
  editors.push(editor)
  return editor
}

const doc = (...content: ContentDocument['content']): ContentDocument => ({ type: 'doc', content })
const paragraph = (text: string) => ({ type: 'paragraph', content: [{ type: 'text', text }] })

afterEach(() => {
  editors.splice(0).forEach((editor) => {
    editor.destroy()
  })
  document.head.querySelectorAll('style').forEach((style) => {
    style.remove()
  })
})

describe('round trip through the editor', () => {
  it.each(validFixtures().map((fixture) => [fixture.name, fixture] as const))(
    'valid/%s comes back exactly as the server stores it',
    (_name, fixture) => {
      const stored = canonicalDocument(fixture.canonical ?? fixture.document)
      const editor = create(canonicalDocument(fixture.document))

      // Both empty forms (no blocks, one empty paragraph) are one thing to an editor, which always has a
      // block to type in. It is the one deliberate normalisation, and it comes back as the empty document.
      const emptied =
        stored.content.length === 0 ||
        (stored.content.length === 1 &&
          stored.content[0]?.type === 'paragraph' &&
          stored.content[0].content === undefined)
      expect(documentFromEditor(editor)).toEqual(emptied ? { type: 'doc', content: [] } : stored)
    },
  )

  it('is stable: loading what the editor produced produces it again', () => {
    for (const fixture of validFixtures()) {
      const first = documentFromEditor(create(canonicalDocument(fixture.document)))
      expect(documentFromEditor(create(first)), fixture.name).toEqual(first)
    }
  })

  it('emits only attributes the server accepts, at the values it accepts, for what the editor holds raw', () => {
    // The editor's own JSON, before canonicalising, still passes the contract: the fixed defaults are the
    // server's, not something to be filtered out afterwards.
    for (const fixture of validFixtures()) {
      const editor = create(canonicalDocument(fixture.document))
      expect(() => canonicalDocument(editor.getJSON()), fixture.name).not.toThrow()
    }
  })

  it('emits the fixed defaults exactly (editor-defaults fixture)', () => {
    const fixture = validFixtures().find((f) => f.name === 'editor-defaults')
    const raw = JSON.stringify(create(canonicalDocument(fixture?.document)).getJSON())

    expect(raw).toContain('"rel":"noopener noreferrer nofollow"')
    expect(raw).toContain('"target":"_blank"')
    expect(raw).toContain('"class":null')
    expect(raw).toContain('"language":null')
    expect(raw).toContain('"colwidth":null')
    expect(raw).toContain('"start":1,"type":null')
    // Tiptap 3's link also has a `title`; the profile does not, and the server refuses an unknown attribute.
    expect(raw).not.toContain('title')
  })
})

describe('what the editor produces', () => {
  it('produces every node and mark of the profile through its commands, and the contract accepts each', () => {
    const editor = create(doc(paragraph('Hello world')))
    const produced = (run: (chain: ReturnType<Editor['chain']>) => unknown) => {
      editor.commands.setContent(editorContent(doc(paragraph('Hello world'))))
      editor.commands.selectAll()
      run(editor.chain())
      return documentFromEditor(editor)
    }

    expect(produced((c) => c.toggleHeading({ level: 2 }).run()).content[0]).toEqual({
      type: 'heading',
      attrs: { level: 2 },
      content: [{ type: 'text', text: 'Hello world' }],
    })
    expect(produced((c) => c.toggleHeading({ level: 3 }).run()).content[0]?.attrs).toEqual({
      level: 3,
    })
    expect(produced((c) => c.toggleHeading({ level: 4 }).run()).content[0]?.attrs).toEqual({
      level: 4,
    })
    expect(produced((c) => c.toggleBold().run()).content[0]?.content?.[0]?.marks).toEqual([
      { type: 'bold' },
    ])
    expect(produced((c) => c.toggleItalic().run()).content[0]?.content?.[0]?.marks).toEqual([
      { type: 'italic' },
    ])
    expect(produced((c) => c.toggleUnderline().run()).content[0]?.content?.[0]?.marks).toEqual([
      { type: 'underline' },
    ])
    expect(produced((c) => c.toggleCode().run()).content[0]?.content?.[0]?.marks).toEqual([
      { type: 'code' },
    ])
    expect(
      produced((c) => c.setLink({ href: 'https://example.org' }).run()).content[0]?.content?.[0]
        ?.marks,
    ).toEqual([{ type: 'link', attrs: { href: 'https://example.org' } }])
    expect(produced((c) => c.toggleBulletList().run()).content[0]?.type).toBe('bulletList')
    expect(produced((c) => c.toggleOrderedList().run()).content[0]).toEqual({
      type: 'orderedList',
      content: [{ type: 'listItem', content: [paragraph('Hello world')] }],
    })
    expect(produced((c) => c.toggleBlockquote().run()).content[0]?.type).toBe('blockquote')
    expect(produced((c) => c.toggleCodeBlock().run()).content[0]).toEqual({
      type: 'codeBlock',
      content: [{ type: 'text', text: 'Hello world' }],
    })
    expect(produced((c) => c.setHorizontalRule().run()).content.map((n) => n.type)).toContain(
      'horizontalRule',
    )
    expect(
      produced((c) => c.setTextSelection(6).setHardBreak().run()).content[0]?.content?.map(
        (n) => n.type,
      ),
    ).toEqual(['text', 'hardBreak', 'text'])
    const table = produced((c) => c.insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run())
    expect(table.content.find((n) => n.type === 'table')).toBeDefined()
  })

  it('writes a table with header cells and plain cells, with no widths and no styles', () => {
    const editor = create(doc(paragraph('x')))
    editor.chain().focus().insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run()
    const raw = JSON.stringify(editor.getJSON())

    expect(raw).toContain('tableHeader')
    expect(raw).toContain('tableCell')
    expect(documentFromEditor(editor).content.find((n) => n.type === 'table')).toEqual({
      type: 'table',
      content: [
        {
          type: 'tableRow',
          content: [
            { type: 'tableHeader', content: [{ type: 'paragraph' }] },
            { type: 'tableHeader', content: [{ type: 'paragraph' }] },
          ],
        },
        {
          type: 'tableRow',
          content: [
            { type: 'tableCell', content: [{ type: 'paragraph' }] },
            { type: 'tableCell', content: [{ type: 'paragraph' }] },
          ],
        },
      ],
    })
    expect(editor.view.dom.querySelector('[style]')).toBeNull()
    expect(editor.view.dom.querySelector('colgroup')).toBeNull()
  })

  it('does not put a table inside a table cell (and does not hang trying)', () => {
    const editor = create(doc(paragraph('x')))
    editor.chain().focus().insertTable({ rows: 1, cols: 1 }).run()
    const before = JSON.stringify(editor.getJSON())

    expect(editor.can().insertTable({ rows: 1, cols: 1 })).toBe(false)
    expect(editor.chain().focus().insertTable({ rows: 2, cols: 2 }).run()).toBe(false)
    expect(JSON.stringify(editor.getJSON())).toBe(before)
  })

  it('does not add a trailing paragraph the author never wrote', () => {
    const editor = create(doc({ type: 'horizontalRule' }))

    expect(documentFromEditor(editor)).toEqual(doc({ type: 'horizontalRule' }))
  })

  it('writes marks in the fixed order whatever order they were applied in', () => {
    const editor = create(doc(paragraph('x')))
    editor
      .chain()
      .selectAll()
      .toggleUnderline()
      .setLink({ href: 'https://example.org' })
      .toggleBold()
      .run()

    expect(documentFromEditor(editor).content[0]?.content?.[0]?.marks?.map((m) => m.type)).toEqual([
      'bold',
      'underline',
      'link',
    ])
  })

  it('holds only the profile: no strike-through, no images, no HTML', () => {
    const names = resourcesProfile.extensions.length
    expect(names).toBeGreaterThan(5)
    const editor = create(doc(paragraph('x')))
    const schema = editor.schema

    expect(Object.keys(schema.marks).sort()).toEqual([
      'bold',
      'code',
      'italic',
      'link',
      'underline',
    ])
    expect(Object.keys(schema.nodes).sort()).toEqual([
      'blockquote',
      'bulletList',
      'codeBlock',
      'doc',
      'hardBreak',
      'heading',
      'horizontalRule',
      'listItem',
      'orderedList',
      'paragraph',
      'table',
      'tableCell',
      'tableHeader',
      'tableRow',
      'text',
    ])
  })

  it('offers headings 2 to 4 only', () => {
    const editor = create(doc(paragraph('x')))

    expect(editor.can().toggleHeading({ level: 1 })).toBe(false)
    expect(editor.can().toggleHeading({ level: 5 })).toBe(false)
    expect(editor.can().toggleHeading({ level: 2 })).toBe(true)
  })

  it('refuses to link an address the profile does not allow', () => {
    const editor = create(doc(paragraph('click')))
    editor.chain().selectAll().setLink({ href: 'javascript:alert(1)' }).run()

    expect(JSON.stringify(editor.getJSON())).not.toContain('javascript')
  })

  it('can be made narrower: a profile from fewer features has a smaller schema', () => {
    const narrow = createEditorProfile('narrow', ['bold', 'italic', 'link'])
    const editor = create(doc(paragraph('x')), narrow)

    expect(Object.keys(editor.schema.marks).sort()).toEqual(['bold', 'italic', 'link'])
    expect(Object.keys(editor.schema.nodes).sort()).toEqual(['doc', 'paragraph', 'text'])
    expect('toggleHeading' in editor.commands).toBe(false)
    expect('toggleBold' in editor.commands).toBe(true)
  })
})

describe('paste', () => {
  function paste(html: string): ContentDocument {
    const editor = create(doc())
    editor.commands.setContent(editorContent({ type: 'doc', content: [] }))
    editor.commands.focus()
    pasteInto(editor, { html })
    return documentFromEditor(editor)
  }

  it('keeps supported structure from pasted HTML', () => {
    const pasted = paste(
      '<h2>Title</h2><p>Some <strong>bold</strong>, <em>italic</em> and <u>underlined</u> text with <code>code</code> and <a href="https://example.org/a">a link</a>.</p><ul><li>One</li><li>Two</li></ul><blockquote><p>Quoted</p></blockquote><pre><code>line 1\nline 2</code></pre><hr>',
    )

    expect(pasted.content.map((n) => n.type)).toEqual([
      'heading',
      'paragraph',
      'bulletList',
      'blockquote',
      'codeBlock',
      'horizontalRule',
    ])
    expect(JSON.stringify(pasted)).toContain('"href":"https://example.org/a"')
    expect(pasted.content[4]?.content?.[0]?.text).toBe('line 1\nline 2')
  })

  it('resolves unsupported structure into the profile instead of keeping it', () => {
    const pasted = paste(
      '<h1>Top</h1><p style="color:red;font-family:Comic Sans">Styled <s>struck</s> <span style="font-size:40px">big</span></p><img src="https://example.org/x.png"><iframe src="https://example.org"></iframe><script>alert(1)</script><div onclick="alert(1)">Div</div>',
    )
    expect(() => canonicalDocument(pasted)).not.toThrow()
    // Everything unsupported resolved to plain paragraphs of the text it held: no mark, attribute, image,
    // frame or script survives, and the text itself (even the struck-through text) is kept.
    expect(pasted).toEqual(doc(paragraph('Top'), paragraph('Styled struck big'), paragraph('Div')))
  })

  it('pins the attributes pasted markup could otherwise set, so the server never refuses what was pasted', () => {
    const pasted = paste(
      '<p><a href="https://example.org" target="_self" rel="author" class="fancy" title="Hi">link</a></p>' +
        '<ol start="0" type="a"><li>A</li></ol><ol start="7" type="i"><li>B</li></ol><ol start="99999999"><li>C</li></ol>' +
        '<pre><code class="language-js">x</code></pre>' +
        '<table><tbody><tr><td colspan="50" rowspan="0" data-colwidth="120" style="width:120px">c</td></tr></tbody></table>',
    )

    expect(() => canonicalDocument(pasted)).not.toThrow()
    const raw = JSON.stringify(pasted)
    expect(raw).not.toMatch(/_self|author|fancy|title|"type":"[ai]"|language|colwidth|120/)
    const lists = pasted.content.filter((n) => n.type === 'orderedList')
    expect(lists.map((n) => n.attrs?.start)).toEqual([undefined, 7, 1_000_000])
    const cell = JSON.stringify(pasted.content.find((n) => n.type === 'table'))
    expect(cell).toContain('"colspan":20')
  })

  it('does not make a link of an address outside the profile', () => {
    const pasted = paste(
      '<p><a href="javascript:alert(1)">a</a> <a href="data:text/html,x">b</a> <a href="/relative">c</a> <a href="ftp://example.org">d</a> <a href="https://user:pw@example.org">e</a> <a href="mailto:me@example.org">f</a></p>',
    )
    const links = JSON.stringify(pasted).match(/"href":"[^"]*"/g)

    expect(links).toEqual(['"href":"mailto:me@example.org"'])
    expect(JSON.stringify(pasted)).toContain('a b c d e ') // the rest stays as text, not as links
  })

  it('removes characters the profile refuses, rather than failing on save', () => {
    const editor = create(doc(paragraph('x')))
    editor.commands.setContent(editorContent({ type: 'doc', content: [] }))
    editor.commands.focus()
    pasteInto(editor, { html: '<p>a\u0007b c d</p><pre><code>x\ty\nz\u0000</code></pre>' })

    const pasted = documentFromEditor(editor)
    expect(JSON.stringify(pasted)).toContain('abcd')
    expect(pasted.content[1]?.content?.[0]?.text).toBe('x\ty\nz')
  })

  it('does not accept tables pasted inside cells', () => {
    const pasted = paste('<table><tr><td><table><tr><td>inner</td></tr></table></td></tr></table>')

    expect(() => canonicalDocument(pasted)).not.toThrow()
  })

  it('pastes plain text as paragraphs', () => {
    const editor = create(doc(paragraph('x')))
    editor.commands.setContent(editorContent({ type: 'doc', content: [] }))
    editor.commands.focus()
    pasteInto(editor, { text: 'one\n\ntwo' })

    expect(documentFromEditor(editor).content.map((n) => n.type)).toEqual([
      'paragraph',
      'paragraph',
    ])
  })
})

describe('under the production Content-Security-Policy', () => {
  // `style-src 'self'` with no 'unsafe-inline' (ADR 0026): a <style> element, and a style ATTRIBUTE set
  // from markup or setAttribute, are blocked. Script setting properties on element.style is not, so it is
  // the first two this watches.
  it('injects no stylesheet, and a control that does is caught (positive control)', () => {
    const before = document.querySelectorAll('style').length
    create(doc(paragraph('x')))
    expect(document.querySelectorAll('style').length).toBe(before)

    const injecting = new Editor({
      extensions: resourcesProfile.extensions,
      content: editorContent(doc(paragraph('x'))),
      injectCSS: true,
    })
    editors.push(injecting)
    expect(document.querySelectorAll('style').length).toBeGreaterThan(before)
  })

  it('does not make tables resizable, and a control that does is caught (positive control)', () => {
    const table = resourcesProfile.extensions.find((extension) => extension.name === 'table')
    expect(table?.options).toMatchObject({ resizable: false })

    const editor = create(doc(paragraph('x')))
    expect(
      editor.state.plugins.map((plugin) => (plugin as unknown as { key: string }).key).join(' '),
    ).not.toMatch(/tableColumnResizing/)
  })

  it('writes no style attribute and no style element while it is used', () => {
    const watcher = watchStyleWrites()
    try {
      const editor = create(
        doc(paragraph('Hello'), {
          type: 'bulletList',
          content: [{ type: 'listItem', content: [paragraph('item')] }],
        }),
      )
      document.body.append(editor.view.dom)
      editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
      editor.chain().focus().addRowAfter().run()
      editor.chain().focus().addColumnAfter().run()
      editor.chain().focus().toggleHeaderRow().run()
      editor.chain().focus().setHorizontalRule().toggleBlockquote().toggleCodeBlock().run()
      editor.commands.selectAll()
      editor.commands.setLink({ href: 'https://example.org' })

      expect(watcher.writes()).toEqual([])
      expect(document.querySelectorAll('style')).toHaveLength(0)
      expect(editor.view.dom.querySelectorAll('[style]').length).toBe(0)
      expect(editor.view.dom.querySelector('style, colgroup')).toBeNull()
    } finally {
      watcher.stop()
    }
  })

  it('has a watcher that would notice a style attribute (positive control)', () => {
    const watcher = watchStyleWrites()
    try {
      document.createElement('div').setAttribute('style', 'width: 10px')
      expect(watcher.writes().length).toBe(1)
    } finally {
      watcher.stop()
    }
  })
})

describe('a paste that would nest a table', () => {
  const cellDocument = (...blocks: ContentDocument['content']): ContentDocument =>
    doc(
      paragraph('before'),
      {
        type: 'table',
        content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: blocks }] }],
      },
      paragraph('after'),
    )
  const TABLE_AND_TEXT =
    '<p>pasted text</p><table><tbody><tr><td>P</td><td>Q</td></tr></tbody></table><p>more text</p>'

  /** Puts the caret at the end of the first text node reading `text`. */
  function caretIn(editor: Editor, text: string): void {
    let at = -1
    editor.state.doc.descendants((node, position) => {
      if (node.isText && node.text === text && at === -1) at = position + text.length
    })
    expect(at).toBeGreaterThan(-1)
    editor.commands.setTextSelection(at)
  }

  const nestedTables = (document: ContentDocument): number => {
    let found = 0
    const walk = (node: ContentDocument['content'][number], inCell: boolean) => {
      if (node.type === 'table' && inCell) found++
      const below = inCell || node.type === 'tableCell' || node.type === 'tableHeader'
      node.content?.forEach((child) => {
        walk(child, below)
      })
    }
    document.content.forEach((node) => {
      walk(node, false)
    })
    return found
  }

  it.each([
    ['a cell directly', cellDocument(paragraph('cell')), 'cell'],
    [
      'a quote in a cell',
      cellDocument({ type: 'blockquote', content: [paragraph('quoted')] }),
      'quoted',
    ],
    [
      'a list item in a cell',
      cellDocument({
        type: 'bulletList',
        content: [{ type: 'listItem', content: [paragraph('item')] }],
      }),
      'item',
    ],
  ])(
    'is refused whole with the caret in %s: nothing is inserted and the document stays valid',
    (_where, start, text) => {
      const editor = create(start)
      editor.commands.focus()
      caretIn(editor, text)
      const before = JSON.stringify(documentFromEditor(editor))

      pasteInto(editor, { html: TABLE_AND_TEXT })

      expect(JSON.stringify(documentFromEditor(editor))).toBe(before)
      expect(nestedTables(documentFromEditor(editor))).toBe(0)
      expect(() => canonicalDocument(documentFromEditor(editor))).not.toThrow()
    },
  )

  it('refuses it for any way of inserting a table inside a cell, not only paste', () => {
    const editor = create(cellDocument({ type: 'blockquote', content: [paragraph('quoted')] }))
    caretIn(editor, 'quoted')
    const before = JSON.stringify(documentFromEditor(editor))

    editor.commands.insertContent({
      type: 'table',
      content: [
        {
          type: 'tableRow',
          content: [{ type: 'tableCell', content: [{ type: 'paragraph' }] }],
        },
      ],
    })

    expect(JSON.stringify(documentFromEditor(editor))).toBe(before)
  })

  it('still pastes a table at the top level, and pastes cell content over cells as before', () => {
    const top = create(doc(paragraph('start')))
    top.commands.focus('end')
    pasteInto(top, { html: TABLE_AND_TEXT })
    const types = documentFromEditor(top).content.map((n) => n.type)

    expect(types).toContain('table')
    expect(() => canonicalDocument(documentFromEditor(top))).not.toThrow()

    // A table pasted over a table's cells is the table's own paste (cells over cells), never a nested table.
    const inside = create(cellDocument(paragraph('cell')))
    inside.commands.focus()
    caretIn(inside, 'cell')
    pasteInto(inside, { html: '<table><tbody><tr><td>P</td></tr></tbody></table>' })

    expect(nestedTables(documentFromEditor(inside))).toBe(0)
    expect(JSON.stringify(documentFromEditor(inside))).toContain('"P"')
  })

  it('keeps quotes, lists and plain pasted text working inside a cell', () => {
    const editor = create(cellDocument(paragraph('cell')))
    editor.commands.focus()
    caretIn(editor, 'cell')

    pasteInto(editor, {
      html: '<blockquote><p>quoted</p></blockquote><ul><li>one</li></ul><p>plain</p>',
    })
    const result = documentFromEditor(editor)

    expect(() => canonicalDocument(result)).not.toThrow()
    const raw = JSON.stringify(result)
    for (const word of ['quoted', 'one', 'plain']) expect(raw).toContain(word)
    expect(nestedTables(result)).toBe(0)
  })
})

describe('a hard break inside a mark', () => {
  // Tiptap lets a mark sit on a hard break (`<strong>one<br>two</strong>` pastes as one). The profile has no marks on a
  // hard break (the server refuses a `marks` key there), so what the editor emits never carries them, however they got in.
  const bold: ContentMark[] = [{ type: 'bold' }]
  const text = (value: string, marks?: ContentMark[]): ContentNode => ({
    type: 'text',
    text: value,
    ...(marks === undefined ? {} : { marks }),
  })

  function pasted(html: string): { editor: Editor; output: ContentDocument } {
    const editor = create(doc())
    editor.commands.focus()
    pasteInto(editor, { html })
    return { editor, output: documentFromEditor(editor) }
  }

  it('pastes from bold text as a plain break between bold words, and the output is valid', () => {
    const { output } = pasted('<p><strong>one<br>two</strong></p>')

    expect(output).toEqual(
      doc({
        type: 'paragraph',
        content: [text('one', bold), { type: 'hardBreak' }, text('two', bold)],
      }),
    )
    expect(() => canonicalDocument(output)).not.toThrow()
  })

  it('pastes from a link as a plain break between linked words, and the output is valid', () => {
    const link: ContentMark[] = [{ type: 'link', attrs: { href: 'https://example.com' } }]
    const { output } = pasted('<p><a href="https://example.com">one<br>two</a></p>')

    expect(output).toEqual(
      doc({
        type: 'paragraph',
        content: [text('one', link), { type: 'hardBreak' }, text('two', link)],
      }),
    )
    expect(() => canonicalDocument(output)).not.toThrow()
  })

  it('removes marks from a hard break however the editor came to hold them, and keeps every other mark', () => {
    const editor = create(doc())
    // Not through the profile's own paths: raw content, as a command, a drop or a future extension could put it.
    editor.commands.setContent({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            text('a', [...bold, { type: 'italic' }]),
            { type: 'hardBreak', marks: [...bold, { type: 'italic' }] },
            text('b', bold),
          ],
        },
        {
          type: 'blockquote',
          content: [
            {
              type: 'paragraph',
              content: [text('c'), { type: 'hardBreak', marks: bold }, text('d')],
            },
          ],
        },
      ],
    })

    const output = documentFromEditor(editor)
    expect(output).toEqual(
      doc(
        {
          type: 'paragraph',
          content: [
            text('a', [...bold, { type: 'italic' }]),
            { type: 'hardBreak' },
            text('b', bold),
          ],
        },
        {
          type: 'blockquote',
          content: [{ type: 'paragraph', content: [text('c'), { type: 'hardBreak' }, text('d')] }],
        },
      ),
    )
    expect(() => canonicalDocument(output)).not.toThrow()
  })

  it('leaves a hard break typed with Shift+Enter, and one in unmarked text, exactly as they were', () => {
    const editor = create(doc(paragraph('one')))
    editor.commands.focus('end')
    editor.chain().setHardBreak().insertContent('two').run()

    expect(documentFromEditor(editor).content[0]?.content?.map((n) => n.type)).toEqual([
      'text',
      'hardBreak',
      'text',
    ])
  })
})
