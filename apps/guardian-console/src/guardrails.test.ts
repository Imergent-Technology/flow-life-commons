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

const rules: Rule[] = [
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
    fine: "<div role=\"alert\">Flow Life Guardian Console</div> hasCapability(current, 'console.access') const ROLES_ASSIGN = 'access.roles.assign'",
  },
  {
    name: 'browser storage',
    because:
      'credentials, tokens and account state must not be readable by script or persist (ADR 0016); the HttpOnly session is the only authority',
    pattern: /\b(?:localStorage|sessionStorage|indexedDB|openDatabase)\b/,
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
