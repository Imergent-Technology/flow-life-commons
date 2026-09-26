import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { Button } from './Button.tsx'
import { buttonVariants } from './button-variants.ts'
import { SubmitButton } from './SubmitButton.tsx'

describe('Button', () => {
  it('is a native button that does not submit a form unless asked to', async () => {
    const user = userEvent.setup()
    const submit = vi.fn((event: React.SubmitEvent) => {
      event.preventDefault()
    })
    render(
      <form onSubmit={submit}>
        <Button>Plain</Button>
        <Button type="submit">Send</Button>
      </form>,
    )

    expect(screen.getByRole('button', { name: 'Plain' })).toHaveAttribute('type', 'button')
    await user.click(screen.getByRole('button', { name: 'Plain' }))
    expect(submit).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Send' }))
    expect(submit).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['primary', 'bg-primary'],
    ['secondary', 'border-border-strong'],
    ['ghost', 'hover:bg-muted'],
    ['danger', 'text-danger'],
    ['danger-solid', 'bg-danger'],
  ] as const)('renders the %s variant from semantic tokens', (variant, marker) => {
    render(<Button variant={variant}>Go</Button>)
    expect(screen.getByRole('button', { name: 'Go' })).toHaveClass(marker)
  })

  it('keeps solid red for danger-solid: the contextual danger variant is an outline', () => {
    render(
      <>
        <Button variant="danger">Outline</Button>
        <Button variant="danger-solid">Solid</Button>
      </>,
    )
    expect(screen.getByRole('button', { name: 'Outline' })).not.toHaveClass('bg-danger')
    expect(screen.getByRole('button', { name: 'Solid' })).toHaveClass('bg-danger')
  })

  it.each([
    ['sm', 'h-(--control-sm)'],
    ['md', 'h-(--control-md)'],
    ['lg', 'h-(--control-lg)'],
  ] as const)('sizes %s from the control-height tokens', (size, marker) => {
    render(<Button size={size}>Go</Button>)
    expect(screen.getByRole('button', { name: 'Go' })).toHaveClass(marker)
  })

  it('has a visible focus ring', () => {
    render(<Button>Go</Button>)
    expect(screen.getByRole('button')).toHaveClass('focus-visible:outline-ring')
  })

  it('cannot be activated while disabled', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()
    render(
      <Button disabled onClick={onClick}>
        Go
      </Button>,
    )
    await user.click(screen.getByRole('button'))
    expect(screen.getByRole('button')).toBeDisabled()
    expect(onClick).not.toHaveBeenCalled()
  })

  it('cannot be activated again while pending, and says it is busy', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()
    render(
      <Button pending pendingLabel="Saving…" onClick={onClick}>
        Save
      </Button>,
    )
    const button = screen.getByRole('button', { name: 'Saving…' })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('aria-busy', 'true')
    await user.click(button)
    button.focus()
    await user.keyboard('{Enter}')
    expect(onClick).not.toHaveBeenCalled()
  })

  it('shows the idle label, and no busy state, when not pending', () => {
    render(
      <Button pendingLabel="Saving…" onClick={vi.fn()}>
        Save
      </Button>,
    )
    const button = screen.getByRole('button', { name: 'Save' })
    expect(button).toBeEnabled()
    expect(button).not.toHaveAttribute('aria-busy')
  })

  it('reserves the width of the other label so it does not jump', () => {
    const { rerender } = render(
      <Button pending={false} pendingLabel="Saving…">
        Save
      </Button>,
    )
    expect(screen.getByText('Save')).toHaveAttribute('data-reserve', 'Saving…')
    rerender(
      <Button pending pendingLabel="Saving…">
        Save
      </Button>,
    )
    expect(screen.getByText('Saving…')).toHaveAttribute('data-reserve', 'Save')
  })

  it('lets a caller override a default class', () => {
    render(<Button className="px-8">Go</Button>)
    const button = screen.getByRole('button')
    expect(button).toHaveClass('px-8')
    expect(button).not.toHaveClass('px-3.5')
  })

  it('gives a link the same look through buttonVariants', () => {
    render(
      <a href="/x" className={buttonVariants({ variant: 'primary' })}>
        Go
      </a>,
    )
    expect(screen.getByRole('link', { name: 'Go' })).toHaveClass('bg-primary')
  })
})

describe('SubmitButton', () => {
  it('is a primary submit button that swaps its label while pending', () => {
    const { rerender } = render(
      <SubmitButton pending={false} pendingLabel="Signing in…">
        Sign in
      </SubmitButton>,
    )
    const idle = screen.getByRole('button', { name: 'Sign in' })
    expect(idle).toHaveAttribute('type', 'submit')
    expect(idle).toHaveClass('bg-primary')
    expect(idle).toBeEnabled()

    rerender(
      <SubmitButton pending pendingLabel="Signing in…">
        Sign in
      </SubmitButton>,
    )
    expect(screen.getByRole('button', { name: 'Signing in…' })).toBeDisabled()
  })
})
