import { json } from './fakeApi.ts'

// Guardian Discussions as the server sends it (openapi/openapi.yaml, ADR 0035).

export const DISCUSSION_ID = '01J000000000000000DISCUS01'
export const DISCUSSION2_ID = '01J000000000000000DISCUS02'
export const MESSAGE1_ID = '01J00000000000000000MSG001'
export const MESSAGE2_ID = '01J00000000000000000MSG002'
export const MESSAGE3_ID = '01J00000000000000000MSG003'

/** The signed-in Person (`accountFor`'s default): "mine" means this id, whatever name is on a message. */
export const ME = { id: '01J0000000000000000000PRSN', display_name: 'Gwen Guardian' }
export const OTHER = { id: '01J000000000000000000OTHER1', display_name: 'Hone Guardian' }

export const wireDiscussion = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => ({
  id: DISCUSSION_ID,
  title: 'Where do we meet?',
  state: 'open',
  creator: OTHER,
  message_count: 3,
  last_activity_at: '2026-10-02T09:00:00Z',
  created_at: '2026-10-01T09:00:00Z',
  resolved_at: null,
  resolved_by: null,
  ...overrides,
})

export const resolvedDiscussion = (overrides: Record<string, unknown> = {}) =>
  wireDiscussion({
    state: 'resolved',
    resolved_at: '2026-10-03T10:00:00Z',
    resolved_by: OTHER,
    ...overrides,
  })

export function discussionsPage(rows: unknown[], meta: Record<string, number> = {}) {
  return {
    data: rows,
    meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, ...meta },
  }
}

/** A message that is still there. */
export const wireMessage = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
  id: MESSAGE1_ID,
  sequence: 1,
  author: OTHER,
  created_at: '2026-10-01T09:00:00Z',
  removed: false,
  body: 'Thoughts on the venue?',
  edited_at: null,
  edited_by: null,
  ...overrides,
})

/** A tombstone: its place and author, and no `body` key at all. */
export const wireTombstone = (
  overrides: Record<string, unknown> = {},
): Record<string, unknown> => ({
  id: MESSAGE2_ID,
  sequence: 2,
  author: OTHER,
  created_at: '2026-10-01T10:00:00Z',
  removed: true,
  removed_at: '2026-10-01T11:00:00Z',
  ...overrides,
})

export function messagesPage(rows: unknown[], meta: Record<string, number> = {}) {
  return {
    data: rows,
    meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, ...meta },
  }
}

export const discussionNotFound = () =>
  json({ message: 'There is no such discussion.', code: 'discussion_not_found' }, 404)

export const messageNotFound = () =>
  json({ message: 'There is no such message in that discussion.', code: 'message_not_found' }, 404)

export const notAuthor = () =>
  json({ message: 'Only the person who wrote that can change it.', code: 'not_author' }, 403)

export const discussionResolvedConflict = () =>
  json({ message: 'That discussion is resolved.', code: 'discussion_resolved' }, 409)

export const messageRemovedConflict = () =>
  json({ message: 'That message has been removed.', code: 'message_removed' }, 409)

export const invalidDiscussionInput = (field: string, message: string) =>
  json({ message, code: 'invalid_discussion_input', errors: { [field]: [message] } }, 422)
