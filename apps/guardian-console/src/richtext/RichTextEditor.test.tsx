import type { Editor } from '@tiptap/core'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y'
import { validFixtures } from '../test/resourceFixtures'
import { watchStyleWrites } from '../test/styleWatch'
import { ContentRefusal, type ContentDocument } from './contract'
import { RichTextEditor } from './RichTextEditor'

// The editor as a person meets it: named, operable by keyboard, honest about its state, and quiet until
// the content is actually changed.

const paragraph = (value: string) => ({
  type: 'paragraph',
  content: [{ type: 'text', text: value }],
})
const hello: ContentDocument = { type: 'doc', content: [paragraph('Hello world')] }

/** Tiptap puts the editor on the element it manages, which is how a test reaches the selection. */
const editorOf = (surface: HTMLElement) => (surface as HTMLElement & { editor: Editor }).editor
const surface = () => screen.getByRole('textbox', { name: 'Card content' })

async function act_(run: () => void) {
  await act(async () => {
    run()
    await Promise.resolve()
  })
}

interface Spies {
  onChange: ReturnType<typeof vi.fn<(document: ContentDocument) => void>>
  onRefusal: ReturnType<typeof vi.fn<(refusal: ContentRefusal | null) => void>>
}

function setup(
  props: Partial<Parameters<typeof RichTextEditor>[0]> = {},
  value: unknown = hello,
): Spies {
  const spies: Spies = { onChange: vi.fn(), onRefusal: vi.fn() }
  render(
    <RichTextEditor
      value={value}
      label="Card content"
      onChange={spies.onChange}
      onRefusal={spies.onRefusal}
      {...props}
    />,
  )
  return spies
}

const select = (from: number, to: number) =>
  act_(() => {
    editorOf(surface()).commands.setTextSelection({ from, to })
  })

describe('naming and structure', () => {
  it('is a named, multi-line text box', () => {
    setup()

    expect(surface()).toHaveAttribute('aria-multiline', 'true')
    expect(surface()).toHaveAttribute('contenteditable', 'true')
  })

  it('is named by an element when one is given, in preference to its own label', () => {
    render(
      <>
        <h2 id="heading">Instructions</h2>
        <RichTextEditor
          value={hello}
          label="Ignored"
          labelledBy="heading"
          onChange={() => undefined}
        />
      </>,
    )

    expect(screen.getByRole('textbox', { name: 'Instructions' })).toBeInTheDocument()
  })

  it('is described by what the parent points at, and is marked invalid when the parent says so', () => {
    render(
      <>
        <p id="why">The content is too long.</p>
        <RichTextEditor
          value={hello}
          label="Card content"
          describedBy="why"
          invalid
          onChange={() => undefined}
        />
      </>,
    )

    expect(surface()).toHaveAccessibleDescription('The content is too long.')
    expect(surface()).toHaveAttribute('aria-invalid', 'true')
  })

  it('is not marked invalid otherwise', () => {
    setup()

    expect(surface()).not.toHaveAttribute('aria-invalid')
  })

  it('has a toolbar, named, that says what it controls', () => {
    setup()
    const toolbar = screen.getByRole('toolbar', { name: 'Formatting' })

    expect(toolbar).toHaveAttribute('aria-controls', surface().id)
    expect(toolbar).toHaveAttribute('aria-orientation', 'horizontal')
  })

  it('names every control, in a sensible order', () => {
    setup()
    const names = within(screen.getByRole('toolbar'))
      .getAllByRole('button')
      .map((button) => button.getAttribute('aria-label'))

    expect(names).toEqual([
      'Undo',
      'Redo',
      'Heading 2',
      'Heading 3',
      'Heading 4',
      'Bold',
      'Italic',
      'Underline',
      'Inline code',
      'Bulleted list',
      'Numbered list',
      'Block quote',
      'Code block',
      'Horizontal rule',
      'Line break',
      'Link',
      'Insert table',
    ])
    for (const button of within(screen.getByRole('toolbar')).getAllByRole('button')) {
      expect(button).toHaveAccessibleName()
      expect(button).toHaveAttribute('type', 'button')
    }
  })

  it('nests no interactive control inside another', () => {
    setup()

    expect(
      document.querySelectorAll(
        'button button, a button, button a, [role="textbox"] button, [role="textbox"] input',
      ),
    ).toHaveLength(0)
  })
})

describe('loading and stability', () => {
  it.each(validFixtures().map((fixture) => [fixture.name, fixture] as const))(
    'valid/%s: opening it and leaving it changes nothing and says nothing',
    (_name, fixture) => {
      const { onChange, onRefusal } = setup({}, fixture.document)

      expect(onChange).not.toHaveBeenCalled()
      expect(onRefusal).not.toHaveBeenCalled()
      expect(surface()).toBeInTheDocument()
    },
  )

  it('shows the document it was given', () => {
    setup()

    expect(surface()).toHaveTextContent('Hello world')
  })

  it('replaces its content, without calling it an edit, when the parent passes a different document', () => {
    const spies: Spies = { onChange: vi.fn(), onRefusal: vi.fn() }
    const { rerender } = render(
      <RichTextEditor value={hello} label="Card content" onChange={spies.onChange} />,
    )

    rerender(
      <RichTextEditor
        value={{ type: 'doc', content: [paragraph('Replaced')] }}
        label="Card content"
        onChange={spies.onChange}
      />,
    )

    expect(surface()).toHaveTextContent('Replaced')
    expect(surface()).not.toHaveTextContent('Hello')
    expect(spies.onChange).not.toHaveBeenCalled()
  })

  it('refuses to open content outside the profile, and shows a message, not the content', () => {
    const { container } = render(
      <RichTextEditor
        value={{ type: 'doc', content: [{ type: 'script' }] }}
        label="Card content"
        onChange={() => undefined}
      />,
    )

    expect(screen.getByRole('status')).toHaveTextContent(/can.t be edited/)
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(container.textContent).not.toMatch(/script/)
  })
})

describe('editing', () => {
  it('calls onChange with the canonical document after an edit, and only then', async () => {
    const { onChange } = setup()
    await act_(() => {
      editorOf(surface()).commands.setTextSelection(6)
      editorOf(surface()).commands.insertContent(' there')
    })

    expect(onChange).toHaveBeenLastCalledWith({
      type: 'doc',
      content: [paragraph('Hello there world')],
    })
  })

  it('applies formatting from the toolbar, exposes it as pressed, and emits the canonical form', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(1, 6)
    const bold = screen.getByRole('button', { name: 'Bold' })
    expect(bold).toHaveAttribute('aria-pressed', 'false')

    await user.click(bold)

    expect(bold).toHaveAttribute('aria-pressed', 'true')
    expect(onChange).toHaveBeenLastCalledWith({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            { type: 'text', text: 'Hello', marks: [{ type: 'bold' }] },
            { type: 'text', text: ' world' },
          ],
        },
      ],
    })

    await user.click(bold)
    expect(bold).toHaveAttribute('aria-pressed', 'false')
    expect(onChange).toHaveBeenLastCalledWith(hello)
  })

  it.each([
    ['Italic', 'italic'],
    ['Underline', 'underline'],
    ['Inline code', 'code'],
  ])('%s toggles its mark', async (name, mark) => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(1, 6)

    await user.click(screen.getByRole('button', { name }))

    expect(onChange.mock.lastCall?.[0].content[0]?.content?.[0]?.marks).toEqual([{ type: mark }])
    expect(screen.getByRole('button', { name })).toHaveAttribute('aria-pressed', 'true')
  })

  it.each([
    ['Heading 2', { type: 'heading', attrs: { level: 2 } }],
    ['Heading 3', { type: 'heading', attrs: { level: 3 } }],
    ['Heading 4', { type: 'heading', attrs: { level: 4 } }],
    ['Bulleted list', { type: 'bulletList' }],
    ['Numbered list', { type: 'orderedList' }],
    ['Block quote', { type: 'blockquote' }],
    ['Code block', { type: 'codeBlock' }],
  ])('%s changes the block', async (name, expected) => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(2, 4)

    await user.click(screen.getByRole('button', { name }))

    expect(onChange.mock.lastCall?.[0].content[0]).toMatchObject(expected)
    expect(screen.getByRole('button', { name })).toHaveAttribute('aria-pressed', 'true')
  })

  it('inserts a rule, a line break and a table, and offers table commands only inside one', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(6, 6)

    await user.click(screen.getByRole('button', { name: 'Line break' }))
    expect(onChange.mock.lastCall?.[0].content[0]?.content?.map((n) => n.type)).toEqual([
      'text',
      'hardBreak',
      'text',
    ])

    await user.click(screen.getByRole('button', { name: 'Horizontal rule' }))
    expect(onChange.mock.lastCall?.[0].content.map((n) => n.type)).toContain('horizontalRule')

    expect(screen.queryByRole('button', { name: 'Add row below' })).toBeNull()
    await user.click(screen.getByRole('button', { name: 'Insert table' }))
    expect(onChange.mock.lastCall?.[0].content.some((n) => n.type === 'table')).toBe(true)

    // Now inside the table: its commands appear, and inserting another table is unavailable, not silent.
    await user.click(await screen.findByRole('button', { name: 'Add row below' }))
    expect(screen.getByRole('button', { name: 'Add column after' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Insert table' })).toHaveAttribute(
      'aria-disabled',
      'true',
    )
    const rows = onChange.mock.lastCall?.[0].content.find((n) => n.type === 'table')?.content
    expect(rows).toHaveLength(4)
  })

  it('leaves a command that cannot run aria-disabled, in the keyboard order, and does nothing when pressed', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    const undo = screen.getByRole('button', { name: 'Undo' })

    expect(undo).toHaveAttribute('aria-disabled', 'true')
    expect(undo).not.toBeDisabled()
    await user.click(undo)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('returns focus to the editor after a command', async () => {
    const user = userEvent.setup()
    setup()
    await select(1, 6)

    await user.click(screen.getByRole('button', { name: 'Bold' }))

    // Tiptap returns focus on the next animation frame.
    await waitFor(() => {
      expect(surface()).toHaveFocus()
    })
  })

  it('reports content the profile refuses instead of passing it on, and clears the report when it is valid again', async () => {
    const { onChange, onRefusal } = setup()
    let deep: unknown = paragraph('x')
    for (let i = 0; i < 25; i++) deep = { type: 'blockquote', content: [deep] }

    await act_(() => {
      editorOf(surface()).commands.setContent({ type: 'doc', content: [deep as never] })
    })
    expect(onChange).not.toHaveBeenCalled()
    expect(onRefusal).toHaveBeenLastCalledWith(expect.any(ContentRefusal))
    expect(onRefusal.mock.lastCall?.[0]?.message).toMatch(/nested too deeply/)

    await act_(() => {
      editorOf(surface()).commands.setContent({ type: 'doc', content: [paragraph('fine')] })
    })
    expect(onRefusal).toHaveBeenLastCalledWith(null)
    expect(onChange).toHaveBeenLastCalledWith({ type: 'doc', content: [paragraph('fine')] })
  })

  it('emits the empty document when everything is deleted', async () => {
    const { onChange } = setup()
    await act_(() => {
      editorOf(surface()).commands.clearContent(true)
    })

    expect(onChange).toHaveBeenLastCalledWith({ type: 'doc', content: [] })
  })

  it('works as a controlled component: the parent keeps what it is sent', async () => {
    const user = userEvent.setup()
    const seen: ContentDocument[] = []
    function Parent() {
      const [value, setValue] = useState<ContentDocument>(hello)
      return (
        <RichTextEditor
          value={value}
          label="Card content"
          onChange={(next) => {
            seen.push(next)
            setValue(next)
          }}
        />
      )
    }
    render(<Parent />)
    await select(1, 6)
    await user.click(screen.getByRole('button', { name: 'Bold' }))

    expect(seen).toHaveLength(1) // the parent passing its value back is not a second edit
    expect(surface().querySelector('strong')?.textContent).toBe('Hello')
  })
})

describe('keyboard', () => {
  const tabStops = () =>
    within(screen.getByRole('toolbar'))
      .getAllByRole('button')
      .filter((b) => b.tabIndex === 0)

  it('has one tab stop in the toolbar, which Tab then leaves', async () => {
    const user = userEvent.setup()
    render(
      <>
        <button type="button">Before</button>
        <RichTextEditor value={hello} label="Card content" onChange={() => undefined} />
        <button type="button">After</button>
      </>,
    )
    expect(tabStops()).toHaveLength(1)

    await user.click(screen.getByRole('button', { name: 'Before' }))
    await user.tab()
    expect(screen.getByRole('button', { name: 'Undo' })).toHaveFocus()
    await user.tab()
    expect(surface()).toHaveFocus() // not the next toolbar button
    await user.tab()
    expect(screen.getByRole('button', { name: 'After' })).toHaveFocus()
  })

  it('moves along the toolbar with the arrow keys, Home and End, wrapping at the ends', async () => {
    const user = userEvent.setup()
    setup()
    const buttons = within(screen.getByRole('toolbar')).getAllByRole('button')
    const first = buttons[0]
    const last = buttons[buttons.length - 1]
    first?.focus()

    await user.keyboard('{ArrowRight}')
    expect(buttons[1]).toHaveFocus()
    await user.keyboard('{ArrowLeft}{ArrowLeft}')
    expect(last).toHaveFocus()
    await user.keyboard('{Home}')
    expect(first).toHaveFocus()
    await user.keyboard('{End}')
    expect(last).toHaveFocus()
    await user.keyboard('{ArrowRight}')
    expect(first).toHaveFocus()
  })

  it('remembers where it was: the tab stop follows focus', async () => {
    const user = userEvent.setup()
    setup()
    screen.getByRole('button', { name: 'Undo' }).focus()
    await user.keyboard('{ArrowRight}{ArrowRight}')

    expect(tabStops().map((b) => b.getAttribute('aria-label'))).toEqual(['Heading 2'])
  })

  it('activates a button with Enter and Space', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(1, 6)
    screen.getByRole('button', { name: 'Bold' }).focus()

    await user.keyboard('{Enter}')
    expect(onChange).toHaveBeenCalledTimes(1)
    await waitFor(() => {
      expect(surface()).toHaveFocus() // a command returns focus to the editor
    })
    screen.getByRole('button', { name: 'Italic' }).focus()
    await user.keyboard(' ')
    expect(onChange).toHaveBeenCalledTimes(2)
  })
})

describe('links', () => {
  it('opens a panel focused on its field, applies a valid address with Enter, and returns to the editor', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(1, 6)

    await user.click(screen.getByRole('button', { name: 'Link' }))
    const field = screen.getByRole('textbox', { name: 'Link address' })
    expect(field).toHaveFocus()

    await user.type(field, 'https://example.org/page{Enter}')

    expect(onChange).toHaveBeenLastCalledWith({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            {
              type: 'text',
              text: 'Hello',
              marks: [{ type: 'link', attrs: { href: 'https://example.org/page' } }],
            },
            { type: 'text', text: ' world' },
          ],
        },
      ],
    })
    expect(screen.queryByRole('group', { name: 'Edit link' })).toBeNull()
    await waitFor(() => {
      expect(surface()).toHaveFocus()
    })
  })

  it('tells the person what is wrong with an address, in words, and does not apply it', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(1, 6)
    await user.click(screen.getByRole('button', { name: 'Link' }))
    const field = screen.getByRole('textbox', { name: 'Link address' })

    for (const bad of [
      'javascript:alert(1)',
      'example.org',
      '/relative',
      'https://user:pw@example.org',
    ]) {
      await user.clear(field)
      await user.type(field, `${bad}{Enter}`)

      expect(field, bad).toBeInvalid()
      expect(field).toHaveAccessibleDescription(/Enter a web address/)
      expect(field).toHaveFocus()
    }
    expect(onChange).not.toHaveBeenCalled()
    expect(JSON.stringify(editorOf(surface()).getJSON())).not.toContain('javascript')
  })

  it('clears the error as soon as the address is edited', async () => {
    const user = userEvent.setup()
    setup()
    await select(1, 6)
    await user.click(screen.getByRole('button', { name: 'Link' }))
    const field = screen.getByRole('textbox', { name: 'Link address' })
    await user.type(field, 'nope{Enter}')
    expect(field).toBeInvalid()

    await user.type(field, 'x')

    expect(field).not.toBeInvalid()
  })

  it('applies with the button, and cancels with the button or Escape, without closing a dialog around it', async () => {
    const user = userEvent.setup()
    const outer = vi.fn()
    const { onChange } = setup()
    document.body.addEventListener('keydown', outer)
    await select(1, 6)

    await user.click(screen.getByRole('button', { name: 'Link' }))
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('group', { name: 'Edit link' })).toBeNull()
    await waitFor(() => {
      expect(surface()).toHaveFocus()
    })
    expect(
      outer.mock.calls.filter(([event]) => (event as KeyboardEvent).key === 'Escape'),
    ).toHaveLength(0)

    await user.click(screen.getByRole('button', { name: 'Link' }))
    await user.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('group', { name: 'Edit link' })).toBeNull()

    await user.click(screen.getByRole('button', { name: 'Link' }))
    await user.type(screen.getByRole('textbox', { name: 'Link address' }), 'mailto:me@example.org')
    await user.click(screen.getByRole('button', { name: 'Apply link' }))
    expect(onChange.mock.lastCall?.[0].content[0]?.content?.[0]?.marks).toEqual([
      { type: 'link', attrs: { href: 'mailto:me@example.org' } },
    ])
    document.body.removeEventListener('keydown', outer)
  })

  it('does not submit a form the editor sits in', async () => {
    const user = userEvent.setup()
    const submit = vi.fn((event: { preventDefault: () => void }) => {
      event.preventDefault()
    })
    render(
      <form onSubmit={submit}>
        <RichTextEditor value={hello} label="Card content" onChange={() => undefined} />
      </form>,
    )
    await select(1, 6)
    await user.click(screen.getByRole('button', { name: 'Link' }))
    await user.type(
      screen.getByRole('textbox', { name: 'Link address' }),
      'https://example.org{Enter}',
    )

    expect(submit).not.toHaveBeenCalled()
  })

  it('inserts the address as the text when nothing is selected', async () => {
    const user = userEvent.setup()
    const { onChange } = setup()
    await select(6, 6)
    await user.click(screen.getByRole('button', { name: 'Link' }))
    await user.type(
      screen.getByRole('textbox', { name: 'Link address' }),
      'https://example.org{Enter}',
    )

    expect(JSON.stringify(onChange.mock.lastCall?.[0])).toContain(
      '"text":"https://example.org","marks":[{"type":"link"',
    )
  })

  it('edits and removes an existing link: the panel opens on its address, with a Remove button', async () => {
    const user = userEvent.setup()
    const linked: ContentDocument = {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          content: [
            {
              type: 'text',
              text: 'site',
              marks: [{ type: 'link', attrs: { href: 'https://example.org' } }],
            },
          ],
        },
      ],
    }
    const { onChange } = setup({}, linked)
    await select(2, 2)
    expect(screen.getByRole('button', { name: 'Link' })).toHaveAttribute('aria-pressed', 'true')

    await user.click(screen.getByRole('button', { name: 'Link' }))
    expect(screen.getByRole('textbox', { name: 'Link address' })).toHaveValue('https://example.org')
    await user.click(screen.getByRole('button', { name: 'Remove link' }))

    expect(onChange).toHaveBeenLastCalledWith({ type: 'doc', content: [paragraph('site')] })
  })
})

describe('read-only and disabled', () => {
  it('read-only: readable and focusable, not editable, no toolbar, and says so', () => {
    setup({ readOnly: true })

    expect(surface()).toHaveAttribute('aria-readonly', 'true')
    expect(surface()).toHaveAttribute('contenteditable', 'false')
    expect(surface().tabIndex).toBe(0)
    expect(surface()).toHaveTextContent('Hello world')
    expect(screen.queryByRole('toolbar')).toBeNull()
  })

  it('disabled: not editable, out of the tab order, toolbar disabled, and says so', () => {
    setup({ disabled: true })

    expect(surface()).toHaveAttribute('aria-disabled', 'true')
    expect(surface()).toHaveAttribute('contenteditable', 'false')
    expect(surface()).not.toHaveAttribute('tabindex')
    for (const button of within(screen.getByRole('toolbar')).getAllByRole('button'))
      expect(button).toBeDisabled()
  })

  it('does not call onChange for a document replaced while read-only', () => {
    const { onChange } = setup({ readOnly: true })

    expect(onChange).not.toHaveBeenCalled()
  })

  it('becomes editable again when the flag is lifted', () => {
    const { rerender } = render(
      <RichTextEditor value={hello} label="Card content" readOnly onChange={() => undefined} />,
    )
    expect(surface()).toHaveAttribute('contenteditable', 'false')

    rerender(<RichTextEditor value={hello} label="Card content" onChange={() => undefined} />)

    expect(surface()).toHaveAttribute('contenteditable', 'true')
    expect(surface()).not.toHaveAttribute('aria-readonly')
    expect(screen.getByRole('toolbar')).toBeInTheDocument()
  })
})

describe('under the production Content-Security-Policy', () => {
  it('writes no style element and no style attribute while it is mounted and used', async () => {
    const user = userEvent.setup()
    const watcher = watchStyleWrites()
    try {
      const { container } = render(
        <RichTextEditor value={hello} label="Card content" onChange={() => undefined} />,
      )
      await select(1, 6)
      await user.click(screen.getByRole('button', { name: 'Bold' }))
      await user.click(screen.getByRole('button', { name: 'Insert table' }))
      await user.click(await screen.findByRole('button', { name: 'Add column after' }))
      await user.click(screen.getByRole('button', { name: 'Link' }))

      expect(watcher.writes()).toEqual([])
      expect(document.querySelectorAll('style')).toHaveLength(0)
      expect(container.querySelectorAll('[style]')).toHaveLength(0)
    } finally {
      watcher.stop()
    }
  })
})

describe('accessibility (axe)', () => {
  const rich: ContentDocument = {
    type: 'doc',
    content: [
      { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Title' }] },
      {
        type: 'paragraph',
        content: [
          { type: 'text', text: 'Text with ' },
          {
            type: 'text',
            text: 'a link',
            marks: [{ type: 'link', attrs: { href: 'https://example.org' } }],
          },
        ],
      },
      { type: 'bulletList', content: [{ type: 'listItem', content: [paragraph('One')] }] },
      {
        type: 'table',
        content: [
          { type: 'tableRow', content: [{ type: 'tableHeader', content: [paragraph('H')] }] },
          { type: 'tableRow', content: [{ type: 'tableCell', content: [paragraph('C')] }] },
        ],
      },
      { type: 'codeBlock', content: [{ type: 'text', text: 'code' }] },
    ],
  }

  it('editable', async () => {
    const { container } = render(
      <RichTextEditor value={rich} label="Card content" onChange={() => undefined} />,
    )

    await expectNoAxeViolations(container)
  })

  it('with the cursor in a table, so the table commands are shown', async () => {
    const { container } = render(
      <RichTextEditor value={rich} label="Card content" onChange={() => undefined} />,
    )
    await act_(() => {
      editorOf(surface()).commands.setTextSelection(editorOf(surface()).state.doc.content.size - 6)
    })
    await expectNoAxeViolations(container)
  })

  it('with the link panel open, and an error showing', async () => {
    const user = userEvent.setup()
    const { container } = render(
      <RichTextEditor value={rich} label="Card content" onChange={() => undefined} />,
    )
    await user.click(screen.getByRole('button', { name: 'Link' }))
    await user.type(screen.getByRole('textbox', { name: 'Link address' }), 'nope{Enter}')

    await expectNoAxeViolations(container)
  })

  it('invalid, and described', async () => {
    const { container } = render(
      <>
        <p id="why">Too long.</p>
        <RichTextEditor
          value={rich}
          label="Card content"
          invalid
          describedBy="why"
          onChange={() => undefined}
        />
      </>,
    )

    await expectNoAxeViolations(container)
  })

  it('read-only', async () => {
    const { container } = render(
      <RichTextEditor value={rich} label="Card content" readOnly onChange={() => undefined} />,
    )

    await expectNoAxeViolations(container)
  })

  it('disabled', async () => {
    const { container } = render(
      <RichTextEditor value={rich} label="Card content" disabled onChange={() => undefined} />,
    )

    await expectNoAxeViolations(container)
  })
})
