import { describe, expect, it } from 'vitest'

// The Resource Library is two readers that must stay apart (ADR 0038, C7; ADR 0037): the VIEWER (policy A, `/admin/resource-library`,
// `resources.view`) and the AUDIENCE reader (policy C, `/admin/my-resources`, Console admission only). A screen that could reach
// the other reader's client could show a Draft to a reader who may not see one, or hide from a viewer what they may read; and a
// screen that reached the management client, or the editor, would no longer be a reader. The browser tests prove the requests; these
// stop the next edit from adding a way to make the wrong one.
//
// The rules read what a module IMPORTS, by name, not what a line looks like: an alias (`getAudiencePack as ok`), a grouped import over
// several lines, a namespace or default import, a re-export, a dynamic `import()` and a path that climbs and comes back are all the
// same dependency, and each is checked below against a source that does it. Comments are not code and are not scanned.

interface Source {
  path: string
  text: string
}

// -- A small reader of imports -----------------------------------------------------------------------------------------------------

interface Dependency {
  /** The module, as a path under `src/` without its extension (`api/resourceLibrary`), or the bare specifier if it is not ours. */
  module: string
  /** The names it takes. `*` stands for a namespace or a default: everything, under a name the rule cannot see. */
  names: string[]
  /** True when every name is a type, so nothing of the module exists at run time. */
  typeOnly: boolean
  how: 'import' | 're-export' | 'dynamic'
}

function stripComments(text: string): string {
  return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"`\\])\/\/[^\n]*/g, '$1')
}

function resolve(from: string, specifier: string): string {
  if (!specifier.startsWith('.')) return specifier
  const parts = from.split('/').slice(0, -1)
  for (const part of specifier.split('/')) {
    if (part === '..') parts.pop()
    else if (part !== '.') parts.push(part)
  }
  return parts.join('/').replace(/\.(?:ts|tsx)$/, '')
}

function specifiers(clause: string): { names: string[]; typeOnly: boolean } {
  let rest = clause.trim()
  let typeOnly = false
  if (/^type\s/.test(rest)) {
    typeOnly = true
    rest = rest.replace(/^type\s+/, '')
  }
  const names: string[] = []
  let allTypes = true
  const named = /\{([\s\S]*?)\}/.exec(rest)
  const before = named === null ? rest : rest.slice(0, named.index)
  if (
    /\*\s*as\s+\w+/.test(before) ||
    (before.replace(/,/g, '').trim() !== '' && !before.includes('{'))
  ) {
    // A namespace or a default import: the module's whole surface, under a name that is not its own.
    names.push('*')
    allTypes = false
  }
  if (named !== null) {
    for (const raw of (named[1] ?? '').split(',')) {
      const item = raw.trim()
      if (item === '') continue
      const isType = /^type\s/.test(item)
      const name =
        item
          .replace(/^type\s+/, '')
          .split(/\s+as\s+/)[0]
          ?.trim() ?? ''
      names.push(name) // the name the MODULE exports, not the alias it is hidden behind
      if (!isType) allTypes = false
    }
  }
  return { names, typeOnly: typeOnly || (names.length > 0 && allTypes) }
}

function dependencies(source: Source): Dependency[] {
  const code = stripComments(source.text)
  const found: Dependency[] = []
  for (const match of code.matchAll(/(?:^|\n)\s*import\s+([^;'"`]*?)\s+from\s+['"]([^'"]+)['"]/g)) {
    found.push({
      module: resolve(source.path, match[2] ?? ''),
      ...specifiers(match[1] ?? ''),
      how: 'import',
    })
  }
  for (const match of code.matchAll(/(?:^|\n)\s*import\s+['"]([^'"]+)['"]/g)) {
    found.push({
      module: resolve(source.path, match[1] ?? ''),
      names: [],
      typeOnly: false,
      how: 'import',
    })
  }
  for (const match of code.matchAll(
    /(?:^|\n)\s*export\s+(type\s+)?(\*(?:\s+as\s+\w+)?|\{[\s\S]*?\})\s+from\s+['"]([^'"]+)['"]/g,
  )) {
    const star = (match[2] ?? '').startsWith('*')
    const named = specifiers(match[2] ?? '')
    found.push({
      module: resolve(source.path, match[3] ?? ''),
      names: star ? ['*'] : named.names,
      typeOnly: match[1] !== undefined || (!star && named.typeOnly),
      how: 're-export',
    })
  }
  for (const match of code.matchAll(/\bimport\s*\(\s*['"]([^'"]+)['"]\s*\)/g)) {
    found.push({
      module: resolve(source.path, match[1] ?? ''),
      names: ['*'],
      typeOnly: false,
      how: 'dynamic',
    })
  }
  for (const match of code.matchAll(/\brequire\s*\(\s*['"]([^'"]+)['"]\s*\)/g)) {
    found.push({
      module: resolve(source.path, match[1] ?? ''),
      names: ['*'],
      typeOnly: false,
      how: 'dynamic',
    })
  }
  return found
}

// -- The rules ----------------------------------------------------------------------------------------------------------------------

const LIB = 'pages/library'
const CLIENT = 'api/resourceLibrary'
const MANAGEMENT = 'api/resources'

type Place = 'viewer' | 'audience' | 'reading' | 'route' | 'other'

function placeOf(path: string): Place {
  if (path.startsWith(`${LIB}/viewer/`)) return 'viewer'
  if (path.startsWith(`${LIB}/audience/`)) return 'audience'
  if (path.startsWith(`${LIB}/reading/`)) return 'reading'
  if (path.startsWith(`${LIB}/`)) return 'route'
  return 'other'
}

/** The names of the client each place may take: its own reader's, and the shared shape of a Card as it is read. */
const OWN_CLIENT_NAMES: Record<Place, RegExp | null> = {
  viewer: /^(?:(?:browse|get)?Viewer\w*|Reading(?:Card|File|CardType))$/,
  audience: /^(?:(?:browse|get)?Audience\w*|Reading(?:Card|File|CardType))$/,
  reading: /^Reading(?:Card|File|CardType)$/,
  route: null,
  other: null,
}

/** What each place may import from the library's own tree. Only `LibraryPages` names both readers. */
const MAY_IMPORT_FROM: Record<Place, readonly Place[]> = {
  viewer: ['viewer', 'reading', 'route'],
  audience: ['audience', 'reading', 'route'],
  reading: ['reading'],
  route: ['route', 'reading'],
  other: [],
}

const CAPABILITY_READERS = ['pages/library/libraryMode', 'pages/library/LibraryRoute']
const PAGE_CHOOSER = ['pages/library/LibraryPages']

/** Every rule at once: the names of the rules `source` breaks. */
function violations(source: Source): string[] {
  const place = placeOf(source.path)
  if (place === 'other') return []
  const code = stripComments(source.text)
  const found: string[] = []
  const deps = dependencies(source)

  for (const dep of deps) {
    if (dep.module === MANAGEMENT && !dep.typeOnly) found.push('the management client at run time')
    if (dep.module === CLIENT) {
      if (dep.typeOnly && place !== 'route') {
        // A type is erased; but the NAME of a type of the other reader still points at the other reader's contract.
        if (!dep.names.every((name) => OWN_CLIENT_NAMES[place]?.test(name) === true)) {
          found.push("a name of the other reader's contract")
        }
      } else if (place === 'route' || place === 'reading') {
        if (!dep.typeOnly)
          found.push('the library client at run time, from a place that reads nothing itself')
        else if (place === 'route') found.push('the library client from the route')
      } else if (dep.names.includes('*') || dep.how !== 'import') {
        found.push('the library client as a namespace, default, re-export or dynamic import')
      } else if (!dep.names.every((name) => OWN_CLIENT_NAMES[place]?.test(name) === true)) {
        found.push("the other reader's client")
      }
    }
    if (dep.module.startsWith(`${LIB}/`)) {
      const target = placeOf(dep.module)
      if (
        !MAY_IMPORT_FROM[place].includes(target) &&
        !PAGE_CHOOSER.includes(source.path.replace(/\.(?:ts|tsx)$/, ''))
      ) {
        found.push(`the ${target} side from the ${place} side`)
      }
    }
  }

  if (place === 'viewer') {
    // The shared reader and the shared navigation take a limited shape. A viewer Card is a superset of it, so handing one over whole
    // type-checks and shows nothing wrong today; it would put state, audiences and the editor in reach of code that is shared with the
    // audience reader. The viewer therefore projects first, and this keeps it doing so.
    for (const tag of code.matchAll(/<LibraryCardBody\b[^>]*>/g)) {
      if (!/\bcard=\{readingOf\(/.test(tag[0]))
        found.push('the whole viewer Card to the shared reader')
    }
    for (const tag of code.matchAll(/<(?:CardNavigation|SeriesControls)\b[^>]*>/g)) {
      if (!/\bcards=\{[^}]*\.map\(navigableOf\)\}/.test(tag[0])) {
        found.push('the whole viewer Cards to the shared navigation')
      }
    }
  }

  const unextended = source.path.replace(/\.(?:ts|tsx)$/, '')
  if (/\/api\/v1\/|\brequest(?:Json|Void)\b|\bfetch\s*\(/.test(code)) {
    found.push('its own request: every request goes through the client')
  }
  if (/\/admin\/resources(?![-\w])/.test(code)) found.push('a management endpoint')
  if (/RichTextEditor|@tiptap\/(?!static)/.test(code)) found.push('the rich-text editor')

  const readsCapabilities =
    /auth\/capabilities|\bhasCapability\b|\bRESOURCES_VIEW\b|\bRESOURCES_MANAGE\b|\bcapabilities\b/.test(
      code,
    )
  if (readsCapabilities && !CAPABILITY_READERS.includes(unextended)) {
    found.push('a capability: only the route chooses a reader')
  }
  if (CAPABILITY_READERS.includes(unextended) || PAGE_CHOOSER.includes(unextended)) {
    if (
      /\buseSearchParams\b|\buseParams\b|\blocalStorage\b|\bsessionStorage\b|\bURLSearchParams\b|\buseLocation\b/.test(
        code,
      )
    ) {
      found.push('a reader chosen from an address or a stored preference')
    }
    if (/\b(?:roles?|relationships?|guardian|volunteer|member)\b/i.test(code)) {
      found.push('a reader chosen by a role or a relationship')
    }
  }
  return found
}

// -- The client's own two sections --------------------------------------------------------------------------------------------------

function clientSections(text: string): { audience: string; viewer: string } {
  const code = stripComments(text)
  const audienceAt = code.indexOf('const AUDIENCE_FAMILY = ')
  const viewerAt = code.indexOf('const VIEWER_FAMILY = ')
  if (audienceAt < 0 || viewerAt < 0 || viewerAt < audienceAt)
    throw new Error('The client no longer has two sections.')
  return { audience: code.slice(audienceAt, viewerAt), viewer: code.slice(viewerAt) }
}

function clientViolations(text: string): string[] {
  const { audience, viewer } = clientSections(text)
  const found: string[] = []
  if (audience.includes('resource-library'))
    found.push("the audience client names the viewer's route")
  if (/VIEWER_FAMILY|viewer/i.test(audience.replace(/PRIVILEGED_FIELDS[\s\S]*?\]/, ''))) {
    found.push("the audience client uses the viewer's family or names")
  }
  if (viewer.includes('my-resources'))
    found.push("the viewer client names the audience reader's route")
  if (viewer.includes('AUDIENCE_FAMILY'))
    found.push("the viewer client uses the audience reader's family")
  if (!audience.includes('AUDIENCE_FAMILY'))
    found.push('the audience client has no family of its own')
  if (!viewer.includes('VIEWER_FAMILY')) found.push('the viewer client has no family of its own')
  if (/\/admin\/resources(?![-\w])/.test(`${audience}${viewer}`))
    found.push('a management endpoint')
  if (/\brequestVoid\b|method:\s*'(?:POST|PUT|PATCH|DELETE)'/.test(`${audience}${viewer}`)) {
    found.push('anything but a read')
  }
  return found
}

// -- The real source ----------------------------------------------------------------------------------------------------------------

const libraryFiles = import.meta.glob<string>('./**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})
const clientFile = import.meta.glob<string>('../../api/resourceLibrary.ts', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const isProduction = (path: string) => !/\.test\.tsx?$/.test(path)
const production: Source[] = Object.entries(libraryFiles)
  .filter(([path]) => isProduction(path))
  .map(([path, text]) => ({ path: `${LIB}/${path.replace(/^\.\//, '')}`, text }))
const clientText = Object.values(clientFile)[0] ?? ''

describe('the library’s two readers stay apart', () => {
  it('has the sources it means to scan', () => {
    const paths = production.map((source) => source.path)
    for (const expected of [
      'pages/library/LibraryRoute.tsx',
      'pages/library/LibraryPages.tsx',
      'pages/library/libraryMode.ts',
      'pages/library/viewer/ViewerLibraryPage.tsx',
      'pages/library/viewer/ViewerPackPage.tsx',
      'pages/library/audience/AudienceLibraryPage.tsx',
      'pages/library/audience/AudiencePackPage.tsx',
      'pages/library/reading/LibraryCardBody.tsx',
      'pages/library/reading/CardNavigation.tsx',
    ]) {
      expect(paths, `${expected} was not scanned`).toContain(expected)
    }
    expect(production.every((source) => !source.path.endsWith('.test.tsx'))).toBe(true)
    expect(clientText).toContain('AUDIENCE_FAMILY')
  })

  it('finds no violation in any library source', () => {
    expect(
      production.flatMap((source) => violations(source).map((rule) => `${source.path}: ${rule}`)),
    ).toEqual([])
  })

  it('finds no violation in the client’s two sections', () => {
    expect(clientViolations(clientText)).toEqual([])
  })

  it('has exactly two places that name a reader’s page: the chooser, and the reader’s own directory', () => {
    const naming = production
      .filter((source) =>
        /\b(?:Viewer|Audience)(?:Library|Pack)Page\b/.test(stripComments(source.text)),
      )
      .map((source) => source.path)
    expect(naming.sort()).toEqual([
      'pages/library/LibraryPages.tsx',
      'pages/library/audience/AudienceLibraryPage.tsx',
      'pages/library/audience/AudiencePackPage.tsx',
      'pages/library/viewer/ViewerLibraryPage.tsx',
      'pages/library/viewer/ViewerPackPage.tsx',
    ])
  })
})

// -- Every rule, shown to be able to fail -------------------------------------------------------------------------------------------

const at = (path: string, text: string): Source => ({ path, text })
const V = 'pages/library/viewer/Sample.tsx'
const A = 'pages/library/audience/Sample.tsx'
const R = 'pages/library/reading/Sample.tsx'
const ROUTE = 'pages/library/LibraryRoute.tsx'
const CLIENT_AT = "'../../../api/resourceLibrary.ts'"

describe('the rules can fail', () => {
  it.each([
    [
      'the viewer taking the audience reader’s client',
      V,
      `import { getAudiencePack } from ${CLIENT_AT}`,
    ],
    [
      'the audience reader taking the viewer’s client',
      A,
      `import { browseViewerLibrary } from ${CLIENT_AT}`,
    ],
    [
      'an alias that hides the other reader’s client',
      V,
      `import { getViewerPack as ok, getAudiencePack as sneaky } from ${CLIENT_AT}`,
    ],
    [
      'a grouped import over several lines',
      A,
      `import {\n  getAudiencePack,\n  browseViewerLibrary,\n} from ${CLIENT_AT}`,
    ],
    ['a namespace import of the client', V, `import * as library from ${CLIENT_AT}`],
    ['a default import of the client', A, `import library from ${CLIENT_AT}`],
    ['a re-export of the client', V, `export { getAudiencePack } from ${CLIENT_AT}`],
    ['a star re-export of the client', A, `export * from ${CLIENT_AT}`],
    ['a dynamic import of the client', V, `const lib = await import(${CLIENT_AT})`],
    ['a type of the other reader’s contract', V, `import type { AudiencePack } from ${CLIENT_AT}`],
    [
      'a path that climbs and comes back',
      V,
      "import { getAudiencePack } from '../../../api/../api/resourceLibrary'",
    ],
    [
      'the client without its extension',
      A,
      "import { getViewerPack } from '../../../api/resourceLibrary'",
    ],
    [
      'the shared reader taking a client at run time',
      R,
      `import { getViewerPack } from ${CLIENT_AT}`,
    ],
    [
      'the route taking the client',
      ROUTE,
      `import { getViewerPack } from '../../api/resourceLibrary.ts'`,
    ],
  ])('flags %s', (_name, path, text) => {
    expect(violations(at(path, text)).length).toBeGreaterThan(0)
  })

  it.each([
    [
      'the viewer taking its own client and the shared shape',
      V,
      `import { browseViewerLibrary, getViewerPack, type ViewerPack, type ReadingCard } from ${CLIENT_AT}`,
    ],
    [
      'the audience reader taking its own client',
      A,
      `import { browseAudienceLibrary, getAudiencePack, type AudiencePack } from ${CLIENT_AT}`,
    ],
    [
      'the shared reader taking the shared shape as a type',
      R,
      `import type { ReadingCard, ReadingFile } from ${CLIENT_AT}`,
    ],
    [
      'a reader taking the shared reading components',
      V,
      "import { LibraryCardBody } from '../reading/LibraryCardBody.tsx'",
    ],
    [
      'a reader taking a type from management',
      A,
      "import type { CategoryRef } from '../../../api/resources.ts'",
    ],
    [
      'a reader taking an inline type from management',
      A,
      "import { type CategoryRef } from '../../../api/resources.ts'",
    ],
    [
      'the audience catalog, which is not the management client',
      V,
      "import { AUDIENCES } from '../../../api/audiences.ts'",
    ],
    [
      'a comment that names the other reader',
      V,
      "// Not the audience reader's `/api/v1/admin/my-resources`, and no role.\nconst a = 1",
    ],
  ])('does not flag %s', (_name, path, text) => {
    expect(violations(at(path, text))).toEqual([])
  })

  it.each([
    ['the management client at run time', V, "import { getPack } from '../../../api/resources.ts'"],
    [
      'the management client without its extension',
      A,
      "import { getPack } from '../../../api/resources'",
    ],
    [
      'the management client behind an alias',
      V,
      "import { getPack as fine } from '../../../api/resources.ts'",
    ],
    [
      'the management client next to a type',
      A,
      "import { type CategoryRef, getPack } from '../../../api/resources.ts'",
    ],
    [
      'the management client by namespace',
      V,
      "import * as management from '../../../api/resources.ts'",
    ],
    ['the management client by default', V, "import management from '../../../api/resources.ts'"],
    ['the management client re-exported', R, "export { getPack } from '../../../api/resources.ts'"],
    [
      'the management client imported dynamically',
      A,
      "const m = await import('../../../api/resources.ts')",
    ],
    ['a management endpoint', V, "const path = '/admin/resources/packs'"],
    ['the editor', A, "import { RichTextEditor } from '../../../richtext/RichTextEditor.tsx'"],
    ['tiptap', R, "import { Editor } from '@tiptap/core'"],
    ['an API path of its own', V, "const path = '/api/v1/admin/resource-library'"],
    ['a request helper', A, "import { requestJson } from '../../../api/http.ts'"],
    ['a raw fetch', R, "const r = await fetch('/x')"],
  ])('flags %s', (_name, path, text) => {
    expect(violations(at(path, text)).length).toBeGreaterThan(0)
  })

  it.each([
    [
      'the viewer taking the audience side',
      V,
      "import { AudiencePackPage } from '../audience/AudiencePackPage.tsx'",
    ],
    [
      'the audience side taking the viewer',
      A,
      "import { ViewerPackPage } from '../viewer/ViewerPackPage.tsx'",
    ],
    [
      'the shared reader taking a reader',
      R,
      "import { ViewerPackPage } from '../viewer/ViewerPackPage.tsx'",
    ],
    ['the shared reader taking the route', R, "import { useLibraryMode } from '../libraryMode.ts'"],
  ])('flags %s', (_name, path, text) => {
    expect(violations(at(path, text)).length).toBeGreaterThan(0)
  })

  it.each([
    [
      'a viewer Card handed whole to the shared reader',
      '<LibraryCardBody key={current.id} card={current} />',
    ],
    ['a viewer Card handed over under another name', '<LibraryCardBody card={whole} />'],
    [
      'every viewer Card handed whole to the navigation',
      '<CardNavigation cards={cards} currentId={x} />',
    ],
    [
      'every viewer Card handed whole to the series controls',
      '<SeriesControls cards={pack.cards} currentId={x} />',
    ],
  ])('flags %s', (_name, text) => {
    expect(violations(at(V, text)).length).toBeGreaterThan(0)
  })

  it('accepts the viewer projecting before it hands anything over, and does not require it of the audience reader', () => {
    const projected =
      '<LibraryCardBody key={current.id} card={readingOf(current)} />\n<CardNavigation cards={cards.map(navigableOf)} currentId={x} />\n<SeriesControls cards={cards.map(navigableOf)} currentId={x} />'
    expect(violations(at(V, projected))).toEqual([])
    expect(violations(at(A, '<LibraryCardBody key={current.id} card={current} />'))).toEqual([])
  })

  it('lets only the chooser name both readers’ pages', () => {
    const both =
      "import { AudiencePackPage } from './audience/AudiencePackPage.tsx'\nimport { ViewerPackPage } from './viewer/ViewerPackPage.tsx'"
    expect(violations(at('pages/library/LibraryPages.tsx', both))).toEqual([])
    expect(violations(at('pages/library/Other.tsx', both))).not.toEqual([])
  })

  it.each([
    [
      'a capability read in the viewer',
      V,
      "import { hasCapability } from '../../../auth/capabilities.ts'",
    ],
    [
      'the viewing capability in the audience reader',
      A,
      "const may = current.capabilities.includes('resources.view')",
    ],
    ['the viewing capability named in the shared reader', R, 'const x = RESOURCES_VIEW'],
    [
      'a reader chosen from the address',
      ROUTE,
      "const [params] = useSearchParams()\nconst mode = params.get('mode')",
    ],
    [
      'a reader chosen from a stored preference',
      ROUTE,
      "const mode = localStorage.getItem('reader')",
    ],
    [
      'a reader chosen by a role',
      ROUTE,
      "const mode = account.roles.includes('x') ? 'viewer' : 'audience'",
    ],
    ['a reader chosen by a relationship', ROUTE, 'const mode = relationships.length > 0 ? 1 : 2'],
    ['a reader chosen by an audience', ROUTE, "const mode = audience === 'guardian'"],
  ])('flags %s', (_name, path, text) => {
    expect(violations(at(path, text)).length).toBeGreaterThan(0)
  })

  it('lets the route read the viewing capability, once, from /me', () => {
    const text =
      "import { hasCapability, RESOURCES_VIEW } from '../../auth/capabilities.ts'\nconst viewer = hasCapability(current, RESOURCES_VIEW)"
    expect(violations(at(ROUTE, text))).toEqual([])
    expect(violations(at('pages/library/LibraryPages.tsx', text)).length).toBeGreaterThan(0)
  })

  describe('the client’s sections', () => {
    const sections = (audience: string, viewer: string) =>
      `const AUDIENCE_FAMILY = 'my-resources'\n${audience}\nconst VIEWER_FAMILY = 'resource-library'\n${viewer}`

    it('accepts a client whose sections each keep to their own route', () => {
      expect(
        clientViolations(
          sections(
            "const a = '/api/v1/admin/my-resources'",
            "const v = '/api/v1/admin/resource-library'",
          ),
        ),
      ).toEqual([])
    })

    it.each([
      [
        'the audience client naming the viewer’s route',
        sections("const a = '/api/v1/admin/resource-library'", ''),
      ],
      [
        'the viewer client naming the audience route',
        sections('', "const v = '/api/v1/admin/my-resources'"),
      ],
      ['the viewer client using the audience family', sections('', 'const f = AUDIENCE_FAMILY')],
      ['the audience client using the viewer family', sections('const f = VIEWER_FAMILY', '')],
      [
        'a management endpoint in either',
        sections('', "const m = '/api/v1/admin/resources/packs'"),
      ],
      ['a write', sections("requestJson({ method: 'POST' })", '')],
    ])('flags %s', (_name, text) => {
      expect(clientViolations(text).length).toBeGreaterThan(0)
    })
  })

  it('does not take a comment for code, or a string for a comment', () => {
    expect(
      violations(
        at(V, "// import { getAudiencePack } from '../../../api/resourceLibrary.ts'\nconst a = 1"),
      ),
    ).toEqual([])
    expect(
      violations(
        at(V, "/* import * as lib from '../../../api/resourceLibrary.ts' */\nconst a = 1"),
      ),
    ).toEqual([])
    expect(
      violations(at(V, "const url = 'https://example.org/api/v1/x' // not a comment's end")).length,
    ).toBeGreaterThan(0)
  })
})
