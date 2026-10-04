// The Console's side of the Resources document profile v1 (ADR 0037, decisions 25-29).
//
// The server is the authority: `DocumentProfile` (apps/platform) parses every submitted document into
// an allowlist or refuses it with `invalid_content`, and never strips. This module is the same
// allowlist in TypeScript. It exists for two jobs, and neither makes the Console an authority:
//
//   - the editor emits its document through `canonicalDocument`, so what the Console sends is already
//     in the form the server stores (defaults dropped, marks in the fixed order) and a defect in the
//     editor's configuration surfaces here, in a test, rather than as a refusal in production;
//   - the renderer draws only a document that passes it, so an unknown node, mark or attribute is
//     refused in the browser too, instead of being drawn on trust.
//
// The two implementations are kept honest by one shared corpus (apps/platform/tests/Fixtures/
// resource-content): every valid file must come out of `canonicalDocument` as the server stores it,
// and every invalid file must be refused at the path the server names. Change the profile on the
// server and those tests fail here until this file follows.
//
// Pure and deterministic. HTML is never an input, an output or a stored form.

export const CONTENT_FORMAT = 'prosemirror'
export const CONTENT_VERSION = 1

/** Canonical JSON, in UTF-8 bytes, a document may occupy. */
export const MAX_BYTES = 262_144
/** How deeply nodes may nest. */
export const MAX_DEPTH = 24

export const MAX_SPAN = 20

export interface ContentMark {
  type: 'bold' | 'italic' | 'underline' | 'code' | 'link'
  attrs?: { href: string }
}

export interface ContentNode {
  type: string
  attrs?: Record<string, number>
  content?: ContentNode[]
  text?: string
  marks?: ContentMark[]
}

export interface ContentDocument {
  type: 'doc'
  content: ContentNode[]
}

/** The document a Card with no content holds. */
export const EMPTY_DOCUMENT: ContentDocument = { type: 'doc', content: [] }

/** A document outside the profile. The message names where and why, never the offending value. */
export class ContentRefusal extends Error {
  readonly path: string

  constructor(path: string, why: string) {
    super(`The content is not in the allowed format: ${path} ${why}.`)
    this.name = 'ContentRefusal'
    this.path = path
  }
}

type Json = Record<string, unknown>

const BLOCKS = [
  'paragraph',
  'heading',
  'bulletList',
  'orderedList',
  'blockquote',
  'horizontalRule',
  'codeBlock',
  'table',
]

/** The fixed default values of the attributes that are accepted but not stored, per node or mark type. */
const DROPPED_DEFAULTS: Record<string, Record<string, unknown[]>> = {
  codeBlock: { language: [null] },
  orderedList: { type: [null] },
  tableHeader: { colwidth: [null] },
  tableCell: { colwidth: [null] },
  link: {
    target: [null, '_blank'],
    rel: [null, 'noopener noreferrer nofollow', 'noopener noreferrer'],
    class: [null],
  },
}

const MARK_ORDER = ['bold', 'italic', 'underline', 'code', 'link'] as const

/**
 * Parses a document into the profile and returns it in canonical form: fixed key order, fixed mark
 * order, editor defaults dropped. The same document the server stores.
 *
 * @throws ContentRefusal naming the path of the first offence
 */
export function canonicalDocument(input: unknown): ContentDocument {
  if (!isObject(input) || input.type !== 'doc') {
    throw refuse('content', 'must be a document (type "doc")')
  }
  onlyKeys(input, ['type', 'content'], 'content')

  const content = children(input, 'content', 'doc', 1)
  const document: ContentDocument = { type: 'doc', content }

  if (new TextEncoder().encode(JSON.stringify(document)).length > MAX_BYTES) {
    throw new ContentRefusal(
      'content',
      `is too long: it is limited to ${String(MAX_BYTES / 1024)} KiB`,
    )
  }

  return document
}

/** The same check as a result, for a caller that must not throw (the renderer). */
export function parseContentDocument(
  input: unknown,
): { ok: true; document: ContentDocument } | { ok: false; refusal: ContentRefusal } {
  try {
    return { ok: true, document: canonicalDocument(input) }
  } catch (error) {
    if (error instanceof ContentRefusal) return { ok: false, refusal: error }
    throw error
  }
}

/**
 * The one rule for an external address in a link (ExternalUri on the server): `https` or `http` with
 * a host, or `mailto:` with one plain address. Returns the normalised address (the scheme
 * lower-cased, nothing else rewritten) or null. Never fetches anything.
 */
export function normaliseLinkHref(raw: string): string | null {
  const uri = raw.replace(/^[ \t\n\r\v\f]+|[ \t\n\r\v\f]+$/g, '')
  if (uri === '' || Array.from(uri).length > 2048) return null
  if (/[\p{Cc}\p{Cs}\s\\<>"]/u.test(uri)) return null

  const label = '[\\p{L}\\p{N}](?:[\\p{L}\\p{N}-]*[\\p{L}\\p{N}])?'
  const mail = new RegExp(`^mailto:([^@?#%\\s]+@${label}(?:\\.${label})+)$`, 'iu').exec(uri)
  if (mail?.[1] !== undefined) return `mailto:${mail[1]}`

  const parts = /^(https?):\/\/([^/?#]*)([/?#].*)?$/isu.exec(uri)
  const scheme = parts?.[1]
  const authority = parts?.[2]
  if (scheme === undefined || authority === undefined) return null
  if (authority === '' || authority.includes('@') || !validAuthority(authority, label)) return null

  return `${scheme.toLowerCase()}://${authority}${parts?.[3] ?? ''}`
}

function validAuthority(authority: string, label: string): boolean {
  if (authority.startsWith('[')) {
    const v6 = /^\[[0-9A-Fa-f:.]+\](?::(\d{1,5}))?$/.exec(authority)
    return v6 !== null && validPort(v6[1])
  }
  const host = /^([^:]+)(?::(\d{1,5}))?$/u.exec(authority)
  if (host?.[1] === undefined) return false

  return new RegExp(`^${label}(?:\\.${label})*\\.?$`, 'u').test(host[1]) && validPort(host[2])
}

function validPort(port: string | undefined): boolean {
  return port === undefined || port === '' || (Number(port) >= 1 && Number(port) <= 65_535)
}

function node(input: Json, path: string, parent: string, depth: number): ContentNode {
  if (depth > MAX_DEPTH) throw refuse(path, 'is nested too deeply')
  const type = input.type
  if (typeof type !== 'string') throw refuse(path, 'has no type')

  switch (type) {
    case 'paragraph':
      return textBlock(input, path, 'paragraph', parent, {}, depth)
    case 'heading':
      return textBlock(input, path, 'heading', parent, { level: level(input, path) }, depth)
    case 'bulletList':
    case 'blockquote':
    case 'listItem':
    case 'table':
    case 'tableRow':
      return container(input, path, type, parent, {}, depth)
    case 'orderedList':
      return container(input, path, type, parent, orderedStart(input, path), depth)
    case 'tableHeader':
    case 'tableCell':
      return container(input, path, type, parent, spans(input, path, type), depth)
    case 'horizontalRule':
      return leaf(input, path, type, parent)
    case 'codeBlock':
      return codeBlock(input, path, parent)
    case 'text':
      return text(input, path, parent)
    case 'hardBreak':
      return leaf(input, path, type, parent)
    default:
      throw refuse(path, 'has a node type the profile does not allow')
  }
}

function textBlock(
  input: Json,
  path: string,
  type: string,
  parent: string,
  attrs: Record<string, number>,
  depth: number,
): ContentNode {
  place(type, parent, path)
  onlyKeys(input, ['type', 'attrs', 'content'], path)
  if (type === 'paragraph') noAttributes(input, path, type) // a heading's `level` was read by the caller
  const out: ContentNode = { type }
  if (Object.keys(attrs).length > 0) out.attrs = attrs
  const kids = children(input, path, type, depth + 1)
  if (kids.length > 0) out.content = kids

  return out
}

function container(
  input: Json,
  path: string,
  type: string,
  parent: string,
  attrs: Record<string, number>,
  depth: number,
): ContentNode {
  place(type, parent, path)
  onlyKeys(input, ['type', 'attrs', 'content'], path)
  if (!['orderedList', 'tableHeader', 'tableCell'].includes(type)) noAttributes(input, path, type)
  const kids = children(input, path, type, depth + 1)
  const first = kids[0]
  if (first === undefined) throw refuse(path, 'must not be empty')
  if (type === 'listItem' && first.type !== 'paragraph') {
    throw refuse(`${path}.content[0]`, 'a list item starts with a paragraph')
  }
  const out: ContentNode = { type }
  if (Object.keys(attrs).length > 0) out.attrs = attrs
  out.content = kids

  return out
}

function leaf(input: Json, path: string, type: string, parent: string): ContentNode {
  place(type, parent, path)
  onlyKeys(input, ['type', 'attrs'], path)
  noAttributes(input, path, type)

  return { type }
}

function codeBlock(input: Json, path: string, parent: string): ContentNode {
  place('codeBlock', parent, path)
  onlyKeys(input, ['type', 'attrs', 'content'], path)
  noAttributes(input, path, 'codeBlock')
  const kids: ContentNode[] = []
  list(input, 'content', path).forEach((child, i) => {
    const at = `${path}.content[${String(i)}]`
    if (!isObject(child)) throw refuse(at, 'must be an object')
    if (child.type !== 'text' || child.marks !== undefined) {
      throw refuse(at, 'code holds plain text only')
    }
    kids.push(text(child, at, 'codeBlock'))
  })
  const out: ContentNode = { type: 'codeBlock' }
  if (kids.length > 0) out.content = kids

  return out
}

function text(input: Json, path: string, parent: string): ContentNode {
  place('text', parent, path)
  onlyKeys(input, ['type', 'text', 'marks'], path)
  const value = input.text
  if (typeof value !== 'string' || value === '') throw refuse(path, 'holds no text')
  // A tab is ordinary; a line feed is a line break only inside code (elsewhere a hardBreak says it).
  // Nothing else is a control character, a line or paragraph separator, or a lone surrogate.
  const allowed = parent === 'codeBlock' ? /[\t\n]/g : /\t/g
  if (/[\p{Cc}\p{Cs}\p{Zl}\p{Zp}]/u.test(value.replace(allowed, ''))) {
    throw refuse(path, 'contains a character the profile does not allow')
  }

  const out: ContentNode = { type: 'text', text: value }
  const marks = parseMarks(input, path)
  if (marks.length > 0) out.marks = marks

  return out
}

function parseMarks(input: Json, path: string): ContentMark[] {
  const found = new Map<ContentMark['type'], ContentMark>()
  list(input, 'marks', path).forEach((mark, i) => {
    const at = `${path}.marks[${String(i)}]`
    if (!isObject(mark) || typeof mark.type !== 'string') throw refuse(at, 'is not a mark')
    const type = MARK_ORDER.find((known) => known === mark.type)
    if (type === undefined) throw refuse(at, 'has a mark type the profile does not allow')
    if (found.has(type)) throw refuse(at, 'repeats a mark')
    onlyKeys(mark, ['type', 'attrs'], at)
    if (type === 'link') {
      found.set(type, { type, attrs: { href: href(mark, at) } })
    } else {
      noAttributes(mark, at, type)
      found.set(type, { type })
    }
  })

  return MARK_ORDER.flatMap((type) => {
    const mark = found.get(type)
    return mark === undefined ? [] : [mark]
  })
}

function href(mark: Json, path: string): string {
  const attrs = attributes(mark, `${path}.attrs`)
  const raw = attrs.href
  const uri = typeof raw === 'string' ? normaliseLinkHref(raw) : null
  if (uri === null) throw refuse(`${path}.attrs.href`, 'is not an allowed link address')
  droppedDefaults(attrs, 'link', `${path}.attrs`, ['href'])

  return uri
}

function level(input: Json, path: string): number {
  const attrs = attributes(input, `${path}.attrs`)
  const value = attrs.level
  if (typeof value !== 'number' || !Number.isInteger(value) || value < 2 || value > 4) {
    throw refuse(`${path}.attrs.level`, 'must be 2, 3 or 4')
  }
  droppedDefaults(attrs, 'heading', `${path}.attrs`, ['level'])

  return value
}

function orderedStart(input: Json, path: string): Record<string, number> {
  const attrs = attributes(input, `${path}.attrs`)
  const start = attrs.start ?? 1
  if (typeof start !== 'number' || !Number.isInteger(start) || start < 1 || start > 1_000_000) {
    throw refuse(`${path}.attrs.start`, 'must be a positive whole number')
  }
  droppedDefaults(attrs, 'orderedList', `${path}.attrs`, ['start'])

  return start === 1 ? {} : { start }
}

function spans(input: Json, path: string, type: string): Record<string, number> {
  const attrs = attributes(input, `${path}.attrs`)
  const out: Record<string, number> = {}
  for (const name of ['colspan', 'rowspan']) {
    const value = attrs[name] ?? 1
    if (typeof value !== 'number' || !Number.isInteger(value) || value < 1 || value > MAX_SPAN) {
      throw refuse(`${path}.attrs.${name}`, `must be a whole number from 1 to ${String(MAX_SPAN)}`)
    }
    if (value !== 1) out[name] = value
  }
  droppedDefaults(attrs, type, `${path}.attrs`, ['colspan', 'rowspan'])

  return out
}

/** The children of a node, validated against what its parent type may hold. */
function children(input: Json, path: string, parent: string, depth: number): ContentNode[] {
  return list(input, 'content', path).map((child, i) => {
    const at = `${path}.content[${String(i)}]`
    if (!isObject(child)) throw refuse(at, 'must be an object')
    return node(child, at, parent, depth)
  })
}

/** Whether a node of `type` may sit directly inside `parent`: the whole of the profile's structure. */
function place(type: string, parent: string, path: string): void {
  let allowed: string[]
  switch (parent) {
    case 'doc':
    case 'blockquote':
    case 'listItem':
      allowed = BLOCKS
      break
    case 'tableHeader':
    case 'tableCell':
      allowed = BLOCKS.filter((block) => block !== 'table')
      break
    case 'bulletList':
    case 'orderedList':
      allowed = ['listItem']
      break
    case 'table':
      allowed = ['tableRow']
      break
    case 'tableRow':
      allowed = ['tableHeader', 'tableCell']
      break
    case 'paragraph':
    case 'heading':
      allowed = ['text', 'hardBreak']
      break
    case 'codeBlock':
      allowed = ['text']
      break
    default:
      allowed = []
  }
  if (!allowed.includes(type)) throw refuse(path, 'is not allowed here')
}

function attributes(input: Json, path: string): Json {
  const attrs = input.attrs ?? {}
  if (!isObject(attrs)) throw refuse(path, 'must be an object')

  return attrs
}

function noAttributes(input: Json, path: string, type: string): void {
  droppedDefaults(attributes(input, `${path}.attrs`), type, `${path}.attrs`, [])
}

/**
 * Refuses every attribute that is neither one the caller handled nor one of the type's
 * accepted-and-dropped defaults, and a default attribute holding anything but its default.
 */
function droppedDefaults(attrs: Json, type: string, path: string, handled: string[]): void {
  const defaults = DROPPED_DEFAULTS[type] ?? {}
  for (const [name, value] of Object.entries(attrs)) {
    if (handled.includes(name)) continue
    const accepted = defaults[name]
    if (accepted === undefined) throw refuse(path, 'has an attribute the profile does not allow')
    if (!accepted.includes(value)) throw refuse(`${path}.${name}`, "is not the editor's default")
  }
}

function onlyKeys(input: Json, keys: string[], path: string): void {
  for (const key of Object.keys(input)) {
    if (!keys.includes(key)) throw refuse(path, 'has a key the profile does not allow')
  }
}

function list(input: Json, key: string, path: string): unknown[] {
  if (!(key in input)) return []
  const value = input[key]
  if (!Array.isArray(value)) throw refuse(`${path}.${key}`, 'must be a list')

  return value as unknown[]
}

function isObject(value: unknown): value is Json {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function refuse(path: string, why: string): ContentRefusal {
  return new ContentRefusal(path, why)
}
