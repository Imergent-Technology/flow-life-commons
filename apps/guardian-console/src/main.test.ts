import ts from 'typescript'
import { describe, expect, it } from 'vitest'

import mainSource from './main.tsx?raw'

// The pre-render theme stamp (ADR 0030, design spec §9): `main.tsx` applies the resolved theme to <html>
// SYNCHRONOUSLY, before React has painted anything, so an operator whose theme differs from the default never
// sees a wrong-theme frame. A browser test cannot tell that apart from ThemeProvider correcting the theme
// just after mount (both end in the right theme, a frame apart), so the property is protected structurally:
// the module's own top-level statements must apply the theme, from the one preferences module, before the
// statement that creates the root.

/** What is wrong with a `main.tsx`, if anything. */
function stampProblems(source: string): string[] {
  const file = ts.createSourceFile(
    'main.tsx',
    source,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  )
  const problems: string[] = []

  const appliesTheme = (node: ts.Node): boolean =>
    ts.isExpressionStatement(node) &&
    ts.isCallExpression(node.expression) &&
    ts.isIdentifier(node.expression.expression) &&
    node.expression.expression.text === 'applyResolvedTheme'

  const containsCall = (node: ts.Node, name: string): boolean => {
    let found = false
    const visit = (child: ts.Node): void => {
      if (
        ts.isCallExpression(child) &&
        ts.isIdentifier(child.expression) &&
        child.expression.text === name
      ) {
        found = true
      }
      if (!found) ts.forEachChild(child, visit)
    }
    visit(node)
    return found
  }

  const statements = [...file.statements]
  const stamp = statements.findIndex(appliesTheme)
  const root = statements.findIndex((statement) => containsCall(statement, 'createRoot'))

  if (stamp === -1) {
    problems.push(
      'no top-level applyResolvedTheme(...) statement: it must run at module level, not inside a callback, effect or listener',
    )
  }
  if (root === -1) problems.push('no createRoot(...) statement')
  if (stamp !== -1 && root !== -1 && stamp > root) {
    problems.push(
      'applyResolvedTheme runs after createRoot: the first paint would be in the wrong theme',
    )
  }

  const call = statements.find(appliesTheme)
  if (
    call !== undefined &&
    ts.isExpressionStatement(call) &&
    ts.isCallExpression(call.expression) &&
    !call.expression.arguments.some((argument) => containsCall(argument, 'readInitialTheme'))
  ) {
    problems.push('applyResolvedTheme must be given the preference resolved by readInitialTheme()')
  }

  const fromPreferences = statements.some(
    (statement) =>
      ts.isImportDeclaration(statement) &&
      ts.isStringLiteral(statement.moduleSpecifier) &&
      /(^|\/)ui\/preferences(\.ts)?$/.test(statement.moduleSpecifier.text) &&
      statement.getText(file).includes('applyResolvedTheme') &&
      statement.getText(file).includes('readInitialTheme'),
  )
  if (!fromPreferences) {
    problems.push(
      'applyResolvedTheme and readInitialTheme must come from ui/preferences (one owner, no copy)',
    )
  }
  return problems
}

const good = `
import { createRoot } from 'react-dom/client'
import { applyResolvedTheme, readInitialTheme } from './ui/preferences.ts'
applyResolvedTheme(readInitialTheme().resolved)
createRoot(document.getElementById('root')!).render(null)
`

describe('the pre-render theme stamp in main.tsx', () => {
  it('reads the real entry point, not an empty file', () => {
    expect(mainSource).toContain('createRoot')
    expect(mainSource.length).toBeGreaterThan(200)
  })

  it('applies the resolved theme synchronously, at module level, before the root is created', () => {
    expect(stampProblems(mainSource)).toEqual([])
  })

  describe('positive controls: each way of losing it is caught', () => {
    it('accepts the shape it protects', () => {
      expect(stampProblems(good)).toEqual([])
    })

    it('catches a stamp that was removed', () => {
      const without = good.replace('applyResolvedTheme(readInitialTheme().resolved)\n', '')
      expect(stampProblems(without).join()).toContain('no top-level applyResolvedTheme')
    })

    it('catches a stamp moved after createRoot', () => {
      const after = `
import { createRoot } from 'react-dom/client'
import { applyResolvedTheme, readInitialTheme } from './ui/preferences.ts'
createRoot(document.getElementById('root')!).render(null)
applyResolvedTheme(readInitialTheme().resolved)
`
      expect(stampProblems(after).join()).toContain('after createRoot')
    })

    it('catches a stamp deferred until after mount (a callback, a microtask, a listener)', () => {
      for (const deferred of [
        'queueMicrotask(() => { applyResolvedTheme(readInitialTheme().resolved) })',
        'setTimeout(() => { applyResolvedTheme(readInitialTheme().resolved) }, 0)',
        "addEventListener('DOMContentLoaded', () => { applyResolvedTheme(readInitialTheme().resolved) })",
        'function later() { applyResolvedTheme(readInitialTheme().resolved) }',
      ]) {
        const source = good.replace('applyResolvedTheme(readInitialTheme().resolved)', deferred)
        expect(stampProblems(source).join(), deferred).toContain('no top-level applyResolvedTheme')
      }
    })

    it('catches a stamp given something other than the resolved stored preference', () => {
      const wrong = good.replace('readInitialTheme().resolved', "'light'")
      expect(stampProblems(wrong).join()).toContain('readInitialTheme()')
    })

    it('catches a second copy of the theme logic in place of the preferences module', () => {
      const copy = good.replace("'./ui/preferences.ts'", "'./somewhere-else.ts'")
      expect(stampProblems(copy).join()).toContain('ui/preferences')
    })
  })
})
