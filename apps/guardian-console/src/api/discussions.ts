// The Guardian Discussions endpoints the Console uses (openapi/openapi.yaml is the contract, ADR 0035). A Person arrives as an id
// and a display name that is null only when Identity no longer holds them, and nothing else: no Account is ever on the wire.
// The Console never sends who wrote, edited or resolved anything: the server takes that from the signed-in session.
//
// `state` is read back as plain `string`, not the input union: a state the server holds that this Console does not know must be
// shown, not crash the page. The Console only ever treats `open` as "replies are accepted", so an unknown state fails closed.
//
// A message is one of two shapes, told apart by `removed`: a live message has its text, and a tombstone has no text at all
// (the server removed it from the database, so there is nothing here that could be shown).

import { requestJson, type Result } from './http.ts'

export interface DiscussionPerson {
  id: string
  /** Null only if Identity no longer holds the Person: the words stay, the author is shown as unknown. */
  displayName: string | null
}

export interface Discussion {
  id: string
  title: string
  state: string
  /** The author of the opening message. */
  creator: DiscussionPerson | null
  /** The last allocated sequence: removed messages are still counted, since each keeps its place. */
  messageCount: number
  /** When a message was last POSTED. An edit, a removal, a new title, resolving and reopening are not activity. */
  lastActivityAt: string
  createdAt: string
  resolvedAt: string | null
  resolvedBy: DiscussionPerson | null
}

interface MessageBase {
  id: string
  /** The order within the discussion: 1 is the opening message. Time is not the order. */
  sequence: number
  author: DiscussionPerson
  createdAt: string
}

export interface LiveMessage extends MessageBase {
  removed: false
  body: string
  /** Null until its author changes the text. There is no history behind it. */
  editedAt: string | null
  editedBy: DiscussionPerson | null
}

export interface RemovedMessage extends MessageBase {
  removed: true
  removedAt: string
}

export type Message = LiveMessage | RemovedMessage

export interface PageMeta {
  page: number
  perPage: number
  total: number
  lastPage: number
}

export interface DiscussionsPage extends PageMeta {
  discussions: Discussion[]
}

export interface MessagesPage extends PageMeta {
  messages: Message[]
}

interface WirePerson {
  id: string
  display_name: string | null
}

interface WireDiscussion {
  id: string
  title: string
  state: string
  creator: WirePerson | null
  message_count: number
  last_activity_at: string
  created_at: string
  resolved_at: string | null
  resolved_by: WirePerson | null
}

interface WireBase {
  id: string
  sequence: number
  author: WirePerson
  created_at: string
}

type WireMessage =
  | (WireBase & {
      removed: false
      body: string
      edited_at: string | null
      edited_by: WirePerson | null
    })
  | (WireBase & { removed: true; removed_at: string })

interface WireMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isNumber = (value: unknown): value is number => typeof value === 'number'
const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null
const isNullableString = (value: unknown): value is string | null =>
  value === null || isString(value)

const isWirePerson = (value: unknown): value is WirePerson =>
  isRecord(value) && isString(value.id) && isNullableString(value.display_name)
const isNullableWirePerson = (value: unknown): value is WirePerson | null =>
  value === null || isWirePerson(value)

function isWireDiscussion(value: unknown): value is WireDiscussion {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.title) &&
    isString(value.state) &&
    isNullableWirePerson(value.creator) &&
    isNumber(value.message_count) &&
    isString(value.last_activity_at) &&
    isString(value.created_at) &&
    isNullableString(value.resolved_at) &&
    isNullableWirePerson(value.resolved_by)
  )
}

function isWireMessage(value: unknown): value is WireMessage {
  if (
    !isRecord(value) ||
    !isString(value.id) ||
    !isNumber(value.sequence) ||
    !isWirePerson(value.author) ||
    !isString(value.created_at)
  ) {
    return false
  }
  if (value.removed === true) return isString(value.removed_at)
  return (
    value.removed === false &&
    isString(value.body) &&
    isNullableString(value.edited_at) &&
    isNullableWirePerson(value.edited_by)
  )
}

function isWireMeta(value: unknown): value is WireMeta {
  return (
    isRecord(value) &&
    isNumber(value.page) &&
    isNumber(value.per_page) &&
    isNumber(value.total) &&
    isNumber(value.last_page)
  )
}

const personFrom = (wire: WirePerson | null): DiscussionPerson | null =>
  wire === null ? null : { id: wire.id, displayName: wire.display_name }

function discussionFrom(wire: WireDiscussion): Discussion {
  return {
    id: wire.id,
    title: wire.title,
    state: wire.state,
    creator: personFrom(wire.creator),
    messageCount: wire.message_count,
    lastActivityAt: wire.last_activity_at,
    createdAt: wire.created_at,
    resolvedAt: wire.resolved_at,
    resolvedBy: personFrom(wire.resolved_by),
  }
}

function messageFrom(wire: WireMessage): Message {
  const base = {
    id: wire.id,
    sequence: wire.sequence,
    author: { id: wire.author.id, displayName: wire.author.display_name },
    createdAt: wire.created_at,
  }
  if (wire.removed) return { ...base, removed: true, removedAt: wire.removed_at }
  return {
    ...base,
    removed: false,
    body: wire.body,
    editedAt: wire.edited_at,
    editedBy: personFrom(wire.edited_by),
  }
}

const metaFrom = (meta: WireMeta): PageMeta => ({
  page: meta.page,
  perPage: meta.per_page,
  total: meta.total,
  lastPage: meta.last_page,
})

const idPath = (id: string) => encodeURIComponent(id)
const base = '/api/v1/admin/discussions' as const
const discussionPath = (id: string) => `${base}/${idPath(id)}` as const
const messagesPath = (id: string) => `${discussionPath(id)}/messages` as const

async function oneDiscussion(
  request: Promise<Result<WireDiscussion>>,
): Promise<Result<Discussion>> {
  const result = await request
  return result.ok ? { ok: true, value: discussionFrom(result.value) } : result
}

async function oneMessage(request: Promise<Result<WireMessage>>): Promise<Result<Message>> {
  const result = await request
  return result.ok ? { ok: true, value: messageFrom(result.value) } : result
}

/**
 * GET /admin/discussions: most recently active first (last activity, then id). The order is the server's and is never
 * re-sorted here. `state` narrows to one state; `query` matches TITLES only, never message text.
 */
export async function listDiscussions(input: {
  page: number
  perPage?: number
  state?: 'open' | 'resolved'
  query?: string
  signal?: AbortSignal
}): Promise<Result<DiscussionsPage>> {
  const params = new URLSearchParams({ page: String(input.page) })
  if (input.perPage !== undefined) params.set('per_page', String(input.perPage))
  if (input.state !== undefined) params.set('state', input.state)
  if (input.query !== undefined && input.query !== '') params.set('q', input.query)

  const result = await requestJson(
    {
      method: 'GET',
      path: `${base}?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (body): body is { data: WireDiscussion[]; meta: WireMeta } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(isWireDiscussion) &&
      isWireMeta(body.meta),
  )
  if (!result.ok) return result
  return {
    ok: true,
    value: { discussions: result.value.data.map(discussionFrom), ...metaFrom(result.value.meta) },
  }
}

/** GET /admin/discussions/{id}: the header, open or resolved. */
export function getDiscussion(id: string, signal?: AbortSignal): Promise<Result<Discussion>> {
  return oneDiscussion(
    requestJson(
      { method: 'GET', path: discussionPath(id), authenticated: true, ...(signal && { signal }) },
      isWireDiscussion,
    ),
  )
}

/** POST /admin/discussions: the header and its opening message together. The author is the signed-in caller, taken by the server. */
export function startDiscussion(input: {
  title: string
  body: string
}): Promise<Result<Discussion>> {
  return oneDiscussion(
    requestJson(
      {
        method: 'POST',
        path: base,
        authenticated: true,
        body: { title: input.title, body: input.body },
      },
      isWireDiscussion,
    ),
  )
}

/** PATCH /admin/discussions/{id}: the title only, and only by the discussion's creator (`not_author` otherwise). */
export function retitleDiscussion(id: string, title: string): Promise<Result<Discussion>> {
  return oneDiscussion(
    requestJson(
      { method: 'PATCH', path: discussionPath(id), authenticated: true, body: { title } },
      isWireDiscussion,
    ),
  )
}

/** POST /admin/discussions/{id}/resolve: idempotent. A resolved discussion stays readable and takes no new replies. */
export function resolveDiscussion(id: string): Promise<Result<Discussion>> {
  return oneDiscussion(
    requestJson(
      { method: 'POST', path: `${discussionPath(id)}/resolve`, authenticated: true },
      isWireDiscussion,
    ),
  )
}

/** POST /admin/discussions/{id}/reopen: idempotent. */
export function reopenDiscussion(id: string): Promise<Result<Discussion>> {
  return oneDiscussion(
    requestJson(
      { method: 'POST', path: `${discussionPath(id)}/reopen`, authenticated: true },
      isWireDiscussion,
    ),
  )
}

/** GET /admin/discussions/{id}/messages: oldest first by sequence, tombstones in their place. The order is the server's. */
export async function listMessages(input: {
  discussionId: string
  page: number
  perPage?: number
  signal?: AbortSignal
}): Promise<Result<MessagesPage>> {
  const params = new URLSearchParams({ page: String(input.page) })
  if (input.perPage !== undefined) params.set('per_page', String(input.perPage))

  const result = await requestJson(
    {
      method: 'GET',
      path: `${messagesPath(input.discussionId)}?${params.toString()}`,
      authenticated: true,
      ...(input.signal && { signal: input.signal }),
    },
    (body): body is { data: WireMessage[]; meta: WireMeta } =>
      isRecord(body) &&
      Array.isArray(body.data) &&
      body.data.every(isWireMessage) &&
      isWireMeta(body.meta),
  )
  if (!result.ok) return result
  return {
    ok: true,
    value: { messages: result.value.data.map(messageFrom), ...metaFrom(result.value.meta) },
  }
}

/** POST /admin/discussions/{id}/messages: refused with `discussion_resolved` if the discussion is no longer open. */
export function replyToDiscussion(discussionId: string, body: string): Promise<Result<Message>> {
  return oneMessage(
    requestJson(
      { method: 'POST', path: messagesPath(discussionId), authenticated: true, body: { body } },
      isWireMessage,
    ),
  )
}

/** PATCH .../messages/{id}: the text only, and only by its author. The author, sequence and time can never be sent. */
export function editMessage(
  discussionId: string,
  messageId: string,
  body: string,
): Promise<Result<Message>> {
  return oneMessage(
    requestJson(
      {
        method: 'PATCH',
        path: `${messagesPath(discussionId)}/${idPath(messageId)}`,
        authenticated: true,
        body: { body },
      },
      isWireMessage,
    ),
  )
}

/** DELETE .../messages/{id}: leaves a tombstone and returns it. Irreversible, and only by the message's author. */
export function removeMessage(discussionId: string, messageId: string): Promise<Result<Message>> {
  return oneMessage(
    requestJson(
      {
        method: 'DELETE',
        path: `${messagesPath(discussionId)}/${idPath(messageId)}`,
        authenticated: true,
      },
      isWireMessage,
    ),
  )
}
