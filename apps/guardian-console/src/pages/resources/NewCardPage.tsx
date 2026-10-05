import { useCallback, useId, useState, type SyntheticEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'

import {
  cardTypeLabel,
  describeResourcesFailure,
  type ResourceProblem,
} from '../../admin/resourcesWording.ts'
import {
  createFileCard,
  createJsonCard,
  getPack,
  type CardType,
  type SummaryMode,
} from '../../api/resources.ts'
import {
  EMPTY_DOCUMENT,
  type ContentDocument,
  type ContentRefusal,
} from '../../richtext/contract.ts'
import { useBreadcrumbLeaf } from '../../shell/breadcrumb-leaf.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Field } from '../../ui/Field.tsx'
import { Input } from '../../ui/Input.tsx'
import { Page } from '../../ui/Page.tsx'
import { PageHeader } from '../../ui/PageHeader.tsx'
import { SkeletonRegion, SkeletonText } from '../../ui/Skeleton.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { useLoad } from '../../ui/useLoad.ts'
import { CARD_TITLE_MAX } from './cardLimits.ts'
import { CardContentField } from './CardContentField.tsx'
import { FileField } from './FileField.tsx'
import { SummaryField } from './SummaryField.tsx'
import { useFocusFirstInvalid } from './useFocusFirstInvalid.ts'

const TYPE_HELP: Record<CardType, string> = {
  basic: 'The written content is the resource. A related link is optional.',
  external_link:
    'Points to a web page. The platform never visits the address; people follow it themselves.',
  file: 'Offers one file to download: a PDF, an image, a text or CSV file, or a Word, Excel or PowerPoint document.',
}

const CHOICES: readonly CardType[] = ['basic', 'external_link', 'file']

/**
 * Adds a Card to a Pack (ADR 0037, decisions 17-23). The Card's Type is chosen here and is fixed for good: a different Type is a
 * different Card. A File Card is created WITH its file, in one multipart request, and cannot exist without one. Every Card starts
 * as a Draft that inherits its Pack's audiences; publishing it is a separate, deliberate step on the Card's own page.
 */
export function NewCardPage() {
  const { packId = '' } = useParams()
  const navigate = useNavigate()
  const typeName = useId()
  const loadPack = useCallback((signal: AbortSignal) => getPack(packId, signal), [packId])
  const [pack] = useLoad(loadPack)
  useBreadcrumbLeaf(pack.status === 'loaded' ? `New Card in ${pack.value.title}` : undefined)

  const [type, setType] = useState<CardType>('basic')
  const [title, setTitle] = useState('')
  const [content, setContent] = useState<ContentDocument | null>(null)
  const [refusal, setRefusal] = useState<ContentRefusal | null>(null)
  const [uri, setUri] = useState('')
  const [summaryMode, setSummaryMode] = useState<SummaryMode>('derived')
  const [summaryText, setSummaryText] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [pending, setPending] = useState(false)
  const [problem, setProblem] = useState<(ResourceProblem & { attempt: number }) | null>(null)
  const form = useFocusFirstInvalid(problem?.attempt)

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
    if (type === 'file' && file === null) {
      setProblem((previous) => ({
        message: 'A File Card needs its file.',
        fields: { file: ['Choose the file this Card offers.'] },
        items: [],
        attempt: (previous?.attempt ?? 0) + 1,
      }))
      return
    }

    setPending(true)
    const summary = summaryMode === 'custom' ? summaryText : null
    const result =
      type === 'file' && file !== null
        ? await createFileCard(packId, { title, file, content, summary })
        : await createJsonCard(packId, {
            type: type === 'external_link' ? 'external_link' : 'basic',
            title,
            content,
            uri: uri.trim() === '' ? null : uri,
            summary,
          })
    setPending(false)
    if (result.ok) {
      void navigate(`/resources/packs/${packId}/cards/${result.value.id}`, {
        state: {
          notice: 'The Card was created as a Draft. Publish it when it is ready.',
        },
      })
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const next = describeResourcesFailure(result.failure, type === 'file' ? 'file' : 'card')
    setProblem((previous) => ({ ...next, attempt: (previous?.attempt ?? 0) + 1 }))
  }

  if (pack.status === 'loading') {
    return (
      <Page width="form">
        <SkeletonRegion label="Loading the Resource Pack…" visibleLabel>
          <SkeletonText lines={4} />
        </SkeletonRegion>
      </Page>
    )
  }
  if (pack.status === 'failed') {
    return (
      <Page width="form">
        <PageHeader title="Add a Card" />
        <Alert tone="error">{describeResourcesFailure(pack.failure, 'pack').message}</Alert>
        <Link to="/resources" className={buttonVariants({})}>
          All Resource Packs
        </Link>
      </Page>
    )
  }

  const fields = problem?.fields ?? {}

  return (
    <Page width="form">
      <PageHeader
        title="Add a Card"
        description={
          <span className="wrap-anywhere">
            To{' '}
            <Link to={`/resources/packs/${packId}`} className="underline underline-offset-2">
              {pack.value.title}
            </Link>
            . It starts as a Draft.
          </span>
        }
      />
      <form
        ref={form}
        aria-label="Add a Card"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void submit()
        }}
        className="flex flex-col gap-5"
      >
        {problem && Object.keys(problem.fields).length === 0 ? (
          <Alert key={problem.attempt} tone="error" focusOnMount>
            {problem.message}
          </Alert>
        ) : null}

        <fieldset className="flex flex-col gap-2">
          <legend className="text-label font-medium text-foreground">Type of Card</legend>
          <p className="text-meta text-muted-foreground">
            The type cannot be changed once the Card exists. To use a different type, add another
            Card.
          </p>
          {CHOICES.map((choice) => (
            <label key={choice} className="flex items-start gap-2.5 text-body text-foreground">
              <input
                type="radio"
                name={typeName}
                checked={type === choice}
                onChange={() => {
                  setType(choice)
                  setProblem(null)
                }}
                className="mt-0.5 size-4 shrink-0 accent-primary"
              />
              <span>
                {cardTypeLabel(choice)}
                <span className="block text-meta text-muted-foreground">{TYPE_HELP[choice]}</span>
              </span>
            </label>
          ))}
        </fieldset>

        <Field label="Title" error={fields.title?.join(' ')}>
          {(control) => (
            <Input
              {...control}
              name="title"
              value={title}
              required
              maxLength={CARD_TITLE_MAX}
              autoComplete="off"
              onChange={(event) => {
                setTitle(event.target.value)
              }}
            />
          )}
        </Field>

        {type === 'file' ? (
          <FileField label="File" required onChange={setFile} error={fields.file?.join(' ')} />
        ) : (
          <Field
            label={type === 'external_link' ? 'Web address' : 'Related link (optional)'}
            hint="An http or https address. The platform never visits it."
            error={fields.uri?.join(' ')}
          >
            {(control) => (
              <Input
                {...control}
                name="uri"
                inputMode="url"
                value={uri}
                required={type === 'external_link'}
                autoComplete="off"
                spellCheck={false}
                onChange={(event) => {
                  setUri(event.target.value)
                }}
              />
            )}
          </Field>
        )}

        <CardContentField
          label={type === 'basic' ? 'Content' : 'Description (optional)'}
          hint={
            type === 'basic'
              ? 'The Card’s text. It needs some before it can be published.'
              : 'Say what this Card is for.'
          }
          value={content ?? EMPTY_DOCUMENT}
          onChange={setContent}
          onRefusal={setRefusal}
          error={fields.content?.join(' ')}
        />

        <SummaryField
          mode={summaryMode}
          onModeChange={setSummaryMode}
          text={summaryText}
          onTextChange={setSummaryText}
          error={fields.summary?.join(' ')}
        />

        <div className="flex flex-wrap gap-3">
          <SubmitButton
            pending={pending}
            pendingLabel={type === 'file' ? 'Uploading…' : 'Creating…'}
          >
            Create Card
          </SubmitButton>
          <Link to={`/resources/packs/${packId}`} className={buttonVariants({})}>
            Cancel
          </Link>
        </div>
      </form>
    </Page>
  )
}
