import { screen } from '@testing-library/react'
import type userEvent from '@testing-library/user-event'

type User = ReturnType<typeof userEvent.setup>

/** Opens the account menu from its button. */
export async function openAccountMenu(user: User) {
  await user.click(await screen.findByRole('button', { name: /account menu/i }))
  return screen.getByRole('menu', { name: 'Account' })
}

/** Signs out the way an operator does: through the account menu. */
export async function signOutViaMenu(user: User) {
  await openAccountMenu(user)
  await user.click(screen.getByRole('menuitem', { name: 'Sign out' }))
}
