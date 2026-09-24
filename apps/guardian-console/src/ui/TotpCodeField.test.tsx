import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { TotpCodeField } from './TotpCodeField.tsx'

function Harness({ onSubmit }: { onSubmit: (code: string) => void }) {
  const [code, setCode] = useState('')
  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        onSubmit(code)
      }}
    >
      <TotpCodeField label="Authentication code" value={code} onChange={setCode} />
      <button type="submit">Verify</button>
    </form>
  )
}

describe('TotpCodeField', () => {
  it('asks a phone for a numeric keypad and offers itself as a one-time code', () => {
    render(<Harness onSubmit={() => undefined} />)

    const field = screen.getByLabelText('Authentication code')
    expect(field).toHaveAttribute('inputmode', 'numeric')
    expect(field).toHaveAttribute('autocomplete', 'one-time-code')
    expect(field).toHaveAccessibleDescription('The 6-digit code from your authenticator app.')
  })

  it('keeps digits only, and no more than six', async () => {
    const user = userEvent.setup()
    render(<Harness onSubmit={() => undefined} />)
    const field = screen.getByLabelText('Authentication code')

    await user.type(field, '12a-3 4b5678')

    expect(field).toHaveValue('123456')
  })

  it('takes a pasted code the way an app shows it, spaces and all', async () => {
    const user = userEvent.setup()
    render(<Harness onSubmit={() => undefined} />)
    const field = screen.getByLabelText('Authentication code')

    await user.click(field)
    await user.paste('123 456')

    expect(field).toHaveValue('123456')
  })

  it('does not submit when the sixth digit arrives: only the person pressing Verify does', async () => {
    const onSubmit = vi.fn()
    const user = userEvent.setup()
    render(<Harness onSubmit={onSubmit} />)

    await user.type(screen.getByLabelText('Authentication code'), '123456')
    expect(onSubmit).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Verify' }))
    expect(onSubmit).toHaveBeenCalledExactlyOnceWith('123456')
  })
})
