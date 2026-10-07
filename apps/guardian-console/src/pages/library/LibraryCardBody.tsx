import { useState } from 'react'

import { mediaTypeLabel, sizeLabel } from '../../admin/resourcesWording.ts'
import type { DeliveredCard, DeliveredFile } from '../../api/resourceLibrary.ts'
import { RichContentRenderer } from '../../richtext/RichContentRenderer.tsx'
import { parseContentDocument } from '../../richtext/contract.ts'
import { Alert } from '../../ui/Alert.tsx'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Button } from '../../ui/Button.tsx'
import { Property, PropertyList } from '../../ui/PropertyList.tsx'

/** Whether a document draws nothing (an empty one). One that does not pass the profile is not blank: the renderer says it can't be shown. */
function isBlank(document: unknown): boolean {
  const parsed = parseContentDocument(document)
  return parsed.ok && parsed.document.content.length === 0
}

/**
 * What a Card is, drawn from what the library delivered and nothing else. The Type decides the shape:
 *
 * - Basic: its rich content (through the renderer, which refuses a document outside the profile as a whole), and a related link if it has one;
 * - External link: its description and a clear way to open the address, which the platform never visits itself;
 * - File: what the file is and how to view or download it, through the LIBRARY's own file route.
 *
 * A Card with no content of its own is described by its summary instead, so there is something to read; a Card with content is
 * not given its summary as well, which would only say the same thing twice.
 */
export function LibraryCardBody({ card }: { card: DeliveredCard }) {
  const blank = isBlank(card.document)

  return (
    <div className="flex flex-col gap-4">
      {blank ? (
        card.summary !== '' ? (
          <p className="text-body wrap-anywhere text-foreground">{card.summary}</p>
        ) : null
      ) : (
        <RichContentRenderer document={card.document} />
      )}
      {card.type === 'file' ? (
        card.file === null ? (
          <Alert tone="warning">This file cannot be shown right now.</Alert>
        ) : (
          <FileBody card={card} file={card.file} />
        )
      ) : card.uri !== null ? (
        <ExternalAction
          uri={card.uri}
          title={card.title}
          primary={card.type === 'external_link'}
          label={card.type === 'external_link' ? 'Open link' : 'Open related link'}
        />
      ) : null}
    </div>
  )
}

/** The addresses a Card may offer. The platform has already checked it; this holds the line again before putting it in a link. */
function externalHref(uri: string): { href: string; host: string } | null {
  if (!/^https?:\/\//i.test(uri)) return null
  try {
    const url = new URL(uri)
    return url.protocol === 'https:' || url.protocol === 'http:'
      ? { href: uri, host: url.host }
      : null
  } catch {
    return null
  }
}

function ExternalAction({
  uri,
  title,
  primary,
  label,
}: {
  uri: string
  title: string
  primary: boolean
  label: string
}) {
  const target = externalHref(uri)
  if (target === null) return null

  return (
    <div className="flex flex-col items-start gap-1.5">
      <a
        href={target.href}
        target="_blank"
        rel="noopener noreferrer"
        className={buttonVariants({ variant: primary ? 'primary' : 'secondary' })}
      >
        {label}
        <span className="sr-only">
          : {title} (opens {target.host} in a new tab)
        </span>
      </a>
      <p className="text-meta wrap-anywhere text-muted-foreground">
        Opens {target.host} in a new tab.
      </p>
    </div>
  )
}

const RASTER_IMAGES: readonly string[] = ['image/png', 'image/jpeg', 'image/webp', 'image/gif']

/**
 * A File Card's file, served by the library's route (the path the API named, never one built here). A raster image is shown in the
 * Card with `<img>`, which the platform's policy allows, rather than by opening the file in a page of its own. A PDF is opened in a new
 * tab to be viewed; everything else is a download. Nothing is fetched into the page to preview it.
 *
 * The answer does not say whether the file is still in the store: if it is not, the platform answers `asset_unavailable` when the
 * file is asked for. An image that cannot be loaded is therefore met with a message and a way to ask again, and no link to a file
 * already known not to be there.
 */
function FileBody({ card, file }: { card: DeliveredCard; file: DeliveredFile }) {
  const path = file.downloadPath
  const isImage = RASTER_IMAGES.includes(file.mediaType)
  const isPdf = file.mediaType === 'application/pdf'
  const [broken, setBroken] = useState(false)
  const [round, setRound] = useState(0)

  if (path === null) return <Alert tone="warning">This file cannot be shown right now.</Alert>

  return (
    <div className="flex flex-col gap-4">
      {isImage ? (
        broken ? (
          <div className="flex flex-col items-start gap-3">
            <Alert tone="warning">This image cannot be shown right now.</Alert>
            <Button
              onClick={() => {
                setBroken(false)
                setRound((n) => n + 1)
              }}
            >
              Try again
            </Button>
          </div>
        ) : (
          <img
            key={round}
            src={`${path}?disposition=inline`}
            alt={card.title}
            loading="lazy"
            onError={() => {
              setBroken(true)
            }}
            className="h-auto max-w-full self-start rounded-md border border-border"
          />
        )
      ) : null}

      <PropertyList>
        <Property term="File name">{file.name}</Property>
        <Property term="Type">{mediaTypeLabel(file.mediaType)}</Property>
        <Property term="Size">{sizeLabel(file.byteSize)}</Property>
      </PropertyList>

      {isImage && broken ? null : (
        <div className="flex flex-wrap items-center gap-2">
          {isPdf ? (
            <a
              href={`${path}?disposition=inline`}
              target="_blank"
              rel="noopener noreferrer"
              className={buttonVariants({ variant: 'primary' })}
            >
              View PDF<span className="sr-only">: {file.name} (opens in a new tab)</span>
            </a>
          ) : null}
          <a href={path} className={buttonVariants({ variant: isPdf ? 'secondary' : 'primary' })}>
            Download<span className="sr-only"> {file.name}</span>
          </a>
        </div>
      )}
    </div>
  )
}
