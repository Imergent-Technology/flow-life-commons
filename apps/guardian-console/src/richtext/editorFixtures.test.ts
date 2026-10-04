/// <reference types="node" />
import { Editor } from '@tiptap/core'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'

import { pasteInto } from '../test/paste'
import { canonicalDocument, type ContentNode } from './contract'
import { documentFromEditor, editorContent, profileEditorProps } from './editorDocument'
import { resourcesProfile } from './profile'

// The fixtures named `editor-*` in the shared corpus are produced by the REAL configured editor, by
// the recipes below, and committed as the corpus's valid documents. The server's suite then validates
// exactly what the editor emits (ADR 0037, decision 28): a document the editor can produce that the
// server refuses is found there, not in production.
//
// This test is also the generator and the drift check. Normally it fails if the editor no longer
// produces what is committed (a Tiptap upgrade, a changed extension). To regenerate after a deliberate
// change, run it from the repository with UPDATE_RESOURCE_FIXTURES=1 and commit the files; then the
// server's `ContentFixturesTest` must pass against them.

const DIRECTORY = join(
  import.meta.dirname,
  '../../../platform/tests/Fixtures/resource-content/valid',
)

const editors: Editor[] = []
function blank(): Editor {
  const editor = new Editor({
    extensions: resourcesProfile.extensions,
    content: editorContent({ type: 'doc', content: [] }),
    injectCSS: false,
    editorProps: profileEditorProps,
  })
  editors.push(editor)
  return editor
}
afterEach(() => {
  editors.splice(0).forEach((editor) => {
    editor.destroy()
  })
})

const p = (text: string, marks?: { type: string; attrs?: { href: string } }[]) => ({
  type: 'paragraph',
  content: [{ type: 'text', text, ...(marks === undefined ? {} : { marks }) }],
})

interface Recipe {
  description: string
  build: () => Editor
}

const recipes: Record<string, Recipe> = {
  'editor-article': {
    description:
      'Made in the real editor: headings 2 to 4, every mark (applied in an order that is not the stored one), a link, nested lists, an ordered list from 3, a quote, a rule, a code block and a hard break. The raw editor output, with its fixed defaults spelled out.',
    build: () => {
      const editor = blank()
      editor.commands.setContent(
        editorContent(
          canonicalDocument({
            type: 'doc',
            content: [
              {
                type: 'heading',
                attrs: { level: 2 },
                content: [{ type: 'text', text: 'Opening the shop' }],
              },
              p('Unlock the door, then switch on the lights.'),
              {
                type: 'heading',
                attrs: { level: 3 },
                content: [{ type: 'text', text: 'Checklist' }],
              },
              {
                type: 'bulletList',
                content: [
                  {
                    type: 'listItem',
                    content: [
                      p('Float counted'),
                      {
                        type: 'bulletList',
                        content: [{ type: 'listItem', content: [p('Notes and coins')] }],
                      },
                    ],
                  },
                  { type: 'listItem', content: [p('Sign the log')] },
                ],
              },
              {
                type: 'orderedList',
                attrs: { start: 3 },
                content: [{ type: 'listItem', content: [p('Third step')] }],
              },
              {
                type: 'heading',
                attrs: { level: 4 },
                content: [{ type: 'text', text: 'Reference' }],
              },
              { type: 'blockquote', content: [p('Quoted policy text.')] },
              { type: 'horizontalRule' },
              { type: 'codeBlock', content: [{ type: 'text', text: 'line one\n  line two\n' }] },
              p('Plain'),
            ],
          }),
        ),
      )
      // Marks, applied through the editor's own commands, in the opposite order to the stored one.
      const end = editor.state.doc.content.size
      editor.commands.setTextSelection({ from: end - 5, to: end - 1 })
      editor
        .chain()
        .setLink({ href: 'https://example.org/sop' })
        .toggleUnderline()
        .toggleItalic()
        .toggleBold()
        .run()
      editor.commands.setTextSelection(end - 1)
      editor
        .chain()
        .setHardBreak()
        .insertContent('after the break ')
        .toggleCode()
        .insertContent('code')
        .run()
      return editor
    },
  },
  'editor-table': {
    description:
      'Made in the real editor: a table inserted with a header row, then given another row and column and filled in. Cells carry the editor defaults (colspan, rowspan, colwidth), and there are no widths or styles.',
    build: () => {
      const editor = blank()
      editor.chain().insertContent('Rota').run()
      editor.chain().focus('end').insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run()
      editor.chain().addRowAfter().run()
      editor.chain().addColumnAfter().run()
      const cells = ['Day', 'Who', 'Notes', 'Mon', 'Sam', '', 'Tue', 'Kai', '']
      cells.forEach((text, index) => {
        if (text === '') return
        // Walk to the nth cell and type into it.
        let n = 0
        let target = -1
        editor.state.doc.descendants((node, position) => {
          if (node.type.name === 'tableHeader' || node.type.name === 'tableCell') {
            if (n === index) target = position + 2
            n++
          }
          return true
        })
        editor.chain().setTextSelection(target).insertContent(text).run()
      })
      return editor
    },
  },
  'editor-pasted': {
    description:
      'Made in the real editor by pasting HTML that carries things the profile does not allow (styles, a heading 1, strike-through, an image, a script, a link with a target and rel, a code block with a language class, an ordered list with a type and a zero start). The editor resolves it to the profile and emits only accepted attributes.',
    build: () => {
      const editor = blank()
      pasteInto(editor, {
        html:
          '<h1>Imported title</h1><p style="color:red">Styled <s>struck</s> <strong>bold</strong> <a href="https://example.org" target="_self" rel="author" class="x">link</a> <a href="javascript:alert(1)">unsafe</a></p>' +
          '<img src="https://example.org/a.png"><script>alert(1)</script>' +
          '<ol start="0" type="a"><li>One</li></ol><pre><code class="language-js">const a = 1</code></pre>' +
          '<table><tr><th colspan="2" data-colwidth="120">Head</th></tr><tr><td colspan="50">Wide</td></tr></table>',
      })
      return editor
    },
  },
}

/** What the server derives as a Card's plain text: the text of every node, blocks and breaks as one space, whitespace collapsed. */
function plainText(node: ContentNode | { type: 'doc'; content: ContentNode[] }): string {
  const walk = (current: ContentNode | { type: 'doc'; content: ContentNode[] }): string => {
    if (current.type === 'text') return current.text ?? ''
    if (current.type === 'hardBreak') return ' '
    const inner = (current.content ?? []).map(walk).join('')
    return current.type === 'doc' ? inner : `${inner} `
  }
  return walk(node)
    .replace(/[\s\p{Z}]+/gu, ' ')
    .trim()
}

function fixtureFor(recipe: Recipe) {
  const editor = recipe.build()
  const document = editor.getJSON()
  const canonical = documentFromEditor(editor)
  return {
    description: recipe.description,
    document,
    canonical,
    text: plainText(canonical),
  }
}

describe('fixtures produced by the real editor', () => {
  if (process.env.UPDATE_RESOURCE_FIXTURES === '1') {
    it('writes them', () => {
      for (const [name, recipe] of Object.entries(recipes)) {
        writeFileSync(
          join(DIRECTORY, `${name}.json`),
          `${JSON.stringify(fixtureFor(recipe), null, 2)}\n`,
        )
      }
    })
    return
  }

  it.each(Object.entries(recipes))(
    '%s is still exactly what the editor produces',
    (name, recipe) => {
      const committed = JSON.parse(readFileSync(join(DIRECTORY, `${name}.json`), 'utf8')) as unknown

      expect(committed).toEqual(JSON.parse(JSON.stringify(fixtureFor(recipe))))
    },
  )

  it('has fixtures that exercise what matters: defaults spelled out, and resolved paste', () => {
    const raw = recipes['editor-article']?.build().getJSON()
    const article = JSON.stringify(raw)

    for (const needle of [
      '"rel":"noopener noreferrer nofollow"',
      '"language":null',
      '"start":3',
      '"level":4',
      '"hardBreak"',
      '"underline"',
    ]) {
      expect(article).toContain(needle)
    }
    const table = JSON.stringify(recipes['editor-table']?.build().getJSON())
    expect(table).toContain('"colwidth":null')
    expect(table).toContain('tableHeader')
    const pasted = JSON.stringify(recipes['editor-pasted']?.build().getJSON())
    expect(pasted).not.toMatch(
      /_self|author|javascript|language-|"type":"a"|colwidth":\[|style|strike|image/,
    )
  })
})
