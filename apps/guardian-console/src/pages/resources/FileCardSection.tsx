import { useState, type SyntheticEvent } from 'react'

import {
  describeResourcesFailure,
  mediaTypeLabel,
  opensInBrowser,
  sizeLabel,
} from '../../admin/resourcesWording.ts'
import { shown } from '../../admin/time.ts'
import { replaceCardFile, type ManagedCard, type ManagedFile } from '../../api/resources.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { Property, PropertyList } from '../../ui/PropertyList.tsx'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { FileField } from './FileField.tsx'
import { useFeedback } from './useFeedback.ts'

/**
 * A File Card's one managed file (ADR 0037, decisions 62-66): what it is called, what kind of file the server found it to be, how
 * big it is, who uploaded it and when, and a way to download it and to replace it. The file is only ever DESCRIBED here and
 * fetched through the MANAGEMENT route, which serves Drafts (the library's route does not). No storage key, disk or path exists in
 * what the server sends, so none is shown.
 *
 * Replacing it does not use the Card's revision and asks for no recent verification: the later replacement wins. The old file
 * stays the Card's until the server says the new one is in; a refusal or a failure leaves it exactly as it was.
 */
export function FileCardSection({
  card,
  file,
  onReplaced,
}: {
  card: ManagedCard
  file: ManagedFile
  onReplaced: (next: ManagedCard) => void
}) {
  const [chosen, setChosen] = useState<File | null>(null)
  const [pending, setPending] = useState(false)
  const [fieldError, setFieldError] = useState<string | undefined>(undefined)
  // Changing it clears the file input after a replacement, which a file input cannot otherwise be told to do.
  const [round, setRound] = useState(0)
  const { feedback, say, clear } = useFeedback()

  async function replace() {
    if (chosen === null) {
      setFieldError('Choose the replacement file.')
      return
    }
    setPending(true)
    clear()
    setFieldError(undefined)
    const result = await replaceCardFile(card.packId, card.id, chosen)
    setPending(false)
    if (result.ok) {
      onReplaced(result.value)
      setChosen(null)
      setRound((n) => n + 1)
      say('success', 'The file was replaced. The Card now offers the new file.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const problem = describeResourcesFailure(result.failure, 'file')
    say('error', `${problem.message} The Card still has its current file.`, problem.items)
  }

  return (
    <Panel
      title="File"
      description="The one file this Card offers. Replacing it replaces it for everyone who can open the Card."
    >
      <div className="flex flex-col gap-4">
        <PropertyList>
          <Property term="File name">{file.name}</Property>
          <Property term="Type">{mediaTypeLabel(file.mediaType)}</Property>
          <Property term="Size">{sizeLabel(file.byteSize)}</Property>
          <Property term="Uploaded">
            by {file.uploadedBy.displayName ?? 'an unknown person'} on{' '}
            <time dateTime={file.uploadedAt}>{shown(file.uploadedAt)}</time>
          </Property>
        </PropertyList>

        {file.available ? (
          <div className="flex flex-wrap items-center gap-2">
            <a href={file.downloadPath} className={buttonVariants({})}>
              Download <span className="sr-only">{file.name}</span>
            </a>
            {opensInBrowser(file.mediaType) ? (
              <a
                href={`${file.downloadPath}?disposition=inline`}
                target="_blank"
                rel="noopener noreferrer"
                className={buttonVariants({})}
              >
                View <span className="sr-only">{file.name} in a new tab</span>
              </a>
            ) : null}
          </div>
        ) : (
          <Alert tone="warning">
            This file is on record but is missing from the store (after a partial restore, for
            example), so it cannot be downloaded or published. Replace it with a new file.
          </Alert>
        )}

        <FeedbackAlert feedback={feedback} />
        <form
          aria-label="Replace the file"
          onSubmit={(event: SyntheticEvent) => {
            event.preventDefault()
            void replace()
          }}
          className="flex flex-col gap-3"
        >
          <FileField
            label="Replacement file"
            inputKey={round}
            error={fieldError}
            onChange={(next) => {
              setChosen(next)
              setFieldError(undefined)
            }}
          />
          <Button
            type="submit"
            variant="primary"
            className="self-start"
            pending={pending}
            pendingLabel="Uploading…"
          >
            Replace file
          </Button>
        </form>
      </div>
    </Panel>
  )
}
