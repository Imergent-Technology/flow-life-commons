/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

// Read from disk, not imported: the test configuration compiles no CSS (`css: false`), and a stylesheet
// imported as text would arrive empty.
const tokensSource = readFileSync(join(import.meta.dirname, 'tokens.css'), 'utf8')

// The contrast promised by the design (docs/design/guardian-console-visual-system.md §11), computed from
// the tokens that SHIP: this reads src/theme/tokens.css itself, so a token cannot change without its
// contrast being judged, and no test fixture holds a second copy of the values.
//
// WCAG 2.x relative-luminance contrast. Translucent tokens (the navigation, the hover fills) are
// composited over the surface they actually sit on before being measured, and the surface under the
// navigation is not one colour: it is the page ground and the top of the atmospheric wash, so the pair is
// measured over each of them and the worst of them is what must pass.

// ---------------------------------------------------------------------------------------------------
// Reading the tokens
// ---------------------------------------------------------------------------------------------------

interface Colour {
  r: number
  g: number
  b: number
  a: number
}

if (tokensSource.length < 5000) throw new Error('tokens.css was read empty')

const withoutComments = tokensSource.replace(/\/\*[\s\S]*?\*\//g, '')

/** The text between the braces that follow `opener`, matched as CSS nests them. */
function blockAfter(source: string, opener: string): string {
  const start = source.indexOf(opener)
  if (start === -1) throw new Error(`tokens.css has no block opened by ${opener}`)
  const open = source.indexOf('{', start)
  let depth = 0
  for (let index = open; index < source.length; index += 1) {
    if (source[index] === '{') depth += 1
    if (source[index] === '}') {
      depth -= 1
      if (depth === 0) return source.slice(open + 1, index)
    }
  }
  throw new Error(`tokens.css block ${opener} is not closed`)
}

function declarations(block: string): Map<string, string> {
  const found = new Map<string, string>()
  for (const match of block.matchAll(/--([a-z0-9-]+):\s*([^;]+);/g)) {
    const [, name, value] = match
    if (name !== undefined && value !== undefined)
      found.set(name, value.replace(/\s+/g, ' ').trim())
  }
  return found
}

const garden = declarations(blockAfter(withoutComments, ':root {'))
const deepTide = declarations(blockAfter(withoutComments, ":root[data-theme='dark'] {"))
const deepTideBeforeScript = declarations(
  blockAfter(
    blockAfter(withoutComments, '@media (prefers-color-scheme: dark) {'),
    ":root:not([data-theme='light']) {",
  ),
)
const themes = { Garden: garden, 'Deep Tide': deepTide } as const

function parseColour(value: string): Colour {
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value)
  if (hex?.[1] !== undefined) {
    const digits = hex[1].length === 3 ? hex[1].replace(/./g, (c) => c + c) : hex[1]
    return {
      r: parseInt(digits.slice(0, 2), 16),
      g: parseInt(digits.slice(2, 4), 16),
      b: parseInt(digits.slice(4, 6), 16),
      a: 1,
    }
  }
  const rgb = /^rgba?\(\s*(\d+)\s+(\d+)\s+(\d+)\s*(?:\/\s*([\d.]+))?\s*\)$/.exec(value)
  if (rgb?.[1] !== undefined && rgb[2] !== undefined && rgb[3] !== undefined) {
    return { r: Number(rgb[1]), g: Number(rgb[2]), b: Number(rgb[3]), a: Number(rgb[4] ?? 1) }
  }
  throw new Error(`cannot read the colour "${value}"`)
}

/** The colour stops of the wash that are opaque: the two that decide what sits under the page's top. */
function washStops(theme: Map<string, string>): [Colour, Colour] {
  const wash = theme.get('wash') ?? ''
  const stops = [...wash.matchAll(/(#[0-9a-f]{3,6}|rgb\([^)]*\))\s+[\d.]+%/gi)].map((m) =>
    parseColour(m[1] ?? ''),
  )
  const opaque = stops.filter((stop) => stop.a === 1)
  const [first, second] = opaque
  if (first === undefined || second === undefined)
    throw new Error('the wash has no two opaque stops')
  return [first, second]
}

// ---------------------------------------------------------------------------------------------------
// WCAG maths
// ---------------------------------------------------------------------------------------------------

function channel(value: number): number {
  const s = value / 255
  return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
}

function luminance({ r, g, b }: Colour): number {
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

export function contrastRatio(a: Colour, b: Colour): number {
  const [light, dark] = luminance(a) >= luminance(b) ? [a, b] : [b, a]
  return (luminance(light) + 0.05) / (luminance(dark) + 0.05)
}

function over(top: Colour, bottom: Colour): Colour {
  return {
    r: top.a * top.r + (1 - top.a) * bottom.r,
    g: top.a * top.g + (1 - top.a) * bottom.g,
    b: top.a * top.b + (1 - top.a) * bottom.b,
    a: 1,
  }
}

/**
 * A stack written top first and bottom last, joined by "/": `nav-active/nav/wash0` is the active-item fill
 * over the navigation over the first stop of the wash. `name@0.6` is the token at 60% of its own alpha (a
 * hover fill). The bottom of a stack must be opaque, or the ratio would not be one number.
 */
function resolve(theme: Map<string, string>, stack: string): Colour {
  const [wash0, wash1] = washStops(theme)
  const layers = stack.split('/').map((entry) => {
    const [name = '', scale] = entry.split('@')
    const colour =
      name === 'wash0'
        ? wash0
        : name === 'wash1'
          ? wash1
          : parseColour(
              theme.get(name) ??
                (() => {
                  throw new Error(`no token --${name}`)
                })(),
            )
    return scale === undefined ? colour : { ...colour, a: colour.a * Number(scale) }
  })
  const bottom = layers.at(-1)
  if (bottom?.a !== 1) throw new Error(`"${stack}" is not opaque at the bottom`)
  return layers.slice(0, -1).reduceRight((under, layer) => over(layer, under), bottom)
}

// ---------------------------------------------------------------------------------------------------
// What must be legible against what
// ---------------------------------------------------------------------------------------------------

const TEXT = 4.5 // WCAG 1.4.3, normal text: what the interface sets at 13–14px
const UI = 3 // WCAG 1.4.11, non-text: boundaries, focus, state

interface Requirement {
  /** The foreground: a token, or a stack over its backing surfaces. */
  fg: string
  /** Every surface it is read on; the pair must pass on each. */
  on: string[]
  min: number
  why: string
}

const grounds = ['background', 'wash0', 'wash1']
const cards = ['surface', 'surface-raised', 'muted']
const navBacking = grounds.map((ground) => `nav/${ground}`)

const requirements: Requirement[] = [
  // --- body text
  { fg: 'foreground', on: [...grounds, ...cards, 'field', 'accent'], min: TEXT, why: 'body text' },
  {
    fg: 'muted-foreground',
    on: [...grounds, ...cards],
    min: TEXT,
    why: 'descriptions, metadata and secondary cells, on the page and on every card',
  },
  {
    fg: 'primary-foreground',
    on: ['primary', 'primary-hover'],
    min: TEXT,
    why: 'the primary button',
  },
  {
    fg: 'primary',
    on: [...grounds, ...cards],
    min: TEXT,
    why: 'links, which are text in the action colour',
  },
  { fg: 'primary-hover', on: [...grounds, ...cards], min: TEXT, why: 'a hovered link' },
  {
    fg: 'accent-foreground',
    on: ['accent', ...cards],
    min: TEXT,
    why: 'the info alert and role chip',
  },

  // --- status
  { fg: 'success', on: ['success-soft', 'surface'], min: TEXT, why: 'the active badge' },
  { fg: 'warning', on: ['warning-soft', 'surface'], min: TEXT, why: 'the invited badge' },
  {
    fg: 'danger',
    on: ['danger-soft', 'surface', 'surface-raised', 'background', 'muted'],
    min: TEXT,
    why: 'errors, revoked text and the outlined destructive button, on the danger panel and beside it',
  },
  { fg: 'danger-foreground', on: ['danger'], min: TEXT, why: 'the solid destructive button' },
  { fg: 'neutral-foreground', on: ['neutral-soft'], min: TEXT, why: 'the inactive badge' },

  // --- navigation: the rail and pinned drawer sit over the ground and the wash; the overlay drawer and the sheet on a raised surface
  {
    fg: 'nav-foreground',
    on: [...navBacking, 'surface-raised'],
    min: TEXT,
    why: 'navigation items',
  },
  {
    fg: 'nav-strong',
    on: [...navBacking, 'surface-raised'],
    min: TEXT,
    why: 'drawer title, current rail label',
  },
  {
    fg: 'nav-muted',
    on: [...navBacking, 'surface-raised'],
    min: TEXT,
    why: 'the group labels (12.5px text)',
  },
  {
    fg: 'nav-active-foreground',
    on: [...grounds.map((ground) => `nav-active/nav/${ground}`), 'nav-active/surface-raised'],
    min: TEXT,
    why: 'the current drawer item',
  },
  {
    fg: 'nav-foreground',
    on: [
      ...grounds.map((ground) => `nav-active@0.6/nav/${ground}`),
      'nav-active@0.6/surface-raised',
    ],
    min: TEXT,
    why: 'a hovered drawer item',
  },

  // --- placeholder: the one text that is not required information (spec §3), so the non-text minimum
  {
    fg: 'subtle-foreground',
    on: ['field'],
    min: UI,
    why: 'placeholder text, never required information',
  },

  // --- non-text: control boundaries, focus, state
  {
    fg: 'input',
    // wash1, not wash0: the wash's first stop is the top bar's height, where no control is drawn; the search
    // field on the Accounts list sits over the wash's second stop and the ground it fades to.
    on: ['field', ...cards, 'background', 'wash1'],
    min: UI,
    why: 'the boundary of a form control, against its field and against whatever it sits on',
  },
  {
    fg: 'ring',
    // Not on `primary`: the ring is drawn with a 2px offset, so between a button and its ring is the ground.
    on: [...grounds, ...cards, 'field'],
    min: UI,
    why: 'the focus ring, against every ground it is drawn over',
  },
  {
    fg: 'nav-active-icon',
    on: [
      ...grounds.map((ground) => `nav-active/nav/${ground}`),
      'nav-active/surface-raised',
      ...navBacking,
      'surface-raised',
    ],
    min: UI,
    why: 'the current-place icon and its indicator bar, on the pill and on the navigation',
  },
  { fg: 'primary', on: [...cards, 'field'], min: UI, why: 'a checked checkbox' },
]

describe.each(Object.entries(themes))('contrast in %s', (themeName, theme) => {
  describe.each(requirements)('$fg: $why', ({ fg, on, min }) => {
    it.each(on)(`on %s is at least ${String(min)}:1`, (surface) => {
      const ratio = contrastRatio(resolve(theme, fg), resolve(theme, surface))
      expect(ratio, `${fg} on ${surface} in ${themeName}`).toBeGreaterThanOrEqual(min)
    })
  })
})

describe('the token definitions', () => {
  it('define the same roles in both themes, so neither can silently fall back to the other', () => {
    // The wash's height is a size, not a colour: it is stated once, with Garden, and both themes share it.
    const roles = (theme: Map<string, string>) =>
      [...theme.keys()].filter((name) => name !== 'wash-height').sort()
    expect(roles(deepTide)).toEqual(roles(garden))
  })

  it('keep the two Deep Tide blocks identical, so the theme that paints before the script is the theme that ships', () => {
    expect(Object.fromEntries(deepTideBeforeScript)).toEqual(Object.fromEntries(deepTide))
  })

  it('read the colours it measures from real, opaque, parseable values', () => {
    for (const name of ['background', 'surface', 'foreground', 'primary', 'ring', 'input']) {
      expect(parseColour(garden.get(name) ?? '').a).toBe(1)
      expect(parseColour(deepTide.get(name) ?? '').a).toBe(1)
    }
    expect(parseColour(garden.get('nav') ?? '').a).toBeLessThan(1) // translucent: it is composited, not read as opaque
  })
})

describe('the contrast maths', () => {
  const black = { r: 0, g: 0, b: 0, a: 1 }
  const white = { r: 255, g: 255, b: 255, a: 1 }

  it('gives 21:1 for black on white and 1:1 for a colour on itself', () => {
    expect(contrastRatio(black, white)).toBeCloseTo(21, 5)
    expect(contrastRatio(white, white)).toBeCloseTo(1, 5)
  })

  it('matches a known published value', () => {
    // #767676 on white is the classic AA-passing grey: 4.54:1.
    expect(contrastRatio(parseColour('#767676'), white)).toBeCloseTo(4.54, 2)
  })

  it('composites a translucent colour over what is under it, so it is never measured as if opaque', () => {
    const half = over({ r: 0, g: 0, b: 0, a: 0.5 }, white)
    expect(half.r).toBeCloseTo(127.5, 5)
    expect(contrastRatio(half, white)).toBeLessThan(5) // half-black on white is a mid grey, not 21:1
    // resolve() does exactly this for a stack: the navigation's white at 50% over the ground is not white.
    const rail = resolve(garden, 'nav/background')
    expect(rail.r).toBeGreaterThan(243)
    expect(rail.r).toBeLessThan(255)
  })

  it('fails a pair that is below its threshold (positive control)', () => {
    // #949494 on white is 3.03:1: it passes the non-text minimum and must fail the text one.
    const grey = parseColour('#949494')
    expect(contrastRatio(grey, white)).toBeGreaterThanOrEqual(UI)
    expect(contrastRatio(grey, white)).toBeLessThan(TEXT)
  })

  it('refuses a stack with no opaque bottom rather than measuring a guess', () => {
    expect(() => resolve(garden, 'nav')).toThrow(/not opaque/)
  })
})

describe('motion tokens', () => {
  it('drop to nothing when the person asks for reduced motion', () => {
    const reduced = declarations(
      blockAfter(
        blockAfter(withoutComments, '@media (prefers-reduced-motion: reduce) {'),
        ':root {',
      ),
    )
    for (const name of ['duration-fast', 'duration-base', 'duration-slow']) {
      expect(reduced.get(name), `--${name} under prefers-reduced-motion`).toBe('0ms')
    }
  })
})
