import { describe, expect, it } from 'vitest'

// The Member surface must never become coupled to Guardian/admin implementation (ADR 0032, Work Package
// 4): it may import neutral shared code (ui/**, api/**, auth/**), but never Guardian's own page
// implementations, its shell, or its admin-only helpers. Scoped to `./**` relative to THIS file, which
// is `src/member/`, so it never has to name that prefix itself and automatically covers a file added
// here later.

const FORBIDDEN_ROOTS = ['pages/admin', 'shell', 'admin']

const modules = import.meta.glob<string>('./**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const isProduction = (path: string) => !/\.test\.tsx?$/.test(path)
const production = Object.fromEntries(
  Object.entries(modules).filter(([path]) => isProduction(path)),
)

/** Every string literal a static or a re-export `import`/`export ... from` names, in source order. */
function importSpecifiers(source: string): string[] {
  const pattern = /(?:import|export)(?:(?!from)[\s\S])*?\bfrom\s+['"]([^'"]+)['"]/g
  return Array.from(source.matchAll(pattern), (match) => match[1] ?? '')
}

/** Whether a relative specifier (as written FROM src/member/*) reaches one of the forbidden roots. */
function crossesBoundary(specifier: string): boolean {
  if (!specifier.startsWith('../')) return false // a sibling under src/member itself, or a package
  const withoutUp = specifier.replace(/^(\.\.\/)+/, '')
  return FORBIDDEN_ROOTS.some(
    (root) =>
      withoutUp === root || withoutUp.startsWith(`${root}/`) || withoutUp.startsWith(`${root}.`),
  )
}

interface Offender {
  file: string
  specifier: string
}

function scan(files: Record<string, string>): Offender[] {
  const found: Offender[] = []
  for (const [file, source] of Object.entries(files)) {
    for (const specifier of importSpecifiers(source)) {
      if (crossesBoundary(specifier)) found.push({ file, specifier })
    }
  }
  return found
}

describe('the Member surface never imports Guardian/admin implementation', () => {
  it('looks at real Member source, not an empty set', () => {
    const paths = Object.keys(production)
    expect(paths.length).toBeGreaterThan(3)
    expect(paths).toContain('./MemberShell.tsx')
    expect(paths.some((p) => p.endsWith('.test.ts') || p.endsWith('.test.tsx'))).toBe(false)
  })

  it('imports nothing from pages/admin, shell or admin', () => {
    expect(scan(production)).toEqual([])
  })

  it('flags a forbidden import (positive control)', () => {
    expect(
      scan({ './Offender.tsx': "import { AccountsPage } from '../pages/admin/AccountsPage.tsx'" }),
    ).toEqual([{ file: './Offender.tsx', specifier: '../pages/admin/AccountsPage.tsx' }])
    expect(
      scan({ './Offender.tsx': "import { ConsoleShell } from '../shell/ConsoleShell.tsx'" }),
    ).toEqual([{ file: './Offender.tsx', specifier: '../shell/ConsoleShell.tsx' }])
    expect(scan({ './Offender.tsx': "import { useLoad } from '../admin/useLoad.ts'" })).toEqual([
      { file: './Offender.tsx', specifier: '../admin/useLoad.ts' },
    ])
  })

  it('does not flag the neutral areas Member code legitimately uses (negative control)', () => {
    const fine = [
      "import { Page } from '../ui/Page.tsx'",
      "import { getCurrentMembership } from '../api/myMembership.ts'",
      "import { useCurrentAccount } from '../auth/auth-context.ts'",
      "import { MembershipStateBadge } from './MembershipStateBadge.tsx'",
      "import { MfaSection } from '../pages/MfaSection.tsx'", // a specific, neutral page — not pages/admin
    ]
    for (const line of fine) expect(scan({ './Fine.tsx': line })).toEqual([])
  })
})
