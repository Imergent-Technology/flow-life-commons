import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { Checkbox } from './Checkbox.tsx'
import { Field } from './Field.tsx'
import { Input } from './Input.tsx'
import { Select } from './Select.tsx'
import { TextField } from './TextField.tsx'

describe('Field', () => {
  it('associates the label with the control, so the control is found and focused by it', async () => {
    const user = userEvent.setup()
    render(<Field label="Email address">{(control) => <Input {...control} />}</Field>)
    const input = screen.getByLabelText('Email address')
    expect(input.tagName).toBe('INPUT')
    await user.click(screen.getByText('Email address'))
    expect(input).toHaveFocus()
  })

  it('adds no description and no invalid flag when there is neither hint nor error', () => {
    render(<Field label="Name">{(control) => <Input {...control} />}</Field>)
    const input = screen.getByLabelText('Name')
    expect(input).not.toHaveAttribute('aria-describedby')
    expect(input).not.toHaveAttribute('aria-invalid')
  })

  it('describes the control by its hint', () => {
    render(
      <Field label="Name" hint="As on your passport">
        {(control) => <Input {...control} />}
      </Field>,
    )
    expect(screen.getByLabelText('Name')).toHaveAccessibleDescription('As on your passport')
  })

  it('describes the control by its error, marks it invalid, and shows the error as text', () => {
    render(
      <Field label="Name" error="Enter a name.">
        {(control) => <Input {...control} />}
      </Field>,
    )
    const input = screen.getByLabelText('Name')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('Enter a name.')
    expect(screen.getByText('Enter a name.')).toBeVisible()
  })

  it('describes the control by both hint and error, hint first', () => {
    render(
      <Field label="Name" hint="As on your passport" error="Enter a name.">
        {(control) => <Input {...control} />}
      </Field>,
    )
    expect(screen.getByLabelText('Name')).toHaveAccessibleDescription(
      'As on your passport Enter a name.',
    )
  })

  it('works for a select too, with the same wiring', () => {
    render(
      <Field label="Role" error="Choose a role.">
        {(control) => (
          <Select {...control}>
            <option>Guardian</option>
          </Select>
        )}
      </Field>,
    )
    const select = screen.getByLabelText('Role')
    expect(select.tagName).toBe('SELECT')
    expect(select).toHaveAttribute('aria-invalid', 'true')
    expect(select).toHaveAccessibleDescription('Choose a role.')
  })
})

describe('Input', () => {
  it('passes native attributes straight through', () => {
    render(
      <Input
        aria-label="Email"
        type="email"
        name="email"
        autoComplete="username"
        inputMode="email"
        maxLength={40}
        required
        disabled
      />,
    )
    const input = screen.getByLabelText('Email')
    expect(input).toHaveAttribute('type', 'email')
    expect(input).toHaveAttribute('name', 'email')
    expect(input).toHaveAttribute('autocomplete', 'username')
    expect(input).toHaveAttribute('inputmode', 'email')
    expect(input).toHaveAttribute('maxlength', '40')
    expect(input).toBeRequired()
    expect(input).toBeDisabled()
  })

  it('is a controlled input the caller drives', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Input aria-label="Name" value="" onChange={onChange} />)
    await user.type(screen.getByLabelText('Name'), 'a')
    expect(onChange).toHaveBeenCalledTimes(1)
  })
})

describe('Checkbox', () => {
  it('is a native checkbox toggled by its label, and describable by a hint', async () => {
    const user = userEvent.setup()
    render(<Checkbox label="Send an invitation" hint="They will get an email." />)
    const box = screen.getByRole('checkbox', { name: 'Send an invitation' })
    expect(box).toHaveAccessibleDescription('They will get an email.')
    await user.click(screen.getByText('Send an invitation'))
    expect(box).toBeChecked()
  })
})

describe('TextField (legacy adapter over Field and Input)', () => {
  it('keeps the credential attributes and the label, hint and error wiring', () => {
    render(
      <TextField
        label="Password"
        name="password"
        type="password"
        autoComplete="current-password"
        value=""
        onChange={vi.fn()}
        hint="At least 12 characters"
        errors={['Too short.', 'Too common.']}
      />,
    )
    const input = screen.getByLabelText('Password')
    expect(input).toHaveAttribute('type', 'password')
    expect(input).toHaveAttribute('autocomplete', 'current-password')
    expect(input).toHaveAttribute('autocapitalize', 'none')
    expect(input).toHaveAttribute('autocorrect', 'off')
    expect(input).toHaveAttribute('spellcheck', 'false')
    expect(input).toBeRequired()
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('At least 12 characters Too short. Too common.')
  })

  it('reports what is typed as a plain string', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<TextField label="Name" name="name" autoComplete="name" value="" onChange={onChange} />)
    await user.type(screen.getByLabelText('Name'), 'x')
    expect(onChange).toHaveBeenCalledWith('x')
  })
})
