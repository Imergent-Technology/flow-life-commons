import { useEffect, useId, useRef, useState, type ReactNode } from 'react'

import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'

export interface ReorderItem {
  id: string
  /** The item's name, for its buttons: "Move Welcome up". */
  name: string
  children: ReactNode
}

export type ReorderOutcome = { ok: true } | { ok: false; message: string }

/**
 * An ordered list a person can reorder, by keyboard as well as by mouse: every item has Move up and Move down, and there is no
 * other way (drag and drop would be an enhancement over these, never instead of them).
 *
 * The server owns the order. A move sends the WHOLE new order and nothing is shown as moved until the server has accepted it; while
 * it is in flight the list keeps its old order and the buttons wait. `onReorder` returns how it came out, and the parent then
 * hands this list the order the server answered with. Afterwards focus goes back to the button that was pressed on the item that
 * moved (or its opposite, if the item is now at that end), and the move is said in a status line.
 */
export function ReorderList({
  label,
  items,
  onReorder,
}: {
  label: string
  items: readonly ReorderItem[]
  onReorder: (ids: string[]) => Promise<ReorderOutcome>
}) {
  const [pending, setPending] = useState(false)
  const [status, setStatus] = useState('')
  const [problem, setProblem] = useState<{ text: string; attempt: number } | null>(null)
  const focusAfter = useRef<{ id: string; direction: 'up' | 'down' } | null>(null)
  const listId = useId()

  // After the server's order is on screen, put focus back where the person was.
  useEffect(() => {
    const target = focusAfter.current
    if (target === null || pending) return
    focusAfter.current = null
    const list = document.getElementById(listId)
    const find = (direction: 'up' | 'down') =>
      list?.querySelector<HTMLButtonElement>(`[data-move="${target.id}:${direction}"]`) ?? null
    const preferred = find(target.direction)
    const fallback = find(target.direction === 'up' ? 'down' : 'up')
    ;(preferred !== null && !preferred.disabled ? preferred : fallback)?.focus()
  }, [items, pending, listId])

  async function move(index: number, direction: 'up' | 'down') {
    const item = items[index]
    const to = direction === 'up' ? index - 1 : index + 1
    if (item === undefined || to < 0 || to >= items.length) return
    const ids = items.map((other) => other.id)
    ids.splice(index, 1)
    ids.splice(to, 0, item.id)

    setPending(true)
    setProblem(null)
    setStatus('')
    focusAfter.current = { id: item.id, direction }
    const outcome = await onReorder(ids)
    setPending(false)
    if (outcome.ok) {
      setStatus(`Moved ${item.name} to position ${String(to + 1)} of ${String(items.length)}.`)
    } else {
      // The alert says what happened and takes focus itself; the controls are back, so there is nothing to restore.
      focusAfter.current = null
      setProblem((previous) => ({ text: outcome.message, attempt: (previous?.attempt ?? 0) + 1 }))
    }
  }

  return (
    <div className="flex flex-col gap-2">
      {problem !== null ? (
        <Alert key={problem.attempt} tone="error" focusOnMount>
          {problem.text}
        </Alert>
      ) : null}
      <ol
        id={listId}
        aria-label={label}
        className="flex list-decimal flex-col gap-2 pl-6 marker:text-muted-foreground"
      >
        {items.map((item, index) => (
          <li key={item.id} className="pl-1">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border border-border bg-surface px-3 py-2">
              <div className="flex min-w-0 flex-1 flex-col gap-1">{item.children}</div>
              <div className="flex shrink-0 items-center gap-2">
                <Button
                  size="sm"
                  data-move={`${item.id}:up`}
                  aria-label={`Move ${item.name} up`}
                  disabled={pending || index === 0}
                  onClick={() => {
                    void move(index, 'up')
                  }}
                >
                  Move up
                </Button>
                <Button
                  size="sm"
                  data-move={`${item.id}:down`}
                  aria-label={`Move ${item.name} down`}
                  disabled={pending || index === items.length - 1}
                  onClick={() => {
                    void move(index, 'down')
                  }}
                >
                  Move down
                </Button>
              </div>
            </div>
          </li>
        ))}
      </ol>
      <p role="status" className="sr-only">
        {status}
      </p>
    </div>
  )
}
