/// <reference types="node" />
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

// The library is the CONSUMING surface (ADR 0037): it shows a Guardian what the platform's delivery projection answers, and it is
// forbidden from building that picture any other way. A screen that asked the management API, or fetched a Pack's every Card and
// chose which to show, would have to know what is hidden in order to hide it, and would be one filter away from showing it. So the
// library's sources may not reach the management client, the management endpoints, or the editor. (The browser tests prove the
// requests; this stops the next edit from adding one.)

const here = import.meta.dirname
const sources = (dir: string): string[] =>
  readdirSync(dir)
    .filter((file) => /\.(ts|tsx)$/.test(file) && !/\.test\.tsx?$/.test(file))
    .map((file) => join(dir, file))

const FILES = [...sources(here), join(here, '../../api/resourceLibrary.ts')]

const FORBIDDEN: { name: string; pattern: RegExp; offends: string; fine: string }[] = [
  {
    name: 'the management client',
    // A type-only import is erased and asks nothing; a real one brings the management calls in.
    pattern: /^\s*import\s+(?!type\b)[^'"]*\bfrom\s+['"][^'"]*\/api\/resources(?:\.ts)?['"]/m,
    offends: "import { getPack } from '../../api/resources.ts'",
    fine: "import type { CategoryRef } from '../../api/resources.ts'",
  },
  {
    name: 'a management endpoint',
    pattern: /\/admin\/resources(?![-\w])/,
    offends: "const path = '/api/v1/admin/resources/packs'",
    fine: "const path = '/api/v1/admin/resource-library/packs'",
  },
  {
    name: 'the rich-text editor',
    pattern: /RichTextEditor|@tiptap\/(?!static)/,
    offends: "import { RichTextEditor } from '../../richtext/RichTextEditor.tsx'",
    fine: "import { RichContentRenderer } from '../../richtext/RichContentRenderer.tsx'",
  },
]

describe('the library stays a reader', () => {
  it.each(FORBIDDEN)('proves its scan for $name can fail', ({ pattern, offends, fine }) => {
    expect(pattern.test(offends)).toBe(true)
    expect(pattern.test(fine)).toBe(false)
  })

  it('has sources to scan', () => {
    expect(FILES.length).toBeGreaterThan(4)
  })

  it.each(FORBIDDEN)('does not use $name', ({ pattern }) => {
    const offenders = FILES.filter((file) => pattern.test(readFileSync(file, 'utf8')))
    expect(offenders).toEqual([])
  })
})
