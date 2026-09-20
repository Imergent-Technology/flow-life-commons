import { render, screen } from '@testing-library/react'
import { encodeQR } from 'qr'
import { describe, expect, it } from 'vitest'

import { QrCode } from './QrCode.tsx'

const URI = 'otpauth://totp/Flow%20Life:ada@example.org?secret=JBSWY3DPEHPK3PXP&issuer=Flow%20Life'

describe('QrCode', () => {
  it('draws exactly the modules of the QR code for the value, with a quiet zone', () => {
    render(<QrCode value={URI} label="setup" />)

    const matrix = encodeQR(URI, 'raw')
    const dark = matrix.flat().filter(Boolean).length
    const svg = screen.getByRole('img', { name: 'setup' })
    const path = svg.querySelector('path')?.getAttribute('d') ?? ''

    expect(path.match(/M/g)).toHaveLength(dark)
    expect(svg.getAttribute('viewBox')).toBe(
      `0 0 ${String(matrix.length + 8)} ${String(matrix.length + 8)}`,
    )
  })

  it('is a different picture for a different value, and the same one for the same', () => {
    const { container, rerender } = render(<QrCode value={URI} label="a" />)
    const first = container.querySelector('path')?.getAttribute('d')
    rerender(<QrCode value={`${URI}x`} label="a" />)
    const second = container.querySelector('path')?.getAttribute('d')
    rerender(<QrCode value={URI} label="a" />)

    expect(second).not.toBe(first)
    expect(container.querySelector('path')?.getAttribute('d')).toBe(first)
  })

  it('makes no request and embeds no markup from the value', () => {
    const { container } = render(<QrCode value={'<img src=x onerror=alert(1)>'} label="a" />)

    expect(container.querySelector('img')).toBeNull()
    expect(container.innerHTML).not.toContain('onerror')
  })
})
