import { describe, expect, it } from 'vitest'

// Source rules for the relationship screens (ADR 0038, C2, C4, K5, A9; WP5). Each is tied to a concrete risk, and each has
// positive controls (a line that MUST be flagged, so the rule cannot pass by matching nothing) and a negative control (a line
// that must NOT be). Behaviour that can be proved by running the screens is proved in the component tests; these catch what
// running one path cannot: a type's name, a role or a decision written somewhere it must never be.

interface Rule {
  name: string
  because: string
  /** The files the rule inspects. */
  appliesTo: RegExp
  /** The files that may legitimately contain it. */
  allowedIn?: RegExp
  /** What it forbids, tested on a line of source. */
  pattern: RegExp
  offends: string[]
  fine: string[]
}

const anyProduction = /^\.\/.*\.tsx?$/
const relationshipScreens =
  /^\.\/(?:relationships\/|pages\/relationships\/|pages\/people\/RelationshipsSection\.tsx|shell\/relationshipNavigation\.ts)/

const rules: Rule[] = [
  {
    name: 'a relationship type named in the source',
    because:
      "types, labels and slugs arrive as served definitions (ADR 0038, C1, C2); a name written here would be a second catalog that drifts, and would make a type's screens different from another's. The registry of extensions is the one place a type key may be written, and the resources client holds the audience key",
    appliesTo: anyProduction,
    allowedIn: /^\.\/(?:relationships\/extensions\.ts|api\/resources\.ts)$/,
    pattern:
      /['"`](?:guardians?|volunteers?|apprentices?)['"`]|\/relationships\/(?:guardians?|volunteers?)\b/,
    offends: [
      "const slug = 'volunteers'",
      "const type = 'volunteer'",
      "navigate('/relationships/guardians/new')",
      'const path = `/relationships/volunteers/${id}`',
    ],
    fine: [
      'const path = `/relationships/${type.slug}/${id}`',
      "const sentence = 'Volunteers are people who help'",
      "to: '/relationships'",
    ],
  },
  {
    name: 'a relationship capability named in the source',
    because:
      "what an operator may do with a type arrives as that type's `actions` (ADR 0038, F13); a capability name written here would let the Console decide from a hint, or from another capability, what only the server decides (A2)",
    appliesTo: anyProduction,
    pattern: /['"`](?:guardians?|volunteers?)\.(?:view|manage)['"`]/,
    offends: ["const MANAGE = 'guardians.manage'", 'can(`volunteers.view`)'],
    fine: ["const READ = 'crm.people.view'", 'type.actions.manage'],
  },
  {
    name: 'a relationship screen reaching another API',
    because:
      'the relationship screens work only through the relationship client: no CRM contact data, no Account, role, Membership, security or Resources client (ADR 0038, P2, P6, C4). A source test pins that the client names only its own routes, so this keeps the screens from reaching around it',
    appliesTo: relationshipScreens,
    pattern:
      /from\s+['"][^'"]*\/api\/(?:people|tags|interactions|admin|membership|myMembership|resources|resourceLibrary|discussions|auth|csrf|health)(?:\.ts)?['"]/,
    offends: [
      "import { getPerson } from '../api/people.ts'",
      "import { listRoleCatalog } from '../../api/admin.ts'",
      "import { verifySecurity } from '../api/auth.ts'",
      "import { listLibrary } from '../api/resourceLibrary.ts'",
    ],
    fine: [
      "import { getRelationship } from '../api/relationships.ts'",
      "import { requestJson } from '../api/http.ts'",
      "import { useCurrentAccount } from '../auth/auth-context.ts'",
    ],
  },
  {
    name: 'a relationship endpoint called outside the typed client',
    because:
      'every relationship request goes through api/relationships.ts, which names the instance and revision each mutation needs and parses each reply strictly; a request made anywhere else bypasses both',
    appliesTo: anyProduction,
    allowedIn: /^\.\/api\/relationships\.ts$/,
    pattern: /\/api\/v1\/admin\/relationship(?:s|-types)/,
    offends: [
      "requestJson({ method: 'GET', path: '/api/v1/admin/relationship-types' }, ok)",
      'const path = `/api/v1/admin/relationships/${slug}`',
    ],
    fine: [
      "requestJson({ method: 'GET', path: '/api/v1/admin/people' }, ok)",
      "const page = '/relationships'",
    ],
  },
  {
    name: 'a relationship screen deciding from a capability',
    because:
      'what the operator may do comes from the served `actions`, and the server decides every request (ADR 0038, A2, A9). The one capability a screen may ask about is People view, for the link to the full record',
    appliesTo: relationshipScreens,
    allowedIn: /^\.\/relationships\/RelationshipRecord\.tsx$/,
    pattern: /auth\/capabilities|hasCapability|\.capabilities\.(?:includes|some|find|has)\b/,
    offends: [
      "import { hasCapability } from '../auth/capabilities.ts'",
      'if (current.capabilities.includes(x)) show()',
    ],
    fine: ['if (type.actions.manage) show()', 'const actions = type.actions'],
  },
  {
    name: 'a default-role word written outside the code that owns the choice',
    because:
      'the server never grants by omission and neither may the Console (ADR 0038, K5): `grant`, `decline` and `defer` are chosen in one file from the K5 table, and sent only after a deliberate confirmation. A word written anywhere else is a default that nobody confirmed',
    appliesTo: relationshipScreens,
    allowedIn: /^\.\/relationships\/(?:defaultRole\.ts|DefaultRolePanel\.tsx)$/,
    pattern: /['"`](?:grant|decline|defer)['"`]/,
    offends: ["const defaultRole = 'grant'", "defaultRole: 'defer'", 'const d = `decline`'],
    fine: ["const label = 'Grant access'", 'choiceToSend(option, checked)', "const rule = 'none'"],
  },
  {
    name: 'the Account page reading relationship data',
    because:
      "the Account page lists sourced grants from the Account endpoint alone (ADR 0038, K4, WP5). Importing the relationship client or screens there would assemble access from relationship data instead of Access's own answer",
    appliesTo: /^\.\/pages\/admin\//,
    pattern: /from\s+['"][^'"]*(?:\/relationships\/|\/api\/relationships)/,
    offends: [
      "import { getRelationship } from '../../api/relationships.ts'",
      "import { RelationshipPanel } from '../../relationships/RelationshipPanel.tsx'",
    ],
    fine: ["import { getAccount } from '../../api/admin.ts'"],
  },
  {
    name: 'a revoke action for sourced access',
    because:
      'a sourced grant is withdrawn by a decision on its source, never revoked from an Account (ADR 0038, K4); the Console offers no revoke for one',
    appliesTo: /^\.\/pages\/admin\/SourcedGrantsSection\.tsx$/,
    pattern: /revokeRole|<Button|onClick|DELETE/,
    offends: ["import { revokeRole } from '../../api/admin.ts'", '<Button>Remove</Button>'],
    fine: ['<ul aria-label="Access through a source">'],
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
      if (!rule.appliesTo.test(file)) continue
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

describe('relationship source rules', () => {
  it('looks at the real source: the screens, the client, the navigation and the Account page', () => {
    const paths = Object.keys(production)
    for (const expected of [
      './api/relationships.ts',
      './relationships/RelationshipPanel.tsx',
      './relationships/RelationshipIntake.tsx',
      './relationships/DefaultRolePanel.tsx',
      './relationships/extensions.ts',
      './pages/relationships/RelationshipRoute.tsx',
      './pages/people/RelationshipsSection.tsx',
      './pages/admin/SourcedGrantsSection.tsx',
      './shell/relationshipNavigation.ts',
    ]) {
      expect(paths, `${expected} was not scanned`).toContain(expected)
    }
    expect(paths.some((p) => p.endsWith('.test.tsx') || p.includes('/test/'))).toBe(false)
  })

  it('finds no violation in the Console', () => {
    expect(scan(production)).toEqual([])
  })

  describe.each(rules)('$name', (rule) => {
    it.each(rule.offends)('flags %s (positive control)', (line) => {
      // Placed where the rule applies and where it is not allowed.
      const where = rule.appliesTo.test('./relationships/Offender.tsx')
        ? './relationships/Offender.tsx'
        : rule.appliesTo.test('./pages/admin/SourcedGrantsSection.tsx')
          ? './pages/admin/SourcedGrantsSection.tsx'
          : './pages/Offender.tsx'
      expect(scan({ [where]: line }).map((v) => v.rule)).toContain(rule.name)
    })

    it.each(rule.fine)('does not flag %s (negative control)', (line) => {
      const where = rule.appliesTo.test('./relationships/Fine.tsx')
        ? './relationships/Fine.tsx'
        : rule.appliesTo.test('./pages/admin/SourcedGrantsSection.tsx')
          ? './pages/admin/SourcedGrantsSection.tsx'
          : './pages/Fine.tsx'
      expect(scan({ [where]: line }).map((v) => v.rule)).not.toContain(rule.name)
    })

    it('inspects only the files it names, and exempts only the files it allows', () => {
      expect(rule.because.length).toBeGreaterThan(40)
      if (rule.allowedIn !== undefined) {
        const allowed = Object.keys(production).filter((p) => rule.allowedIn?.test(p))
        expect(allowed.length, 'the exemption names a file that exists').toBeGreaterThan(0)
        // The same offending line in an allowed file is not flagged, and in another file it is.
        const [line] = rule.offends
        const [exempt] = allowed
        if (line !== undefined && exempt !== undefined) {
          expect(scan({ [exempt]: line }).map((v) => v.rule)).not.toContain(rule.name)
          const elsewhere = rule.appliesTo.test('./relationships/Elsewhere.tsx')
            ? './relationships/Elsewhere.tsx'
            : './pages/Elsewhere.tsx'
          expect(scan({ [elsewhere]: line }).map((v) => v.rule)).toContain(rule.name)
        }
      }
    })
  })

  it('names, in the typed client, only the relationship routes', () => {
    const client = production['./api/relationships.ts']
    expect(client).toBeDefined()
    const paths = [...(client ?? '').matchAll(/\/api\/v1\/[^'"`\s$)]*/g)].map((m) => m[0])
    expect(paths.length).toBeGreaterThanOrEqual(2)
    for (const path of paths) {
      expect(path).toMatch(/^\/api\/v1\/admin\/relationship(?:s|-types)/)
    }
    // The control: a client that reached for CRM or Accounts would be caught by the same check.
    const dirty = "path: '/api/v1/admin/people'"
    expect([...dirty.matchAll(/\/api\/v1\/[^'"`\s$)]*/g)].map((m) => m[0])[0]).not.toMatch(
      /^\/api\/v1\/admin\/relationship(?:s|-types)/,
    )
  })

  it('has the two ways of reading a relationship in two functions that never share a path', () => {
    const client = production['./api/relationships.ts'] ?? ''
    expect(client).toMatch(/export async function getRelationship\(/)
    expect(client).toMatch(/export function getRelationshipManagement\(/)
    expect(client).toMatch(/\/management`/)
  })
})
