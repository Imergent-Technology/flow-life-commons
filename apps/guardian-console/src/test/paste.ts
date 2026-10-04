import type { Editor } from '@tiptap/core'

/**
 * Pastes into an editor the way a browser does: a `paste` event carrying the clipboard's `text/html` and
 * `text/plain`. jsdom has no ClipboardEvent or DataTransfer (so ProseMirror's own `pasteHTML` cannot run
 * in it), but ProseMirror reads only `event.clipboardData.getData`, which this supplies.
 */
export function pasteInto(editor: Editor, clipboard: { html?: string; text?: string }): void {
  const data: Record<string, string> = {
    'text/html': clipboard.html ?? '',
    'text/plain': clipboard.text ?? '',
  }
  const event = new Event('paste', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'clipboardData', {
    value: {
      getData: (type: string) => data[type] ?? '',
      types: Object.keys(data).filter((type) => data[type] !== ''),
      files: [],
      items: [],
    },
  })
  editor.view.dom.dispatchEvent(event)
}
