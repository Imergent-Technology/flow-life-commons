import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y'
import { invalidFixtures, validFixtures } from '../test/resourceFixtures'
import { RichContentRenderer } from './RichContentRenderer'

const text = (value: string, marks?: unknown[]) => ({
  type: 'text',
  text: value,
  ...(marks === undefined ? {} : { marks }),
})
const paragraph = (...content: unknown[]) => ({ type: 'paragraph', content })
const doc = (...content: unknown[]) => ({ type: 'doc', content })

const UNAVAILABLE = /can.t be displayed/

describe('every valid fixture', () => {
  it.each(validFixtures().map((fixture) => [fixture.name, fixture] as const))(
    'valid/%s renders, with all of its text and nothing else',
    (_name, fixture) => {
      const { container } = render(<RichContentRenderer document={fixture.document} />)

      expect(screen.queryByText(UNAVAILABLE)).toBeNull()
      // The server derives `text` (whitespace collapsed); what is drawn holds the same words in the same order.
      const squash = (value: string) => value.replace(/\s+/g, '')
      expect(squash(container.textContent)).toBe(squash(fixture.text))
    },
  )

  it('renders the canonical form the same as what the editor sends', () => {
    for (const fixture of validFixtures()) {
      const sent = render(<RichContentRenderer document={fixture.document} />)
      const stored = render(
        <RichContentRenderer document={fixture.canonical ?? fixture.document} />,
      )

      expect(sent.container.innerHTML, fixture.name).toBe(stored.container.innerHTML)
    }
  })
})

describe('every invalid fixture', () => {
  it.each(invalidFixtures().map((fixture) => [fixture.name, fixture] as const))(
    'invalid/%s is not drawn: a short message stands in, and the document is not shown',
    (_name, fixture) => {
      const { container } = render(<RichContentRenderer document={fixture.document} />)

      expect(screen.getByText(UNAVAILABLE)).toBeInTheDocument()
      expect(container.querySelector('.rich-content')).toBeNull()
      expect(container.querySelector('script, iframe, img, style, [onclick]')).toBeNull()
      // No raw document, and no value from it, reaches the page.
      expect(container.textContent).not.toMatch(/alert\(1\)|"type"|doc|javascript/)
    },
  )
})

describe('structure', () => {
  const everything = doc(
    { type: 'heading', attrs: { level: 2 }, content: [text('Two')] },
    { type: 'heading', attrs: { level: 3 }, content: [text('Three')] },
    { type: 'heading', attrs: { level: 4 }, content: [text('Four')] },
    paragraph(
      text('plain '),
      text('bold', [{ type: 'bold' }]),
      text(' '),
      text('italic', [{ type: 'italic' }]),
      text(' '),
      text('under', [{ type: 'underline' }]),
      text(' '),
      text('code', [{ type: 'code' }]),
      text(' '),
      text('all', [
        { type: 'bold' },
        { type: 'italic' },
        { type: 'underline' },
        { type: 'link', attrs: { href: 'https://example.org' } },
      ]),
      { type: 'hardBreak' },
      text('after'),
    ),
    {
      type: 'bulletList',
      content: [
        {
          type: 'listItem',
          content: [
            paragraph(text('Outer')),
            {
              type: 'orderedList',
              attrs: { start: 4 },
              content: [{ type: 'listItem', content: [paragraph(text('Inner'))] }],
            },
          ],
        },
      ],
    },
    { type: 'blockquote', content: [paragraph(text('Quoted')), { type: 'horizontalRule' }] },
    { type: 'codeBlock', content: [text('a < b && c > d\n  indented')] },
    {
      type: 'table',
      content: [
        {
          type: 'tableRow',
          content: [
            { type: 'tableHeader', content: [paragraph(text('H1'))] },
            { type: 'tableHeader', content: [paragraph(text('H2'))] },
          ],
        },
        {
          type: 'tableRow',
          content: [
            { type: 'tableCell', attrs: { colspan: 2 }, content: [paragraph(text('Wide'))] },
          ],
        },
        {
          type: 'tableRow',
          content: [
            { type: 'tableHeader', attrs: { rowspan: 1 }, content: [paragraph(text('Row'))] },
            { type: 'tableCell', content: [paragraph(text('Cell'))] },
          ],
        },
      ],
    },
  )

  it('draws every node and mark of the profile as its element', () => {
    const { container } = render(<RichContentRenderer document={everything} />)
    const q = (selector: string) => container.querySelector(selector)

    expect(q('h2')?.textContent).toBe('Two')
    expect(q('h3')?.textContent).toBe('Three')
    expect(q('h4')?.textContent).toBe('Four')
    expect(q('strong')?.textContent).toBe('bold')
    expect(q('em')?.textContent).toBe('italic')
    expect(q('u')?.textContent).toBe('under')
    expect(q('p code')?.textContent).toBe('code')
    expect(q('p br')).not.toBeNull()
    expect(q('ul > li > ol')?.getAttribute('start')).toBe('4')
    expect(q('ul > li > p')?.textContent).toBe('Outer')
    expect(q('blockquote > p')?.textContent).toBe('Quoted')
    expect(q('blockquote > hr')).not.toBeNull()
    expect(q('pre > code')?.textContent).toBe('a < b && c > d\n  indented')
    expect(q('thead th[scope="col"]')?.textContent).toBe('H1')
    expect(q('tbody td[colspan="2"]')?.textContent).toBe('Wide')
    expect(q('tbody th[scope="row"]')?.textContent).toBe('Row')
  })

  it('nests marks inside each other in the fixed order', () => {
    const { container } = render(<RichContentRenderer document={everything} />)
    const link = container.querySelector('a')

    expect(link?.textContent).toBe('all')
    expect(link?.querySelector('u em strong')?.textContent).toBe('all')
  })

  it('draws an empty document as nothing, and does not mistake it for a failure', () => {
    const { container } = render(<RichContentRenderer document={doc()} />)

    expect(screen.queryByText(UNAVAILABLE)).toBeNull()
    expect(container.querySelector('.rich-content')?.childElementCount).toBe(0)
  })

  it('does not change the document it is given', () => {
    const frozen = JSON.parse(JSON.stringify(everything)) as unknown
    const before = JSON.stringify(frozen)
    render(<RichContentRenderer document={frozen} />)

    expect(JSON.stringify(frozen)).toBe(before)
  })

  it('has no accessibility violations (structure, names, table headers)', async () => {
    const { container } = render(<RichContentRenderer document={everything} />)

    await expectNoAxeViolations(container)
  })
})

describe('links', () => {
  const link = (href: string) => doc(paragraph(text('go', [{ type: 'link', attrs: { href } }])))

  it('opens a web address in a new browsing context that cannot reach back', () => {
    for (const href of ['https://example.org/a?b=c#d', 'http://example.org']) {
      render(<RichContentRenderer document={link(href)} />)
      const a = screen.getByRole('link', { name: 'go' })

      expect(a).toHaveAttribute('href', href)
      expect(a).toHaveAttribute('target', '_blank')
      expect(a.getAttribute('rel')?.split(' ').sort()).toEqual(['noopener', 'noreferrer'])
      document.body.innerHTML = ''
    }
  })

  it('draws a mailto link as an ordinary link', () => {
    render(<RichContentRenderer document={link('mailto:team@example.org')} />)
    const a = screen.getByRole('link', { name: 'go' })

    expect(a).toHaveAttribute('href', 'mailto:team@example.org')
    expect(a).not.toHaveAttribute('target')
  })

  it('never draws a link whose address the profile refuses: the whole document is withheld', () => {
    for (const href of [
      'javascript:alert(1)',
      'JaVaScRiPt:alert(1)',
      'data:text/html,x',
      '/relative',
      'ftp://example.org',
      'https://user:pw@example.org',
    ]) {
      const { container } = render(<RichContentRenderer document={link(href)} />)

      expect(container.querySelector('a'), href).toBeNull()
      expect(screen.getByText(UNAVAILABLE)).toBeInTheDocument()
      document.body.innerHTML = ''
    }
  })
})

describe('what is not a document', () => {
  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a string', '<p>hello</p><script>alert(1)</script>'],
    ['a number', 3],
    ['an array', [doc()]],
    ['an unknown node', doc({ type: 'script', content: [text('alert(1)')] })],
    ['an unknown mark', doc(paragraph(text('x', [{ type: 'strike' }])))],
    [
      'an unknown attribute',
      doc({ type: 'paragraph', attrs: { onclick: 'alert(1)' }, content: [text('x')] }),
    ],
    ['an unknown key', doc({ type: 'paragraph', html: '<b>x</b>', content: [text('x')] })],
  ])('withholds %s without throwing, and shows no document', (_name, input) => {
    const { container } = render(<RichContentRenderer document={input} />)

    expect(screen.getByRole('status')).toHaveTextContent(/can.t be displayed/)
    expect(container.querySelector('script, [onclick], b')).toBeNull()
    expect(container.textContent).not.toMatch(/alert|<|hello/)
  })

  it('does not break what is around it', () => {
    render(
      <main>
        <h1>Page</h1>
        <RichContentRenderer document={{ not: 'a document' }} />
        <p>After</p>
      </main>,
    )

    expect(screen.getByRole('heading', { name: 'Page' })).toBeInTheDocument()
    expect(screen.getByText('After')).toBeInTheDocument()
  })

  it('withholds a document nested deeper than the profile allows, without exhausting the stack', () => {
    let deep: unknown = paragraph(text('x'))
    for (let i = 0; i < 5000; i++) deep = { type: 'blockquote', content: [deep] }

    render(<RichContentRenderer document={doc(deep)} />)

    expect(screen.getByText(UNAVAILABLE)).toBeInTheDocument()
  })
})

describe('no HTML is produced or interpreted', () => {
  it('draws markup in text as text', () => {
    const hostile = '<img src=x onerror=alert(1)><script>alert(2)</script><b>bold?</b> &amp; &lt;'
    const { container } = render(
      <RichContentRenderer
        document={doc(paragraph(text(hostile)), { type: 'codeBlock', content: [text(hostile)] })}
      />,
    )

    expect(container.querySelector('img, script, b')).toBeNull()
    expect(container.querySelectorAll('p')[0]?.textContent).toBe(hostile)
    expect(container.querySelector('pre')?.textContent).toBe(hostile)
  })

  it('keeps text exactly as written: not HTML-escaped, not converted', () => {
    const { container } = render(
      <RichContentRenderer document={doc(paragraph(text('Tom & Jerry <3 "quotes" — ünï')))} />,
    )

    expect(container.textContent).toBe('Tom & Jerry <3 "quotes" — ünï')
  })
})
