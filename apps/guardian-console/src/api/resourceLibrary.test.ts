import { afterEach, describe, expect, it, vi } from 'vitest'

import { FakeApi, json } from '../test/fakeApi.ts'
import {
  filePath,
  libraryOf,
  MY_RESOURCES,
  MY_RESOURCES_PACK,
  numbered,
  TWO_GROUPS,
  wireDeliveredCard,
  wireDeliveredFile,
  wireFileCard,
  wireGroup,
  wireLibraryPack,
  wireListedEntry,
} from '../test/library.ts'
import { CARD2_ID, CARD_ID, PACK2_ID, PACK_ID } from '../test/resources.ts'
import {
  RESOURCE_LIBRARY,
  RESOURCE_LIBRARY_PACK,
  THREE_VIEWER_CARDS,
  VIEWER_GROUPS,
  viewerFilePath,
  viewerLibraryOf,
  wireViewerCard,
  wireViewerFileCard,
  wireViewerGroup,
  wireViewerListed,
  wireViewerPack,
} from '../test/viewerLibrary.ts'
import {
  browseAudienceLibrary,
  browseViewerLibrary,
  getAudiencePack,
  getViewerPack,
} from './resourceLibrary.ts'

afterEach(() => {
  vi.unstubAllGlobals()
})

// Two readers, two contracts (ADR 0038, C7). These tests serve each client the OTHER reader's answer on purpose: a client that could take
// either would pass every test that serves it only its own.

const serve = () => new FakeApi()
const pathsOf = (api: FakeApi) => api.calls.map((call) => call.path)

describe('the audience reader (policy C)', () => {
  it('asks /admin/my-resources only, with the Category and the words and nothing else', async () => {
    const api = serve().on(`GET ${MY_RESOURCES}`, libraryOf(...TWO_GROUPS))
    api.install()

    await browseAudienceLibrary({ category: 'C1', query: 'soup' })
    await browseAudienceLibrary()

    expect(pathsOf(api)).toEqual([`${MY_RESOURCES}?category=C1&q=soup`, MY_RESOURCES])
  })

  it('has no way to send a viewer-only filter, whatever the caller puts in the query', async () => {
    const api = serve().on(`GET ${MY_RESOURCES}`, libraryOf(...TWO_GROUPS))
    api.install()

    // A caller that smuggles the viewer's filters in is a type error; at run time they are still never sent.
    await browseAudienceLibrary({
      query: 'x',
      publication: 'draft',
      audience: 'volunteer',
    } as never)

    const sent = new URL(pathsOf(api)[0] ?? '', 'http://x').searchParams
    expect([...sent.keys()]).toEqual(['q'])
  })

  it('reads the delivery shape: Categories with named Packs and the Cards this reader can see', async () => {
    serve()
      .on(`GET ${MY_RESOURCES}`, libraryOf(...TWO_GROUPS))
      .install()
    const result = await browseAudienceLibrary()
    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(result.value.map((group) => group.category.name)).toEqual(['Training guides', 'Recipes'])
    expect(result.value[0]?.packs[0]).toEqual({
      id: PACK_ID,
      title: 'Welcome pack',
      summary: 'Everything a new Guardian needs.',
      isSeries: false,
      cardCount: 1,
    })
  })

  it('refuses a group that has no Category: policy C never lists an uncategorized Pack', async () => {
    serve()
      .on(`GET ${MY_RESOURCES}`, libraryOf(wireViewerGroup([wireListedEntry()], null)))
      .install()
    const result = await browseAudienceLibrary()
    expect(result.ok).toBe(false)
  })

  it.each([
    ['state', { state: 'published' }],
    ['audiences', { audiences: ['guardian'] }],
    ['a draft count', { draft_card_count: 1 }],
    ['an editor', { updated_by: { display_name: 'Someone' } }],
    ['a revision', { revision: 4 }],
  ])('refuses a listed Pack that carries %s, which policy C never sends', async (_name, extra) => {
    serve()
      .on(`GET ${MY_RESOURCES}`, libraryOf(wireGroup([wireListedEntry(extra)])))
      .install()
    expect((await browseAudienceLibrary()).ok).toBe(false)
  })

  it('refuses the viewer’s whole answer, rather than reading the part it understands', async () => {
    serve()
      .on(`GET ${MY_RESOURCES}`, viewerLibraryOf(...VIEWER_GROUPS))
      .on(`GET ${MY_RESOURCES_PACK}`, json(wireViewerPack()))
      .install()
    expect((await browseAudienceLibrary()).ok).toBe(false)
    expect((await getAudiencePack(PACK_ID)).ok).toBe(false)
  })

  it.each([
    ['a publication state', { state: 'draft' }],
    ['the Pack’s audiences', { audiences: ['guardian'] }],
    ['a draft count', { draft_card_count: 2 }],
    ['an editor', { updated_by: { display_name: 'Hone' } }],
    ['a time of editing', { updated_at: '2026-09-22T08:00:00Z' }],
  ])(
    'refuses a Pack that carries %s even when every one of its Cards is clean',
    async (_name, extra) => {
      serve()
        .on(`GET ${MY_RESOURCES_PACK}`, json(wireLibraryPack(extra)))
        .install()
      expect((await getAudiencePack(PACK_ID)).ok).toBe(false)
    },
  )

  it('refuses a Card that carries a Draft marker, an audience list or an editor', async () => {
    for (const extra of [
      { state: 'draft' },
      { audiences: ['guardian'] },
      { audience_mode: 'narrowed' },
      { updated_by: { display_name: 'Hone' } },
    ]) {
      serve()
        .on(
          `GET ${MY_RESOURCES_PACK}`,
          json(wireLibraryPack({ cards: [wireDeliveredCard(extra)] })),
        )
        .install()
      expect((await getAudiencePack(PACK_ID)).ok, JSON.stringify(extra)).toBe(false)
    }
  })

  it('reads a Pack, and keeps no field the reader was not meant to have', async () => {
    serve().on(`GET ${MY_RESOURCES_PACK}`, json(wireLibraryPack())).install()
    const result = await getAudiencePack(PACK_ID)
    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(Object.keys(result.value).sort()).toEqual([
      'cards',
      'category',
      'id',
      'isSeries',
      'summary',
      'title',
    ])
    expect(Object.keys(result.value.cards[0] ?? {}).sort()).toEqual([
      'document',
      'file',
      'id',
      'index',
      'summary',
      'title',
      'type',
      'uri',
    ])
  })

  it('refuses a Pack with no Card: policy C answers 404 for a Pack with nothing to show, so an empty one is not its shape', async () => {
    serve()
      .on(`GET ${MY_RESOURCES_PACK}`, json(wireLibraryPack({ cards: [], card_count: 0 })))
      .install()
    expect((await getAudiencePack(PACK_ID)).ok).toBe(false)
  })

  describe('a file’s path', () => {
    const cardWith = (path: string) =>
      wireFileCard({ file: wireDeliveredFile({ download_path: path }) })
    const pathOfFirstCard = async (path: string) => {
      serve()
        .on(`GET ${MY_RESOURCES_PACK}`, json(wireLibraryPack({ cards: [cardWith(path)] })))
        .install()
      const result = await getAudiencePack(PACK_ID)
      if (!result.ok) throw new Error('The Pack did not parse.')
      return result.value.cards[0]?.file?.downloadPath
    }

    it('is offered when it is the audience route of this Pack and this Card', async () => {
      expect(await pathOfFirstCard(filePath(PACK_ID, CARD_ID))).toBe(filePath(PACK_ID, CARD_ID))
    })

    it.each([
      ['the viewer’s route for the same Card', viewerFilePath(PACK_ID, CARD_ID)],
      ['the management route', `/api/v1/admin/resources/packs/${PACK_ID}/cards/${CARD_ID}/file`],
      ['the route of another Card', filePath(PACK_ID, CARD2_ID)],
      ['the route of another Pack', filePath(PACK2_ID, CARD_ID)],
      ['an absolute URL', `https://evil.example${filePath()}`],
      ['a protocol-relative URL', `//evil.example${filePath()}`],
      ['a path with a query', `${filePath()}?disposition=inline`],
      ['a path with a fragment', `${filePath()}#x`],
      ['a relative path', `packs/${PACK_ID}/cards/${CARD_ID}/file`],
      ['a path that climbs', `${MY_RESOURCES}/packs/${PACK_ID}/cards/../../cards/${CARD_ID}/file`],
      ['a storage key', 'resources/ab/cd/abcdef'],
      ['nothing', ''],
    ])('is not offered when it is %s', async (_what, path) => {
      expect(await pathOfFirstCard(path)).toBeNull()
    })
  })
})

describe('the viewer (policy A)', () => {
  it('asks /admin/resource-library with the Category, the words, publication and audience', async () => {
    const api = serve().on(`GET ${RESOURCE_LIBRARY}`, viewerLibraryOf(...VIEWER_GROUPS))
    api.install()

    await browseViewerLibrary({
      category: 'C1',
      query: 'soup',
      publication: 'draft',
      audience: 'volunteer',
    })
    await browseViewerLibrary()

    expect(pathsOf(api)).toEqual([
      `${RESOURCE_LIBRARY}?category=C1&q=soup&publication=draft&audience=volunteer`,
      RESOURCE_LIBRARY,
    ])
  })

  it('reads every Pack with its state, audiences, counts and editor, and the uncategorized group last', async () => {
    serve()
      .on(`GET ${RESOURCE_LIBRARY}`, viewerLibraryOf(...VIEWER_GROUPS))
      .install()
    const result = await browseViewerLibrary()
    expect(result.ok).toBe(true)
    if (!result.ok) return

    expect(result.value.map((group) => group.category?.name ?? null)).toEqual([
      'Training guides',
      'Recipes',
      null,
    ])
    const draft = result.value[0]?.packs[1]
    expect(draft).toEqual({
      id: '01J00000000000000000PACK009',
      title: 'Secret draft plan',
      summary: null,
      isSeries: false,
      state: 'draft',
      audiences: ['volunteer'],
      cardCount: 2,
      draftCardCount: 2,
      updatedAt: '2026-09-22T08:00:00Z',
      editor: { displayName: 'Hone Guardian' },
    })
    // A Pack with no audience at all, and with no Category, is data too.
    expect(result.value[2]?.packs[0]?.audiences).toEqual([])
  })

  it('accepts a Pack with no editor name, and an empty Pack', async () => {
    serve()
      .on(
        `GET ${RESOURCE_LIBRARY}`,
        viewerLibraryOf(
          wireViewerGroup([
            wireViewerListed({ card_count: 0, updated_by: { display_name: null } }),
          ]),
        ),
      )
      .install()
    const result = await browseViewerLibrary()
    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(result.value[0]?.packs[0]).toMatchObject({ cardCount: 0, editor: { displayName: null } })
  })

  it('refuses the audience reader’s answer: no state, audiences or editor to read', async () => {
    serve()
      .on(`GET ${RESOURCE_LIBRARY}`, libraryOf(...TWO_GROUPS))
      .on(`GET ${RESOURCE_LIBRARY_PACK}`, json(wireLibraryPack()))
      .install()
    expect((await browseViewerLibrary()).ok).toBe(false)
    expect((await getViewerPack(PACK_ID)).ok).toBe(false)
  })

  it.each([
    ['an unknown publication state', { state: 'archived' }],
    ['an unknown audience', { audiences: ['partner'] }],
    ['a missing editor', { updated_by: undefined }],
    ['a count that is not a number', { draft_card_count: '1' }],
  ])('refuses a listed Pack with %s', async (_name, extra) => {
    serve()
      .on(`GET ${RESOURCE_LIBRARY}`, viewerLibraryOf(wireViewerGroup([wireViewerListed(extra)])))
      .install()
    expect((await browseViewerLibrary()).ok).toBe(false)
  })

  it('reads a Pack with every Card, Drafts included, and the effective audiences of each', async () => {
    serve()
      .on(
        `GET ${RESOURCE_LIBRARY_PACK}`,
        json(wireViewerPack({ cards: THREE_VIEWER_CARDS, audiences: ['guardian', 'volunteer'] })),
      )
      .install()
    const result = await getViewerPack(PACK_ID)
    expect(result.ok).toBe(true)
    if (!result.ok) return

    expect(result.value.cards.map((card) => [card.index, card.state, card.audienceMode])).toEqual([
      [1, 'published', 'inherit'],
      [2, 'draft', 'inherit'],
      [3, 'published', 'narrowed'],
    ])
    expect(result.value.cards[2]?.audiences).toEqual(['volunteer'])
    expect(result.value.draftCardCount).toBe(1)
    expect(result.value.cardCount).toBe(3)
  })

  it('reads a Pack that has no Category and a Pack that has no Cards', async () => {
    serve()
      .on(
        `GET ${RESOURCE_LIBRARY_PACK}`,
        json(wireViewerPack({ category: null, cards: [], card_count: 0 })),
      )
      .install()
    const result = await getViewerPack(PACK_ID)
    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(result.value.category).toBeNull()
    expect(result.value.cards).toEqual([])
  })

  it('carries nothing the viewer is not entitled to: no revision, position, creator or action', async () => {
    serve()
      .on(`GET ${RESOURCE_LIBRARY_PACK}`, json(wireViewerPack({ cards: THREE_VIEWER_CARDS })))
      .install()
    const result = await getViewerPack(PACK_ID)
    if (!result.ok) throw new Error('The Pack did not parse.')
    const keys = [
      ...Object.keys(result.value),
      ...result.value.cards.flatMap((card) => Object.keys(card)),
    ]
    for (const forbidden of ['revision', 'position', 'createdBy', 'creator', 'actions', 'links']) {
      expect(keys).not.toContain(forbidden)
    }
  })

  describe('a file’s path', () => {
    const pathOfFirstCard = async (path: string) => {
      serve()
        .on(
          `GET ${RESOURCE_LIBRARY_PACK}`,
          json(
            wireViewerPack({
              cards: [
                wireViewerFileCard({
                  file: {
                    name: 'a.pdf',
                    media_type: 'application/pdf',
                    byte_size: 10,
                    download_path: path,
                  },
                }),
              ],
            }),
          ),
        )
        .install()
      const result = await getViewerPack(PACK_ID)
      if (!result.ok) throw new Error('The Pack did not parse.')
      return result.value.cards[0]?.file?.downloadPath
    }

    it('is offered when it is the viewer route of this Pack and this Card', async () => {
      expect(await pathOfFirstCard(viewerFilePath(PACK_ID, CARD_ID))).toBe(
        viewerFilePath(PACK_ID, CARD_ID),
      )
    })

    it.each([
      ['the audience route for the same Card', filePath(PACK_ID, CARD_ID)],
      ['the management route', `/api/v1/admin/resources/packs/${PACK_ID}/cards/${CARD_ID}/file`],
      ['the route of another Card', viewerFilePath(PACK_ID, CARD2_ID)],
      ['the route of another Pack', viewerFilePath(PACK2_ID, CARD_ID)],
      ['an absolute URL', `https://evil.example${viewerFilePath()}`],
      ['a path with a query', `${viewerFilePath()}?disposition=inline`],
      ['nothing', ''],
    ])('is not offered when it is %s', async (_what, path) => {
      expect(await pathOfFirstCard(path)).toBeNull()
    })
  })

  it('treats a 403 as a refusal the screens follow, and a 404 as a missing Pack', async () => {
    serve()
      .on(`GET ${RESOURCE_LIBRARY}`, json({ message: 'x' }, 403))
      .on(`GET ${RESOURCE_LIBRARY_PACK}`, json({ code: 'resource_pack_not_found' }, 404))
      .install()
    const list = await browseViewerLibrary()
    expect(!list.ok && list.failure.kind).toBe('forbidden')
    const pack = await getViewerPack(PACK_ID)
    expect(!pack.ok && pack.failure.kind).toBe('not-found')
  })

  it('numbers Cards among all of them: the server’s index is shown as sent', async () => {
    const cards = numbered([wireViewerCard({ id: CARD_ID }), wireViewerCard({ id: CARD2_ID })])
    serve()
      .on(`GET ${RESOURCE_LIBRARY_PACK}`, json(wireViewerPack({ cards })))
      .install()
    const result = await getViewerPack(PACK_ID)
    if (!result.ok) throw new Error('The Pack did not parse.')
    expect(result.value.cards.map((card) => card.index)).toEqual([1, 2])
  })
})
