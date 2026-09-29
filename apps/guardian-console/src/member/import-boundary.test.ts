import { describe, expect, it } from 'vitest'

// The Member surface must never become coupled to Guardian/admin implementation (ADR 0032, Work Package
// 4): it may import neutral shared code (ui/**, api/**, auth/**), but never ANY of Guardian's own page
// implementations (not just its admin ones — `MfaSection`, the one genuinely shared page-level component
// WP4 needed, now lives in `ui/`, so `pages/**` needs no exception), its shell, or its admin-only helpers.
// Scoped to `./**` relative to THIS file, which is `src/member/`, so it never has to name that prefix
// itself and automatically covers a file added here later.

const FORBIDDEN_ROOTS = ['pages', 'shell', 'admin']

const modules = import.meta.glob<string>('./**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const isProduction = (path: string) => !/\.test\.tsx?$/.test(path)
const production = Object.fromEntries(
  Object.entries(modules).filter(([path]) => isProduction(path)),
)

/**
 * Every string literal any import form names, in source order: a static or re-export `from` clause, a
 * bare side-effect import (`import '...'`, no `from`), and a dynamic `import('...')`. No path aliases
 * exist in this repository, so a literal specifier is all there is to resolve — no bundler-grade module
 * resolver is built here.
 */
function importSpecifiers(source: string): string[] {
  const patterns = [
    /(?:import|export)(?:(?!from)[\s\S])*?\bfrom\s+['"]([^'"]+)['"]/g,
    /\bimport\s+['"]([^'"]+)['"]/g,
    /\bimport\s*\(\s*['"]([^'"]+)['"]/g,
  ]
  return patterns.flatMap((pattern) =>
    Array.from(source.matchAll(pattern), (match) => match[1] ?? ''),
  )
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

  it('imports nothing from pages, shell or admin', () => {
    expect(scan(production)).toEqual([])
  })

  it('flags a forbidden static import, including a Guardian page outside pages/admin (positive control)', () => {
    expect(
      scan({ './Offender.tsx': "import { AccountsPage } from '../pages/admin/AccountsPage.tsx'" }),
    ).toEqual([{ file: './Offender.tsx', specifier: '../pages/admin/AccountsPage.tsx' }])
    expect(
      scan({ './Offender.tsx': "import { OverviewPage } from '../pages/OverviewPage.tsx'" }),
    ).toEqual([{ file: './Offender.tsx', specifier: '../pages/OverviewPage.tsx' }])
    expect(
      scan({ './Offender.tsx': "import { ConsoleShell } from '../shell/ConsoleShell.tsx'" }),
    ).toEqual([{ file: './Offender.tsx', specifier: '../shell/ConsoleShell.tsx' }])
    expect(scan({ './Offender.tsx': "import { useLoad } from '../admin/useLoad.ts'" })).toEqual([
      { file: './Offender.tsx', specifier: '../admin/useLoad.ts' },
    ])
  })

  it('flags a forbidden side-effect import, with no `from` clause (positive control)', () => {
    expect(scan({ './Offender.tsx': "import '../shell/some-global.css'" })).toEqual([
      { file: './Offender.tsx', specifier: '../shell/some-global.css' },
    ])
  })

  it('flags a forbidden dynamic import() (positive control)', () => {
    expect(
      scan({ './Offender.tsx': "const mod = await import('../admin/useAdminAction.ts')" }),
    ).toEqual([{ file: './Offender.tsx', specifier: '../admin/useAdminAction.ts' }])
  })

  it('does not flag the neutral areas Member code legitimately uses (negative control)', () => {
    const fine = [
      "import { Page } from '../ui/Page.tsx'",
      "import { getCurrentMembership } from '../api/myMembership.ts'",
      "import { useCurrentAccount } from '../auth/auth-context.ts'",
      "import { MembershipStateBadge } from './MembershipStateBadge.tsx'",
      "import { MfaSection } from '../ui/MfaSection.tsx'", // moved out of pages/ in WP4's remediation
      "import '../ui/some-neutral.css'", // a fine side-effect import
      "const mod = await import('../api/myMembership.ts')", // a fine dynamic import
    ]
    for (const line of fine) expect(scan({ './Fine.tsx': line })).toEqual([])
  })
})
