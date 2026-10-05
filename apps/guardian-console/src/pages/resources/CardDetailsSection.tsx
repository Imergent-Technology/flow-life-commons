import { useState, type SyntheticEvent } from 'react'

import {
  describeResourcesFailure,
  isStaleRevision,
  type ResourceProblem,
} from '../../admin/resourcesWording.ts'
import { shown } from '../../admin/time.ts'
import {
  getCard,
  updateCard,
  type CardChanges,
  type ManagedCard,
  type SummaryMode,
} from '../../api/resources.ts'
import {
  parseContentDocument,
  type ContentDocument,
  type ContentRefusal,
} from '../../richtext/contract.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { Property, PropertyList } from '../../ui/PropertyList.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { CardContentField } from './CardContentField.tsx'
import { CARD_TITLE_MAX } from './cardLimits.ts'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { SummaryField } from './SummaryField.tsx'
import { useFeedback } from './useFeedback.ts'
import { useFocusFirstInvalid } from './useFocusFirstInvalid.ts'

interface Values {
  title: string
  uri: string
  summaryMode: SummaryMode
  summaryText: string
}

const valuesOf = (card: ManagedCard): Values => ({
  title: card.title,
  uri: card.uri ?? '',
  summaryMode: card.summaryMode,
  summaryText: card.summaryMode === 'custom' ? card.summary : '',
})

/** Whether an edited document is different from the saved one (an edit that was typed and then undone is not a change). */
function contentDiffers(edited: ContentDocument, saved: unknown): boolean {
  const parsed = parseContentDocument(saved)
  return !parsed.ok || JSON.stringify(parsed.document) !== JSON.stringify(edited)
}

/**
 * A Card's authored fields: title, content, web address and summary (ADR 0037, decisions 17-23, 34-37, 56). They are guarded by the
 * Card's `revision`, exactly as a Pack's are: a stale edit is refused, nothing is merged, the Card is read again, the person's
 * edits stay in the form, and they are shown what was saved meanwhile before they choose to save over it or take it. The Type is
 * fixed and shown elsewhere; it is never a field here. Only what changed is sent, and the content is sent as the editor's canonical
 * document.
 */
export function CardDetailsSection({
  card,
  onSaved,
}: {
  card: ManagedCard
  onSaved: (next: ManagedCard) => void
}) {
  const [values, setValues] = useState<Values>(() => valuesOf(card))
  // The person's edit of the content, if they have made one: the document goes back to the editor so a re-read Card does not reset it.
  const [edited, setEdited] = useState<ContentDocument | null>(null)
  const [refusal, setRefusal] = useState<ContentRefusal | null>(null)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(ResourceProblem & { attempt: number }) | null>(null)
  const [conflict, setConflict] = useState<{ saved: ManagedCard; attempt: number } | null>(null)
  const { feedback, say, clear } = useFeedback()
  const form = useFocusFirstInvalid(problem?.attempt)
  const hasAddress = card.type !== 'file'

  function changes(): CardChanges {
    const out: CardChanges = {}
    if (values.title !== card.title) out.title = values.title
    if (edited !== null && contentDiffers(edited, card.content.document)) out.content = edited
    if (hasAddress && values.uri.trim() !== (card.uri ?? '')) {
      out.uri = values.uri.trim() === '' ? null : values.uri
    }
    if (values.summaryMode === 'derived') {
      if (card.summaryMode === 'custom') out.summaryMode = 'derived'
    } else if (card.summaryMode === 'derived' || values.summaryText.trim() !== card.summary) {
      out.summary = values.summaryText
    }
    return out
  }

  async function submit() {
    if (refusal !== null) {
      setProblem((previous) => ({
        message: 'The content cannot be saved as it is.',
        fields: { content: [refusal.message] },
        items: [],
        attempt: (previous?.attempt ?? 0) + 1,
      }))
      return
    }
    const sending = changes()
    if (Object.keys(sending).length === 0) {
      say('info', 'There is nothing to save: no field has changed.')
      return
    }
    setPending(true)
    clear()
    setProblem(null)
    const result = await updateCard(card.packId, card.id, card.revision, sending)
    if (result.ok) {
      setPending(false)
      setConflict(null)
      setEdited(null)
      onSaved(result.value)
      setValues(valuesOf(result.value))
      say('success', 'The Card was saved.')
      return
    }
    if (result.failure.kind === 'unauthenticated') {
      setPending(false)
      return
    }
    if (isStaleRevision(result.failure)) {
      const fresh = await getCard(card.packId, card.id)
      setPending(false)
      if (fresh.ok) {
        onSaved(fresh.value)
        setConflict((previous) => ({ saved: fresh.value, attempt: (previous?.attempt ?? 0) + 1 }))
        return
      }
      setProblem((previous) => ({
        ...describeResourcesFailure(fresh.failure, 'card'),
        attempt: (previous?.attempt ?? 0) + 1,
      }))
      return
    }
    setPending(false)
    const next = describeResourcesFailure(result.failure, 'card')
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  const fields = problem?.fields ?? {}

  return (
    <Panel
      title="Details"
      description="The Card’s title, content and summary. Its Type is fixed, and shown at the top of the page."
    >
      <form
        ref={form}
        aria-label="Card details"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void submit()
        }}
        className="flex flex-col gap-4"
      >
        <FeedbackAlert feedback={feedback} />
        {conflict !== null ? (
          <Alert key={conflict.attempt} tone="warning" focusOnMount>
            <p className="font-medium">Someone else saved changes to this Card first.</p>
            <p>
              Your edits are still in the form and have not been saved. The saved version (revision{' '}
              {conflict.saved.revision}, last edited by{' '}
              {conflict.saved.updatedBy.displayName ?? 'an unknown person'} on{' '}
              <time dateTime={conflict.saved.updatedAt}>{shown(conflict.saved.updatedAt)}</time>)
              has:
            </p>
            <PropertyList className="my-2">
              <Property term="Title">{conflict.saved.title}</Property>
              {hasAddress ? (
                <Property term="Web address">{conflict.saved.uri ?? 'None'}</Property>
              ) : null}
              <Property term="Summary">
                {conflict.saved.summary === '' ? 'None' : conflict.saved.summary}
              </Property>
            </PropertyList>
            <p>
              Its content may differ too. Save again to replace the saved fields with yours, or take
              the saved version and discard your edits.
            </p>
            <div className="mt-2">
              <Button
                size="sm"
                onClick={() => {
                  setValues(valuesOf(conflict.saved))
                  setEdited(null)
                  setRefusal(null)
                  setConflict(null)
                  say('info', 'The form now shows the saved version.')
                }}
              >
                Use the saved version
              </Button>
            </div>
          </Alert>
        ) : null}
        {problem && Object.keys(problem.fields).length === 0 ? (
          <Alert key={problem.attempt} tone="error" focusOnMount>
            {problem.message}
          </Alert>
        ) : null}

        <Field label="Title" error={fields.title?.join(' ')}>
          {(control) => (
            <Input
              {...control}
              name="title"
              value={values.title}
              required
              maxLength={CARD_TITLE_MAX}
              autoComplete="off"
              onChange={(event) => {
                setValues({ ...values, title: event.target.value })
              }}
            />
          )}
        </Field>

        {hasAddress ? (
          <Field
            label={card.type === 'external_link' ? 'Web address' : 'Related link (optional)'}
            hint="An http or https address. The platform never visits it."
            error={fields.uri?.join(' ')}
          >
            {(control) => (
              <Input
                {...control}
                name="uri"
                inputMode="url"
                value={values.uri}
                required={card.type === 'external_link'}
                autoComplete="off"
                spellCheck={false}
                onChange={(event) => {
                  setValues({ ...values, uri: event.target.value })
                }}
              />
            )}
          </Field>
        ) : null}

        <CardContentField
          label={card.type === 'basic' ? 'Content' : 'Description (optional)'}
          hint={
            card.type === 'basic'
              ? 'The Card’s text. It needs some before it can be published.'
              : 'Say what this Card is for.'
          }
          value={edited ?? card.content.document}
          onChange={setEdited}
          onRefusal={setRefusal}
          error={fields.content?.join(' ')}
        />

        <SummaryField
          mode={values.summaryMode}
          onModeChange={(summaryMode) => {
            setValues({ ...values, summaryMode })
          }}
          text={values.summaryText}
          onTextChange={(summaryText) => {
            setValues({ ...values, summaryText })
          }}
          saved={card.summary}
          error={fields.summary?.join(' ')}
        />

        <SubmitButton pending={pending} pendingLabel="Saving…">
          Save Card
        </SubmitButton>
      </form>
    </Panel>
  )
}
