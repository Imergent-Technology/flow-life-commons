import type { Editor } from '@tiptap/core'
import { act, screen } from '@testing-library/react'

/** Tiptap puts the editor on the element it manages, which is how a test reaches it. */
export const editorOf = (surface: HTMLElement) =>
  (surface as HTMLElement & { editor: Editor }).editor

/**
 * Types into a Card form's rich-text editor, by name. The editor is loaded on demand, so this waits for it (`findByRole`) and the
 * test needs no knowledge of when. It inserts text through the editor's own command, exactly as the editor's tests do (jsdom has no
 * layout, so a keystroke-by-keystroke type is not what is being proved here).
 */
export async function writeContent(name: string, text: string): Promise<HTMLElement> {
  const surface = await screen.findByRole('textbox', { name })
  await act(async () => {
    // At the end of what is there, as typing after the last word would.
    editorOf(surface).chain().focus('end').insertContent(text).run()
    await Promise.resolve()
  })
  return surface
}

export const paragraphs = (...texts: string[]) => ({
  type: 'doc',
  content: texts.map((text) => ({ type: 'paragraph', content: [{ type: 'text', text }] })),
})
