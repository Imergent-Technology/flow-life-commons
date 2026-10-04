import { describe, expect, it } from 'vitest'

import { invalidFixtures, validFixtures } from '../test/resourceFixtures'
import {
  canonicalDocument,
  ContentRefusal,
  EMPTY_DOCUMENT,
  MAX_BYTES,
  normaliseLinkHref,
  parseContentDocument,
} from './contract'

// The Console's copy of the profile, held to the server's by the one shared corpus.

describe('the shared corpus', () => {
  it('is a real corpus, so the loops below cannot pass by finding nothing', () => {
    expect(validFixtures().length).toBeGreaterThanOrEqual(14)
    expect(invalidFixtures().length).toBeGreaterThanOrEqual(40)
  })

  it.each(validFixtures().map((f) => [f.name, f] as const))(
    'valid/%s comes out as the form the server stores',
    (_name, fixture) => {
      const stored = canonicalDocument(fixture.document)

      expect(stored).toEqual(fixture.canonical ?? fixture.document)
      // The canonical form is a fixed point, as it is on the server.
      expect(canonicalDocument(stored)).toEqual(stored)
    },
  )

  it.each(invalidFixtures().map((f) => [f.name, f] as const))(
    'invalid/%s is refused at the path the server names, never stripped',
    (_name, fixture) => {
      let refusal: unknown
      try {
        canonicalDocument(fixture.document)
      } catch (error) {
        refusal = error
      }

      expect(refusal).toBeInstanceOf(ContentRefusal)
      expect((refusal as ContentRefusal).path).toBe(fixture.path)
    },
  )

  it('never echoes what it refused: the message names a path and a reason, not the value', () => {
    for (const fixture of invalidFixtures()) {
      const result = parseContentDocument(fixture.document)
      expect(result.ok).toBe(false)
      if (result.ok) continue
      for (const secret of ['alert(1)', 'javascript', 'onclick', '<script', 'user:pass']) {
        expect(result.refusal.message, fixture.name).not.toContain(secret)
      }
    }
  })
})

describe('canonicalDocument', () => {
  it('accepts the empty document', () => {
    expect(canonicalDocument(EMPTY_DOCUMENT)).toEqual({ type: 'doc', content: [] })
  })

  it('refuses a document that is not an object, or not a document', () => {
    for (const bad of [null, 'text', 3, [], { type: 'paragraph' }]) {
      expect(() => canonicalDocument(bad)).toThrow(ContentRefusal)
    }
  })

  it('refuses a document over the size limit', () => {
    const big = {
      type: 'doc',
      content: [{ type: 'codeBlock', content: [{ type: 'text', text: 'x'.repeat(MAX_BYTES) }] }],
    }

    expect(() => canonicalDocument(big)).toThrow(/too long/)
  })

  it('refuses nesting beyond the depth limit', () => {
    let deep: unknown = { type: 'paragraph' }
    for (let i = 0; i < 25; i++) {
      deep = {
        type: 'blockquote',
        content: [deep],
      }
    }

    expect(() => canonicalDocument({ type: 'doc', content: [deep] })).toThrow(/nested too deeply/)
  })

  it('refuses a lone surrogate, which no editor can type and no database can store', () => {
    const lone = {
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'a\ud800b' }] }],
    }

    expect(() => canonicalDocument(lone)).toThrow(ContentRefusal)
  })

  it('rethrows what is not a refusal, rather than reporting a defect as bad content', () => {
    const hostile = {
      type: 'doc',
      get content(): never {
        throw new RangeError('boom')
      },
    }

    expect(() => parseContentDocument(hostile)).toThrow(RangeError)
  })
})

describe('normaliseLinkHref', () => {
  it.each([
    ['https://example.org/a?b=c#d', 'https://example.org/a?b=c#d'],
    ['HTTP://Example.org', 'http://Example.org'],
    ['  https://example.org  ', 'https://example.org'],
    ['https://example.org:8443/x', 'https://example.org:8443/x'],
    ['https://[::1]:80/', 'https://[::1]:80/'],
    ['mailto:team@example.org', 'mailto:team@example.org'],
    ['MAILTO:team@example.org', 'mailto:team@example.org'],
  ])('accepts %s', (raw, expected) => {
    expect(normaliseLinkHref(raw)).toBe(expected)
  })

  it.each([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html;base64,AAAA',
    'vbscript:x',
    'file:///etc/passwd',
    'ftp://example.org',
    '//example.org',
    '/relative',
    'example.org',
    'https://user:pass@example.org',
    'https://exa mple.org',
    'https://example.org/<x>',
    'https://example.org/"x',
    'https://example.org\\x',
    'https://example.org:99999',
    'https://',
    'mailto:a@b.org?subject=x',
    'mailto:nobody',
    'mailto:a@localhost',
    `https://example.org/${'a'.repeat(2048)}`,
  ])('refuses %s', (raw) => {
    expect(normaliseLinkHref(raw)).toBeNull()
  })
})

describe('a table is never anywhere below a table cell', () => {
  const para = (text: string) => ({ type: 'paragraph', content: [{ type: 'text', text }] })
  const table = (blocks: unknown[], cell = 'tableCell') => ({
    type: 'table',
    content: [{ type: 'tableRow', content: [{ type: cell, content: blocks }] }],
  })
  const doc = (...content: unknown[]) => ({ type: 'doc', content })
  const inner = table([para('inner')])
  const list = (...items: unknown[]) => ({
    type: 'bulletList',
    content: items.map((content) => ({ type: 'listItem', content })),
  })
  const quote = (...content: unknown[]) => ({ type: 'blockquote', content })

  it.each([
    [
      'directly in a cell',
      table([para('x'), inner]),
      'content.content[0].content[0].content[0].content[1]',
    ],
    [
      'behind a blockquote',
      table([para('x'), quote(inner)]),
      'content.content[0].content[0].content[0].content[1].content[0]',
    ],
    [
      'behind a list',
      table([list([para('x'), inner])]),
      'content.content[0].content[0].content[0].content[0].content[0].content[1]',
    ],
    [
      'deep below a header',
      table([quote(list([para('x'), quote(inner)]))], 'tableHeader'),
      'content.content[0].content[0].content[0].content[0].content[0].content[0].content[1].content[0]',
    ],
  ])('refuses a table %s, at the path of the inner table', (_where, outer, path) => {
    const refusal = (() => {
      try {
        canonicalDocument(doc(outer))
      } catch (error) {
        return error
      }
      return null
    })()

    expect(refusal).toBeInstanceOf(ContentRefusal)
    expect((refusal as ContentRefusal).path).toBe(path)
  })

  it('keeps ordinary tables and everything a cell may hold but a table', () => {
    const richCell = table([
      para('x'),
      quote(para('quoted')),
      list([para('item'), list([para('nested')])]),
      { type: 'codeBlock', content: [{ type: 'text', text: 'code' }] },
    ])

    for (const blocks of [
      [inner],
      [quote(inner)],
      [list([para('x'), inner])],
      [inner, inner],
      [richCell],
    ]) {
      expect(() => canonicalDocument(doc(...blocks))).not.toThrow()
    }
  })
})
