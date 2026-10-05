import { Component, lazy, Suspense, useState, type ComponentProps, type ReactNode } from 'react'

import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import { SkeletonRegion, SkeletonText } from '../ui/Skeleton.tsx'
import type { RichTextEditor } from './RichTextEditor.tsx'

type EditorProps = ComponentProps<typeof RichTextEditor>

/**
 * The editor, loaded when a screen first needs it (ADR 0037, decision 28; WP2's carry-forward). Tiptap and ProseMirror are about
 * 225 kB gzipped, and almost no Console page edits rich text, so the editor lives in a chunk of its own: the entry bundle, and
 * every route that does not edit a Card, never fetches it. This file reaches the editor only through `import()`, and nothing else
 * in the Console may import `RichTextEditor` statically (`guardrails.test.ts`; `scripts/verify-build.mjs` proves the build).
 *
 * While the chunk loads, the person sees an announced loading state of roughly the editor's size. If the chunk cannot be fetched
 * (the network went away between the page and the editor) they are told so and can ask again: a `lazy` that failed stays failed,
 * so each of a few asks uses a fresh one. The rest of the screen's form is untouched either way.
 */
const ASKS = 3
const EDITORS = Array.from({ length: ASKS }, () =>
  lazy(() => import('./RichTextEditor.tsx').then((module) => ({ default: module.RichTextEditor }))),
)

export function LazyRichTextEditor(props: EditorProps) {
  const [ask, setAsk] = useState(0)
  const Editor = EDITORS[ask]

  return (
    <EditorBoundary
      key={ask}
      canRetry={ask + 1 < ASKS}
      onRetry={() => {
        setAsk((n) => n + 1)
      }}
    >
      <Suspense fallback={<EditorPlaceholder label={props.label} />}>
        {Editor === undefined ? null : <Editor {...props} />}
      </Suspense>
    </EditorBoundary>
  )
}

function EditorPlaceholder({ label }: { label: string }) {
  return (
    <SkeletonRegion label={`Loading the editor for ${label}…`} visibleLabel>
      <div className="rounded-md border border-input bg-surface p-3">
        <SkeletonText lines={4} />
      </div>
    </SkeletonRegion>
  )
}

class EditorBoundary extends Component<
  { children: ReactNode; canRetry: boolean; onRetry: () => void },
  { failed: boolean }
> {
  override state = { failed: false }

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true }
  }

  override render() {
    if (!this.state.failed) return this.props.children
    return (
      <div className="flex flex-col items-start gap-3">
        <Alert tone="error">
          The editor could not be loaded.{' '}
          {this.props.canRetry
            ? 'Check your connection and try again.'
            : 'Reload the page when your connection is back.'}
        </Alert>
        {this.props.canRetry ? <Button onClick={this.props.onRetry}>Try again</Button> : null}
      </div>
    )
  }
}
