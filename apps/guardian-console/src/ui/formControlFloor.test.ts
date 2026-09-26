import { describe, expect, it } from 'vitest'

// The form-control floor (design specification 4.1): every text-accepting control takes its size from
// `--text-control` (14px on desktop, 16px where the pointer is coarse or the viewport narrow), so focusing a
// field never zooms a phone. A control that sets a size of its own would beat that rule.

const controlFiles = ['./control-styles.ts', './Input.tsx', './Select.tsx']
const sizeUtility = /\btext-(?:xs|sm|base|lg|xl|\dxl|title|dialog-title|section|body|label|meta|\[)/

const sources = import.meta.glob<string>(['./*.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
})

interface Rule {
  name: string
  pattern: RegExp
  offends: string
  fine: string
}

function scan(files: Record<string, string>, only: readonly Rule[]) {
  const found: { file: string; rule: string }[] = []
  for (const [file, source] of Object.entries(files)) {
    for (const rule of only) if (rule.pattern.test(source)) found.push({ file, rule: rule.name })
  }
  return found
}

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
