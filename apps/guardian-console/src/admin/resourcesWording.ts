import type { Audience, CardType, PublicationState } from '../api/resources.ts'
import type { Failure } from '../api/http.ts'
import { describeFailure, type Problem } from '../ui/problem.ts'

// The Console's own wording for the Resources API's stable codes (ADR 0037). It never shows the server's sentence for a
// refusal it can name: the code says what happened, and this says what it means to a Guardian. Only a 422's field messages
// (written for people, and carrying no file name or type) are passed through, as everywhere else in the Console.

/** What a Guardian is told: the sentence, the fields it concerns, and, when a refusal names several things, those things. */
export interface ResourceProblem extends Problem {
  items: string[]
}

/** What a request was about, for the answers (404, 413, 503) that do not say it themselves. */
export type ResourceSubject = 'category' | 'pack' | 'card' | 'file' | 'preview'

export const MAX_FILE_MB = 20

/** The `accept` hint for the file input: a convenience for the file picker, never the check (the server judges the content). */
export const FILE_ACCEPT =
  '.pdf,.png,.jpg,.jpeg,.webp,.gif,.txt,.csv,.docx,.xlsx,.pptx,application/pdf,image/png,image/jpeg,image/webp,image/gif,text/plain,text/csv'

export const FILE_TYPES_HELP = 'PDF, PNG, JPEG, WebP, GIF, TXT, CSV, DOCX, XLSX or PPTX'

export const FILE_HELP = `${FILE_TYPES_HELP}, up to ${String(MAX_FILE_MB)} MB. The server checks what the file really is, whatever it is called.`

const packUnmet: Record<string, string> = {
  category: 'Choose a Category.',
  audience: 'Choose at least one audience.',
  published_card: 'Publish at least one Card.',
}

const cardUnmet: Record<string, string> = {
  content: 'Write some text in the content.',
  uri: 'Give it a web address.',
  file: 'Its file is missing from the store: replace it first.',
}

const publishedPackRequirement: Record<string, string> = {
  category:
    'A Published Pack must keep its Category. Unpublish the Pack first, or choose another Category instead of clearing it.',
  audience:
    'A Published Pack must keep at least one audience. Unpublish the Pack first, or choose another audience before removing the last one.',
  published_card:
    'A Published Pack must keep at least one Published Card. Unpublish the Pack first, or publish another Card before unpublishing or deleting this one.',
}

const conflicts: Record<string, string> = {
  stale_revision: 'Someone else changed this while you were editing it.',
  order_mismatch:
    'The list changed while you were reordering it (someone added, removed or moved something). It has been reloaded: check the order and try again.',
  duplicate_category: 'A Category with that name already exists.',
  category_not_empty:
    'That Category still has Packs in it, so it was not deleted. Move or delete those Packs first.',
  card_limit_reached: 'A Pack can hold at most 100 Cards.',
  card_audience_conflict:
    'That would leave a narrowed Card with an audience its Pack no longer has. Change those Cards first.',
}

const notFound: Record<string, string> = {
  category_not_found: 'That Category no longer exists. It may have been deleted.',
  pack_not_found: 'That Resource Pack no longer exists. It may have been deleted.',
  card_not_found: 'That Card no longer exists. It may have been deleted.',
  resource_pack_not_found: 'That Resource Pack could not be found.',
  asset_unavailable:
    'That file is not in the store, so it cannot be downloaded. Replace it with a new file.',
}

const notFoundBySubject: Record<ResourceSubject, string> = {
  category: notFound.category_not_found ?? '',
  pack: notFound.pack_not_found ?? '',
  card: notFound.card_not_found ?? '',
  file: notFound.card_not_found ?? '',
  preview: notFound.pack_not_found ?? '',
}

const bullets = (items: readonly string[] | undefined, table: Record<string, string>): string[] =>
  (items ?? []).map((item) => table[item] ?? item)

/**
 * What to tell a Guardian about a failed Resources request. `subject` words the answers that do not name their own subject:
 * which 404 it was, and what a 413 or a 503 means for an upload (they are only ever asked of one).
 */
export function describeResourcesFailure(
  failure: Failure,
  subject: ResourceSubject = 'pack',
): ResourceProblem {
  switch (failure.kind) {
    case 'not-found':
      return {
        message:
          (failure.code !== undefined ? notFound[failure.code] : undefined) ??
          notFoundBySubject[subject],
        fields: {},
        items: [],
      }
    case 'too-large':
      return {
        message: `That file is too large. The limit is ${String(MAX_FILE_MB)} MB.`,
        fields: { file: [`That file is too large. The limit is ${String(MAX_FILE_MB)} MB.`] },
        items: [],
      }
    case 'unavailable':
    case 'network':
      return subject === 'file'
        ? {
            message:
              'The file could not be stored just now. Nothing was changed. Try again in a moment.',
            fields: {},
            items: [],
          }
        : { ...describeFailure(failure), items: [] }
    case 'invalid':
      if (failure.code === 'file_type_not_allowed') {
        return {
          message: `That file type is not allowed, or the file is not what its name says. Allowed: ${FILE_TYPES_HELP}.`,
          fields: {
            file: [
              `That file type is not allowed, or the file is not what its name says. Allowed: ${FILE_TYPES_HELP}.`,
            ],
          },
          items: [],
        }
      }
      if (failure.code === 'unknown_category') {
        return {
          message: 'That Category no longer exists. Choose another.',
          fields: { category_id: ['Choose a Category from the list.'] },
          items: [],
        }
      }
      if (failure.code === 'card_audience_not_subset') {
        return {
          message: 'A Card can only be narrowed to audiences its Pack has.',
          fields: {},
          items: [],
        }
      }
      return { ...describeFailure(failure), items: [] }
    case 'conflict':
      return describeConflict(failure)
    default:
      return { ...describeFailure(failure), items: [] }
  }
}

function describeConflict(failure: Extract<Failure, { kind: 'conflict' }>): ResourceProblem {
  const { code, detail } = failure
  if (code === 'published_pack_requirement') {
    return {
      message:
        publishedPackRequirement[detail?.requirement ?? ''] ??
        'That would leave a Published Pack without something it needs. Unpublish the Pack first.',
      fields: {},
      items: [],
    }
  }
  if (code === 'pack_not_publishable') {
    return {
      message: 'The Pack cannot be published yet. It still needs:',
      fields: {},
      items: bullets(detail?.unmet, packUnmet),
    }
  }
  if (code === 'card_not_publishable') {
    return {
      message: 'The Card cannot be published yet. It still needs:',
      fields: {},
      items: bullets(detail?.unmet, cardUnmet),
    }
  }
  return {
    message: conflicts[code] ?? 'That could not be done in the current state. Reload and check.',
    fields: {},
    items: [],
  }
}

/** Whether a failure says the thing being edited moved on (so it should be read again), not that the input was wrong. */
export function isStaleRevision(failure: Failure): boolean {
  return failure.kind === 'conflict' && failure.code === 'stale_revision'
}

export function isOrderMismatch(failure: Failure): boolean {
  return failure.kind === 'conflict' && failure.code === 'order_mismatch'
}

const audienceLabels: Record<Audience, string> = { guardian: 'Guardians', member: 'Members' }
export function audienceLabel(audience: Audience): string {
  return audienceLabels[audience]
}

export const MEMBER_DELIVERY_NOTE =
  'Member content can be written, targeted and previewed here. Nothing delivers it to Members yet: the Member-facing surface comes later, so a Pack aimed only at Members does not appear in any Guardian’s library.'

const stateLabels: Record<PublicationState, string> = { draft: 'Draft', published: 'Published' }
export function stateLabel(state: PublicationState): string {
  return stateLabels[state]
}

const typeLabels: Record<CardType, string> = {
  basic: 'Basic',
  external_link: 'External link',
  file: 'File',
}
export function cardTypeLabel(type: CardType): string {
  return typeLabels[type]
}

const mediaLabels: Record<string, string> = {
  'application/pdf': 'PDF',
  'image/png': 'PNG image',
  'image/jpeg': 'JPEG image',
  'image/webp': 'WebP image',
  'image/gif': 'GIF image',
  'text/plain': 'Text file',
  'text/csv': 'CSV file',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word document',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel workbook',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation':
    'PowerPoint presentation',
}

/** A file's type in words. A type the Console does not know (a future one) is shown as-is. */
export function mediaTypeLabel(mediaType: string): string {
  return mediaLabels[mediaType] ?? mediaType
}

/** A size in the units a person reads: "812 bytes", "1.4 MB". */
export function sizeLabel(bytes: number): string {
  if (bytes < 1024) return `${String(bytes)} ${bytes === 1 ? 'byte' : 'bytes'}`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** Whether the management download may be opened in the browser: a PDF, which Chromium shows (images are for WP5's <img>). */
export function opensInBrowser(mediaType: string): boolean {
  return mediaType === 'application/pdf'
}
