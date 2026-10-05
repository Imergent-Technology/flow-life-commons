import { useState, type SyntheticEvent } from 'react'

import {
  audienceLabel,
  describeResourcesFailure,
  MEMBER_DELIVERY_NOTE,
} from '../../admin/resourcesWording.ts'
import {
  AUDIENCES,
  DEFAULT_PREVIEW_AUDIENCE,
  previewPack,
  type Audience,
  type PackPreview,
} from '../../api/resources.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Field } from '../../ui/Field.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { Select } from '../../ui/Select.tsx'
import { TypeBadge } from './badges.tsx'

/**
 * What an audience would receive if this Pack were Published as it stands (ADR 0037, decision 49). The answer is the server's own
 * projection, asked for: whether a Card would be visible, and the order it would be seen in, are never worked out in the
 * browser. A Card the audience cannot see is simply absent, exactly as it would be for them. This is a preview for the editor, not
 * the library: it shows the shape (Pack, Category, Cards), not the finished presentation.
 */
export function PackPreviewSection({ packId }: { packId: string }) {
  const [audience, setAudience] = useState<Audience>(DEFAULT_PREVIEW_AUDIENCE)
  const [pending, setPending] = useState(false)
  const [result, setResult] = useState<
    { kind: 'preview'; preview: PackPreview } | { kind: 'error'; message: string } | null
  >(null)

  async function run() {
    setPending(true)
    const response = await previewPack(packId, audience)
    setPending(false)
    if (response.ok) setResult({ kind: 'preview', preview: response.value })
    else if (response.failure.kind !== 'unauthenticated') {
      setResult({
        kind: 'error',
        message: describeResourcesFailure(response.failure, 'preview').message,
      })
    }
  }

  const preview = result?.kind === 'preview' ? result.preview : null
  const who = preview === null ? '' : audienceLabel(preview.audience)

  return (
    <Panel
      title="Preview"
      description="See what an audience would receive if the Pack were Published as it is now."
    >
      <form
        aria-label="Preview the Pack"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void run()
        }}
        className="flex flex-col gap-3"
      >
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-full sm:w-48">
            <Field label="Preview as">
              {(control) => (
                <Select
                  {...control}
                  value={audience}
                  onChange={(event) => {
                    setAudience(event.target.value as Audience)
                  }}
                >
                  {AUDIENCES.map((each) => (
                    <option key={each} value={each}>
                      {audienceLabel(each)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
          <Button type="submit" pending={pending} pendingLabel="Previewing…">
            Preview
          </Button>
        </div>
        {audience === 'member' ? (
          <p className="text-meta text-muted-foreground">{MEMBER_DELIVERY_NOTE}</p>
        ) : null}
      </form>

      <div aria-live="polite" className="mt-3 flex flex-col gap-2">
        {result?.kind === 'error' ? <Alert tone="error">{result.message}</Alert> : null}
        {preview !== null ? (
          <div className="flex flex-col gap-2 text-body" data-testid="pack-preview">
            {preview.audienceTargeted ? null : <p>The Pack is not aimed at {who}.</p>}
            {preview.packState === 'draft' ? (
              <p className="text-muted-foreground">
                The Pack is a Draft, so {who} cannot see it yet. This is what they would see once it
                is Published.
              </p>
            ) : null}
            {!preview.visible || preview.pack === null ? (
              <p className="font-medium">{who} would see nothing from this Pack.</p>
            ) : (
              <>
                <p className="font-medium">
                  {who} would see{' '}
                  {preview.pack.cards.length === 1
                    ? '1 Card'
                    : `${String(preview.pack.cards.length)} Cards`}{' '}
                  in {preview.pack.category.name}:
                </p>
                <p className="wrap-anywhere">
                  <strong>{preview.pack.title}</strong>
                  {preview.pack.isSeries ? ' (a Series)' : ''}
                  {preview.pack.summary !== null ? ` — ${preview.pack.summary}` : ''}
                </p>
                <ol className="flex list-decimal flex-col gap-1 pl-6">
                  {preview.pack.cards.map((card) => (
                    <li key={card.id} className="wrap-anywhere">
                      <span className="mr-2">{card.title}</span>
                      <TypeBadge type={card.type} />
                      {card.summary !== '' ? (
                        <span className="block text-meta text-muted-foreground">
                          {card.summary}
                        </span>
                      ) : null}
                    </li>
                  ))}
                </ol>
              </>
            )}
          </div>
        ) : null}
      </div>
    </Panel>
  )
}
