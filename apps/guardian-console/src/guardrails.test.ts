/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

// Source rules for the Guardian Console, each tied to a concrete risk the Identity and Access design
// names (ADR 0016, 0017, 0022). They are deliberately few, and each has a positive control: the
// scanner is run over synthetic offending files, so a rule cannot pass just because it matches nothing.
// Behaviour that can be proved by running the Console (no storage writes, no logging, same-origin
// calls) is proved in the component tests instead; these catch what running one path cannot.

interface Rule {
  name: string
  /** What goes wrong if this appears. */
  because: string
  pattern: RegExp
  /** Files (by path) that may legitimately contain it. */
  allowedIn?: RegExp
  /** A line that MUST be flagged, proving the pattern can fail. */
  offends: string
  /** A line that must NOT be flagged, proving it is not just matching everything. */
  fine: string
}

// Theme-specific styling (ADR 0030). Colours are decided in src/theme and consumed by role (`bg-surface`,
// `text-danger`), so a component never names one, and never needs a `dark:` variant to be right in both themes.
const palette =
  'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose'
const colourUtility =
  'bg|text|fill|stroke|from|via|to|divide|placeholder|accent|caret|decoration|shadow|ring|ring-offset|outline|border(?:-[trblxyse])?|inset-shadow|drop-shadow'
const colourName = 'black|white|red|green|blue|gray|grey|orange|yellow|purple|pink|teal|cyan|indigo'
const literalColour = '(?<![\\w&])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![\\w-])'
const colourFunction = '\\b(?:rgba?|hsla?|oklch|oklab|lab|lch|hwb|color-mix)\\('

const rules: Rule[] = [
  {
    name: 'a raw palette utility',
    because:
      'a palette colour is a literal: it cannot follow the theme, and the theme is the design decision (ADR 0030)',
    pattern: new RegExp(`\\b(?:${colourUtility})-(?:${palette})-\\d{2,3}\\b`),
    offends: 'className="bg-slate-100 hover:bg-red-50 border-t-emerald-500"',
    fine: 'className="bg-surface text-neutral-foreground border-danger/40 bg-neutral-soft"',
  },
  {
    name: 'a literal black or white utility',
    because: 'black and white do not follow the theme; use the role that means what is intended',
    pattern: new RegExp(`\\b(?:${colourUtility})-(?:white|black)\\b`),
    offends: 'className="text-white bg-black/50"',
    fine: 'className="text-primary-foreground bg-scrim"',
  },
  {
    name: 'an arbitrary colour utility',
    because: 'an arbitrary colour is a literal in disguise: add a token in src/theme instead',
    pattern: new RegExp(
      `-\\[(?:#|rgba?\\(|hsla?\\(|oklch|oklab|lab\\(|lch\\(|hwb|color-mix|color\\(|color:|(?:${colourName})\\])`,
    ),
    offends: 'className="bg-[#123456] text-[rgb(1,2,3)] border-[color:red] fill-[red]"',
    fine: 'className="w-[min(32rem,calc(100vw-2rem))] shadow-[inset_0_-1px_0_var(--border)]"',
  },
  {
    name: 'a dark: variant',
    because:
      'a component that needs a dark: variant is using a colour that is not a role; roles follow the theme by themselves',
    pattern: /\bdark:|data-\[theme/,
    offends: 'className="bg-white dark:bg-slate-900"',
    fine: 'className="bg-surface"',
  },
  {
    name: 'a colour literal in source',
    because: 'colour values live in src/theme; source names a role',
    pattern: new RegExp(`${literalColour}|${colourFunction}`),
    // A QR code must be black modules on a white plate in EVERY theme, or scanners cannot read it: the
    // one place a colour is not a role. The plate is drawn into the image itself so no class can change it.
    allowedIn: /(^|\/)ui\/QrCode\.tsx$/,
    offends: 'const fill = "#a790ff"; const wash = `rgb(1 2 3 / 0.4)`',
    fine: "const anchor = '#main-content'; const id = `#${MAIN_ID}`; const url = '/a#b'",
  },
  {
    name: 'an inline colour style',
    because: 'a style attribute sets a literal colour beside the theme instead of through it',
    pattern: /style=\{\{[^}]*\b(?:color|background|backgroundColor|borderColor|fill|stroke)\b/,
    offends: "<div style={{ backgroundColor: 'tomato' }} />",
    fine: '<div style={{ width: size }} />',
  },
  {
    name: 'reading the theme',
    because:
      'a component that branches on the theme has stopped using roles; only the theme machinery, its pre-render stamp in main.tsx and the theme menu may know which theme is on',
    pattern: /useTheme|resolvedTheme|data-theme|prefers-color-scheme/,
    allowedIn:
      /(^|\/)ui\/(ThemeProvider\.tsx|preferences\.ts|theme-context\.ts)$|(^|\/)shell\/AccountMenu\.tsx$|^\.\/main\.tsx$/,
    offends: "const { resolvedTheme } = useTheme(); if (resolvedTheme === 'dark') …",
    fine: 'className="bg-surface"',
  },
  {
    name: 'the retired product name',
    because:
      'the product is Flow Life Commons and this interface is the Guardian Console; "Flow Life Guardian Console" is neither',
    pattern: /Flow Life Guardian Console/,
    offends: "document.title = 'Home · Flow Life Guardian Console'",
    fine: "document.title = 'Overview · Flow Life Commons'",
  },
  {
    name: 'system role names',
    because:
      'the Console asks about capabilities, never roles (ADR 0017); a role check here is the rot the design forbids',
    // Not `role="alert"`: that is an ARIA role, which the Console uses legitimately.
    // `access.roles.assign` is a CAPABILITY identifier (what an operator may do), not a role property, so it is the one
    // dotted name that contains `.roles` and is allowed; `account.roles` and `.role` stay flagged.
    pattern:
      /platform_administrator|['"`]guardian['"`]|\bis(?:Guardian|Admin|Administrator)\b|(?<!access)\.roles?\b|\broles\s*:/,
    offends: "if (account.roles.includes('platform_administrator')) show()",
    fine: "<div role=\"alert\">Flow Life Commons</div> hasCapability(current, 'console.access') const ROLES_ASSIGN = 'access.roles.assign'",
  },
  {
    name: 'browser storage',
    because:
      'credentials, tokens and account state must not be readable by script or persist (ADR 0016); the HttpOnly session is the only authority',
    // localStorage is narrowed, not banned, by the next rule: only non-sensitive UI preferences may
    // persist, through one audited module (ADR 0030). sessionStorage, indexedDB and openDatabase stay
    // banned everywhere, with no exception.
    pattern: /\b(?:sessionStorage|indexedDB|openDatabase)\b/,
    offends: "sessionStorage.setItem('token', value)",
    fine: 'const stored = useState(null)',
  },
  {
    name: 'localStorage outside the UI-preferences module',
    because:
      'only non-sensitive display preferences may persist, through one audited module (ADR 0030); identity, session and business data still have no browser-storage authority',
    pattern: /\blocalStorage\b/,
    allowedIn: /(^|\/)ui\/preferences\.ts$/,
    offends: "localStorage.setItem('token', value)",
    fine: 'const stored = useState(null)',
  },
  {
    name: 'writing a cookie',
    because: 'the Console never sets a cookie; the server owns the session cookie',
    pattern: /document\.cookie\s*=(?!=)/,
    offends: "document.cookie = 'a=b'",
    fine: 'const jar = document.cookie',
  },
  {
    name: 'reading cookies outside the request-forgery reader',
    because: 'only the XSRF-TOKEN reader may touch cookies (the session cookie is HttpOnly anyway)',
    pattern: /document\.cookie/,
    allowedIn: /(^|\/)api\/csrf\.ts$/,
    offends: 'const c = document.cookie',
    fine: 'const c = readXsrfToken()',
  },
  {
    name: 'fetch outside the API layer',
    because:
      'every request goes through one client so same-origin, forgery and status handling stay consistent',
    pattern: /\bfetch\s*\(/,
    allowedIn: /(^|\/)api\/(http|health)\.ts$/,
    offends: "await fetch('/api/v1/me')",
    fine: 'await fetchCurrentAccount()',
  },
  {
    name: 'an absolute URL',
    because: 'API calls are relative and same-origin (ADR 0016); there is no cross-origin base URL',
    pattern: /https?:\/\//,
    // returnPath.ts uses a placeholder origin to PARSE (never fetch) a path, and names hostile inputs.
    allowedIn: /(^|\/)auth\/returnPath\.ts$/,
    offends: "const base = 'https://api.example.org'",
    fine: "const path = '/api/v1/me'",
  },
  {
    name: 'credentialed cross-origin requests',
    because: 'credentialed CORS must never be introduced (ADR 0016)',
    pattern: /credentials:\s*['"]include['"]/,
    offends: "fetch(url, { credentials: 'include' })",
    fine: "fetch(url, { credentials: 'same-origin' })",
  },
  {
    name: 'a configurable API origin',
    because: 'the API base is not configurable: same origin, always',
    pattern: /import\.meta\.env|\bVITE_[A-Z_]+/,
    offends: 'const base = import.meta.env.VITE_API_URL',
    fine: 'const mode = process',
  },
  {
    name: 'writing a file outside the recovery-code panel',
    because:
      'recovery codes are shown once and leave the page as a file only by an intentional click in one place; nothing else may hand data to a file',
    pattern: /createObjectURL|\.download\s*=/,
    allowedIn: /(^|\/)ui\/RecoveryCodes\.tsx$/,
    offends: 'const url = URL.createObjectURL(new Blob([secret]))',
    fine: 'const text = codes.join(newline)',
  },
  {
    name: 'writing the clipboard outside the two panels that show a secret once',
    because:
      'recovery codes and a new authenticator setup key are shown once and may be copied by an intentional click, in those two components only; nothing else may hand data to the clipboard',
    pattern: /navigator\.clipboard/,
    allowedIn: /(^|\/)ui\/(RecoveryCodes|AuthenticatorSetup)\.tsx$/,
    offends: 'await navigator.clipboard.writeText(secret)',
    fine: 'const text = codes.join(newline)',
  },
  {
    name: 'raw HTML injection',
    because: 'the Console is the most privileged surface; markup from data would be XSS',
    pattern: /dangerouslySetInnerHTML|\.innerHTML\s*=/,
    offends: '<div dangerouslySetInnerHTML={{ __html: x }} />',
    fine: '<div>{x}</div>',
  },
]

interface Violation {
  file: string
  rule: string
}

function scan(files: Record<string, string>): Violation[] {
  const found: Violation[] = []
  for (const [file, source] of Object.entries(files)) {
    for (const rule of rules) {
      if (rule.allowedIn?.test(file)) continue
      if (rule.pattern.test(source)) found.push({ file, rule: rule.name })
    }
  }
  return found
}

const isProduction = (path: string) => !/\.test\.tsx?$/.test(path) && !path.includes('/test/')

const modules = import.meta.glob<string>('./**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})
const production = Object.fromEntries(
  Object.entries(modules).filter(([path]) => isProduction(path)),
)

describe('Console source rules', () => {
  it('looks at the real source, not an empty set', () => {
    const paths = Object.keys(production)
    expect(paths.length).toBeGreaterThan(20)
    for (const expected of [
      './auth/AuthProvider.tsx',
      './api/http.ts',
      './api/csrf.ts',
      './pages/LoginPage.tsx',
    ]) {
      expect(paths).toContain(expected)
    }
    expect(paths.some((p) => p.endsWith('.test.tsx') || p.includes('/test/'))).toBe(false)
  })

  it('finds no violation in the Console', () => {
    expect(scan(production)).toEqual([])
  })

  describe.each(rules)('$name', (rule) => {
    it('flags what it forbids (positive control)', () => {
      expect(scan({ './pages/Offender.tsx': rule.offends }).map((v) => v.rule)).toContain(rule.name)
    })

    it('does not flag what is fine', () => {
      expect(scan({ './pages/Fine.tsx': rule.fine }).map((v) => v.rule)).not.toContain(rule.name)
    })

    if (rule.allowedIn !== undefined) {
      it('allows only the named files', () => {
        const allowed = Object.keys(production).filter((p) => rule.allowedIn?.test(p))
        expect(allowed.length).toBeGreaterThan(0)
        expect(scan({ './pages/Elsewhere.tsx': rule.offends }).map((v) => v.rule)).toContain(
          rule.name,
        )
      })
    }
  })

  it('states each risk, so a failure explains itself', () => {
    for (const rule of rules) expect(rule.because.length).toBeGreaterThan(20)
  })
})

describe('colours outside src/theme', () => {
  // The stylesheets other than the theme's own: a colour written here would sit beside the tokens, not in them.
  // Listed by glob (so a new one is picked up) and read from disk, because the test configuration compiles no
  // CSS and an imported stylesheet would arrive empty.
  const listed = Object.keys(
    import.meta.glob(['./**/*.css', '!./theme/**'], {
      query: '?url',
      import: 'default',
      eager: true,
    }),
  )
  const sheets = Object.fromEntries(
    listed.map((path) => [path, readFileSync(join(import.meta.dirname, path), 'utf8')]),
  )
  const literal = new RegExp(`${literalColour}|${colourFunction}`)

  it('looks at the real stylesheets, and not the theme', () => {
    expect(listed).toContain('./index.css')
    expect(listed.some((path) => path.startsWith('./theme/'))).toBe(false)
    for (const [path, source] of Object.entries(sheets)) {
      expect(source.length, `${path} was read empty`).toBeGreaterThan(50)
    }
  })

  it('finds no colour literal in them', () => {
    const offenders = Object.entries(sheets)
      .filter(([, source]) => literal.test(source))
      .map(([path]) => path)
    expect(offenders).toEqual([])
  })

  it('would catch one (positive control)', () => {
    expect(literal.test('.x { color: #a790ff }')).toBe(true)
    expect(literal.test('.x { background: rgb(1 2 3 / 0.4) }')).toBe(true)
    expect(literal.test('.x { color: var(--foreground) }')).toBe(false)
  })
})
