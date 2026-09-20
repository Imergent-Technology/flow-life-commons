import { encodeQR } from 'qr'
import { useMemo } from 'react'

/**
 * A QR code drawn IN THE BROWSER from a value that contains a secret (a provisioning URI). Nothing here
 * makes a request: the value never leaves this page, which is the whole point of not using a QR-code
 * service. It is rendered as SVG paths from the module matrix, not as markup from a string, so there is no
 * HTML injection surface.
 *
 * White background and a four-module quiet zone, whatever the theme: scanners need dark on light.
 */
export function QrCode({ value, label }: { value: string; label: string }) {
  const { path, size } = useMemo(() => {
    const matrix = encodeQR(value, 'raw')
    const quiet = 4
    const cells = matrix.flatMap((row, y) =>
      row.map((dark, x) => (dark ? `M${String(x + quiet)} ${String(y + quiet)}h1v1h-1z` : '')),
    )
    return { path: cells.join(''), size: matrix.length + quiet * 2 }
  }, [value])

  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${String(size)} ${String(size)}`}
      shapeRendering="crispEdges"
      className="h-56 w-56 rounded-md border border-slate-200 bg-white"
    >
      <path d={path} fill="#000" />
    </svg>
  )
}
