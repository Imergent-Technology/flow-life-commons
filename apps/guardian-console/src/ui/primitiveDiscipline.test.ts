import { describe, expect, it } from 'vitest'

// The smallest check that the primitives, the shell and (since WP5) every page and auth file keep to the
// semantic visual system (ADR 0030): no raw palette utility, no arbitrary colour, no `dark:` variant, no
// reading of the theme. The permanent repository-wide rule is WP6's (with its own positive controls).

const primitives = [
  'Alert',
  'Badge',
  'Button',
  'Checkbox',
  'ConfirmDialog',
  'DataTable',
  'EmptyState',
  'Field',
  'Input',
  'Modal',
  'Page',
  'PageHeader',
  'Pagination',
  'Panel',
  'PropertyList',
  'Select',
  'Skeleton',
  'StatusIcon',
  // adapters that now delegate to the primitives
  'SubmitButton',
  'TextField',
  'StatusBadge',
].map((name) => `./${name}.tsx`)
const supporting = ['./cn.ts', './button-variants.ts', './control-styles.ts', './usePageHeading.ts']

const sources = import.meta.glob<string>(
  ['./*.{ts,tsx}', '../shell/*.{ts,tsx}', '../pages/**/*.{ts,tsx}', '../auth/*.{ts,tsx}'],
  {
    query: '?raw',
    import: 'default',
    eager: true,
  },
)

const palette =
  'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose'
const colourUtility =
  'bg|text|border|ring|outline|fill|stroke|from|via|to|divide|placeholder|accent|caret|decoration|shadow'

interface Rule {
  name: string
  pattern: RegExp
  /** Files that may legitimately do this: the one control that is the theme selector. */
  allowedIn?: RegExp
  offends: string
  fine: string
}

const rules: Rule[] = [
  {
    name: 'a raw palette utility',
    pattern: new RegExp(`\\b(?:${colourUtility})-(?:${palette})-\\d{2,3}\\b`),
    offends: 'className="bg-slate-100 hover:bg-red-50"',
    fine: 'className="bg-surface text-neutral-foreground border-danger/40 bg-neutral-soft"',
  },
  {
    name: 'a literal black or white',
    pattern: new RegExp(`\\b(?:${colourUtility})-(?:white|black)\\b`),
    offends: 'className="text-white"',
    fine: 'className="text-primary-foreground"',
  },
  {
    name: 'an arbitrary colour value',
    pattern: /-\[(?:#|rgb|hsl|oklch|color-mix)/,
    offends: 'className="bg-[#123456]"',
    fine: 'className="w-[min(32rem,calc(100vw-2rem))]"',
  },
  {
    name: 'a dark: variant',
    pattern: /\bdark:/,
    offends: 'className="bg-white dark:bg-slate-900"',
    fine: 'className="bg-surface"',
  },
  {
    name: 'reading the theme',
    pattern: /useTheme|resolvedTheme|data-theme|prefers-color-scheme/,
    // The account menu IS the theme selector: it reads the preference to show which choice is checked.
    allowedIn: /(^|\/)AccountMenu\.tsx$/,
    offends: "const { resolvedTheme } = useTheme(); if (resolvedTheme === 'dark') …",
    fine: 'className="bg-surface"',
  },
]

const controlFiles = ['./control-styles.ts', './Input.tsx', './Select.tsx']
const sizeUtility = /\btext-(?:xs|sm|base|lg|xl|\dxl|title|dialog-title|section|body|label|meta|\[)/

function scan(files: Record<string, string>, only: readonly Rule[] = rules) {
  const found: { file: string; rule: string }[] = []
  for (const [file, source] of Object.entries(files)) {
    for (const rule of only) {
      if (rule.allowedIn?.test(file) === true) continue
      if (rule.pattern.test(source)) found.push({ file, rule: rule.name })
    }
  }
  return found
}

// The application shell (WP4) is written in the same vocabulary, so it is held to the same rule.
const shell = Object.keys(sources).filter(
  (path) => path.startsWith('../shell/') && !/\.test\.tsx?$/.test(path),
)

// The theme machinery itself is the one thing allowed to read the theme.
const themeInfrastructure = new Set([
  './ThemeProvider.tsx',
  './preferences.ts',
  './theme-context.ts',
])

// Every page, credential flow and boundary component, and every non-test file in src/ui: migrated in WP5.
const migrated = Object.keys(sources).filter(
  (path) =>
    (path.startsWith('../pages/') ||
      path.startsWith('../auth/') ||
      (path.startsWith('./') && !themeInfrastructure.has(path))) &&
    !/\.test\.tsx?$/.test(path),
)

const scanned = Object.fromEntries(
  [...new Set([...primitives, ...supporting, ...shell, ...migrated])].map((path) => [
    path,
    sources[path] ?? '',
  ]),
)

describe('the new primitives keep to the semantic visual system', () => {
  it('looks at the real files, not an empty set', () => {
    for (const path of [...primitives, ...supporting]) {
      expect(sources[path], `${path} exists`).toBeTypeOf('string')
      expect((sources[path] ?? '').length, `${path} is not empty`).toBeGreaterThan(50)
    }
  })

  it('includes the shell, not an empty set', () => {
    expect(shell.length).toBeGreaterThanOrEqual(12)
    for (const expected of [
      '../shell/ConsoleShell.tsx',
      '../shell/AccountMenu.tsx',
      '../shell/Rail.tsx',
    ]) {
      expect(shell).toContain(expected)
    }
  })

  it('includes every page, not an empty set', () => {
    expect(migrated.length).toBeGreaterThanOrEqual(60)
    for (const expected of [
      '../pages/OverviewPage.tsx',
      '../pages/LoginPage.tsx',
      '../pages/AccountSecurityPage.tsx',
      '../pages/admin/AccountsPage.tsx',
      '../pages/admin/MembersPage.tsx',
      '../pages/admin/AccountDetailPage.tsx',
      '../auth/RequireCapability.tsx',
      './AuthLayout.tsx',
    ]) {
      expect(migrated).toContain(expected)
    }
  })

  it('finds no violation in them', () => {
    expect(scan(scanned)).toEqual([])
  })

  describe.each(rules)('$name', (rule) => {
    it('flags what it forbids (positive control)', () => {
      expect(scan({ './Offender.tsx': rule.offends }).map((v) => v.rule)).toContain(rule.name)
    })

    it('does not flag what is fine', () => {
      expect(scan({ './Fine.tsx': rule.fine }).map((v) => v.rule)).not.toContain(rule.name)
    })

    if (rule.allowedIn !== undefined) {
      it('allows only the named file', () => {
        const allowed = Object.keys(scanned).filter((path) => rule.allowedIn?.test(path))
        expect(allowed).toEqual(['../shell/AccountMenu.tsx'])
        expect(scan({ './Elsewhere.tsx': rule.offends }).map((v) => v.rule)).toContain(rule.name)
        expect(scan({ '../shell/AccountMenu.tsx': rule.offends })).toEqual([])
      })
    }
  })
})

describe('the form-control floor (design specification 4.1)', () => {
  const floorRule: Rule = {
    name: 'a text size on a control',
    pattern: sizeUtility,
    offends: 'className="h-9 text-sm"',
    fine: 'className="h-(--control-md) w-full rounded-sm border border-input bg-field"',
  }

  it('leaves the size of every text-accepting control to --text-control', () => {
    const controls = Object.fromEntries(controlFiles.map((path) => [path, sources[path] ?? '']))
    for (const path of controlFiles) expect(controls[path]?.length).toBeGreaterThan(50)
    expect(scan(controls, [floorRule])).toEqual([])
  })

  it('would catch a control that set its own size (positive control)', () => {
    expect(scan({ './Input.tsx': floorRule.offends }, [floorRule])).toHaveLength(1)
    expect(scan({ './Input.tsx': floorRule.fine }, [floorRule])).toEqual([])
  })
})
