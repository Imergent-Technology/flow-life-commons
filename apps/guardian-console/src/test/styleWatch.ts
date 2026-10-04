import { vi } from 'vitest'

/**
 * Watches for the one thing the production policy (`style-src 'self'`, no 'unsafe-inline', ADR 0026)
 * blocks from script: a `style` attribute written with `setAttribute` (which is how ProseMirror's
 * serializer writes every attribute a node spec names). Setting properties on `element.style` is CSSOM,
 * which the policy allows, so it is deliberately not counted.
 *
 * Call `stop()` when done.
 */
export function watchStyleWrites() {
  const spy = vi.spyOn(Element.prototype, 'setAttribute')
  return {
    /** The values written to a `style` attribute since the watch began. */
    writes: () =>
      spy.mock.calls.filter(([name]) => name.toLowerCase() === 'style').map(([, value]) => value),
    stop: () => {
      spy.mockRestore()
    },
  }
}
