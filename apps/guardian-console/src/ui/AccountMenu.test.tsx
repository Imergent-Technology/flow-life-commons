import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { operator, serveOperator } from '../test/admin.ts'
import { empty, json } from '../test/fakeApi.ts'
import { renderApp } from '../test/renderApp.tsx'
import { installViewport } from '../test/viewport.ts'
import { clearNavPreference } from './preferences.ts'
import { initials } from './initials.ts'

const STORAGE_KEY = 'flowlife.console.ui'
const ACCOUNT = operator(['console.access'])
const NAME = ACCOUNT.person.display_name

beforeEach(() => {
  localStorage.clear()
  clearNavPreference()
  localStorage.clear()
  installViewport(1400)
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.documentElement.removeAttribute('data-theme')
})

async function signedIn(path = '/') {
  const api = serveOperator(ACCOUNT)
  api.on('POST /api/v1/logout', () => empty())
  renderApp(path)
  await screen.findByRole('button', { name: /account menu/i })
  // findBy resolves as the DOM appears, before passive effects (heading focus, subscriptions) have run.
  await act(() => Promise.resolve())
  return api
}

/** The element the button controls: the popup around the header and the menu. */
const popupElement = (): HTMLElement => {
  const element = document.getElementById(trigger().getAttribute('aria-controls') ?? '')
  if (element === null) throw new Error('The menu button controls nothing.')
  return element
}
const trigger = () => screen.getByRole('button', { name: /account menu/i })
const menu = () => screen.getByRole('menu', { name: 'Account' })
const item = (name: string) => screen.getByRole('menuitem', { name })
const radio = (name: string) => screen.getByRole('menuitemradio', { name })

describe('initials', () => {
  it.each([
    ['Ada Lovelace', 'AL'],
    ['Ada', 'A'],
    ['  ada   byron   lovelace ', 'AL'],
    ['Hēnare Tāne', 'HT'],
    ['élodie', 'É'],
    ['', ''],
  ])('%j is %j', (name, expected) => {
    expect(initials(name)).toBe(expected)
  })
})

describe('the account menu button', () => {
  it('shows the avatar, the name and a chevron, and is named for what it opens', async () => {
    await signedIn()
    expect(trigger()).toHaveTextContent(NAME)
    expect(trigger()).toHaveAccessibleName(`${NAME} account menu`)
    expect(within(trigger()).getByText(initials(NAME))).toHaveAttribute('aria-hidden', 'true')
    expect(trigger()).toHaveAttribute('aria-haspopup', 'menu')
    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
  })

  it('names the menu it controls, and that element exists whether it is open or not', async () => {
    const user = userEvent.setup()
    await signedIn()
    const id = trigger().getAttribute('aria-controls') ?? ''
    expect(document.getElementById(id)).toHaveAttribute('hidden')
    await user.click(trigger())
    expect(document.getElementById(id)).not.toHaveAttribute('hidden')
    expect(trigger()).toHaveAttribute('aria-expanded', 'true')
  })
})

describe('the account menu contents', () => {
  it('shows who is signed in, then Account security, the Theme choices, and Sign out, in that order', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())

    const popup = popupElement()
    expect(within(popup).getAllByText(NAME).length).toBeGreaterThan(0)
    expect(within(popup).getByText(ACCOUNT.account.email)).toBeVisible()
    const items = Array.from(menu().querySelectorAll('[role^="menuitem"]')).map(
      (element) => element.textContent,
    )
    expect(items).toEqual(['Account security', 'System', 'Light', 'Dark', 'Sign out'])
    const order = Array.from(popup.querySelectorAll('*'))
    const security = order.indexOf(item('Account security'))
    const theme = order.indexOf(radio('System'))
    const signOut = order.indexOf(item('Sign out'))
    expect(security).toBeLessThan(theme)
    expect(theme).toBeLessThan(signOut)
  })

  it('carries no menu content while closed, so it adds nothing to the page', async () => {
    await signedIn()
    expect(screen.queryByRole('menu')).toBeNull()
    expect(screen.queryByText('Sign out')).toBeNull()
    expect(screen.queryByText(ACCOUNT.account.email)).toBeNull()
  })

  it('makes Account security a link to its own route', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    expect(item('Account security')).toHaveAttribute('href', '/account/security')
  })

  it('offers Theme as a labelled group of three radio menu items, System, Light, Dark', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    const group = within(menu()).getByRole('group', { name: 'Theme' })
    expect(
      within(group)
        .getAllByRole('menuitemradio')
        .map((r) => r.textContent),
    ).toEqual(['System', 'Light', 'Dark'])
  })
})

describe('opening and closing', () => {
  it('opens on a click and closes on the next', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    expect(menu()).toBeVisible()
    await user.click(trigger())
    expect(screen.queryByRole('menu')).toBeNull()
    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
  })

  it('closes on a press outside, without moving focus to the button', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    fireEvent.pointerDown(screen.getByRole('main'))
    await waitFor(() => {
      expect(screen.queryByRole('menu')).toBeNull()
    })
    expect(trigger()).not.toHaveFocus()
  })

  it('stays open for a press inside it', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    fireEvent.pointerDown(within(menu()).getByText('Theme'))
    expect(menu()).toBeVisible()
  })
})

describe('the keyboard', () => {
  it('opens on Enter, on Space and on ArrowDown onto the first item, and on ArrowUp onto the last', async () => {
    const user = userEvent.setup()
    await signedIn()

    trigger().focus()
    await user.keyboard('{Enter}')
    expect(item('Account security')).toHaveFocus()
    await user.keyboard('{Escape}')

    await user.keyboard(' ')
    expect(item('Account security')).toHaveFocus()
    await user.keyboard('{Escape}')

    await user.keyboard('{ArrowDown}')
    expect(item('Account security')).toHaveFocus()
    await user.keyboard('{Escape}')

    await user.keyboard('{ArrowUp}')
    expect(item('Sign out')).toHaveFocus()
  })

  it('moves with ArrowDown and ArrowUp, wrapping, and jumps with Home and End', async () => {
    const user = userEvent.setup()
    await signedIn()
    trigger().focus()
    await user.keyboard('{Enter}')

    await user.keyboard('{ArrowDown}')
    expect(radio('System')).toHaveFocus()
    await user.keyboard('{End}')
    expect(item('Sign out')).toHaveFocus()
    await user.keyboard('{ArrowDown}')
    expect(item('Account security')).toHaveFocus()
    await user.keyboard('{ArrowUp}')
    expect(item('Sign out')).toHaveFocus()
    await user.keyboard('{Home}')
    expect(item('Account security')).toHaveFocus()
  })

  it('moves within the Theme choices with ArrowLeft and ArrowRight, and only there', async () => {
    const user = userEvent.setup()
    await signedIn()
    trigger().focus()
    await user.keyboard('{Enter}')

    await user.keyboard('{ArrowLeft}') // on Account security: nothing to move within
    expect(item('Account security')).toHaveFocus()

    await user.keyboard('{ArrowDown}')
    await user.keyboard('{ArrowRight}')
    expect(radio('Light')).toHaveFocus()
    await user.keyboard('{ArrowRight}{ArrowRight}') // wraps
    expect(radio('System')).toHaveFocus()
    await user.keyboard('{ArrowLeft}')
    expect(radio('Dark')).toHaveFocus()
  })

  it('closes on Escape and returns focus to the button', async () => {
    const user = userEvent.setup()
    await signedIn()
    trigger().focus()
    await user.keyboard('{Enter}{ArrowDown}{ArrowDown}')
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('menu')).toBeNull()
    expect(trigger()).toHaveFocus()
    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
  })

  it('closes on Tab and carries on from the button, without trapping', async () => {
    const user = userEvent.setup()
    await signedIn()
    trigger().focus()
    await user.keyboard('{Enter}')
    await user.keyboard('{Tab}')

    expect(screen.queryByRole('menu')).toBeNull()
    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
  })

  it('activates Account security from Enter and from Space', async () => {
    const user = userEvent.setup()
    await signedIn()
    trigger().focus()
    await user.keyboard('{Enter}{Enter}')
    expect(await screen.findByRole('heading', { level: 1, name: 'Account security' })).toBeVisible()

    trigger().focus()
    await user.keyboard('{Enter}')
    await user.keyboard(' ')
    await screen.findByRole('heading', { level: 1, name: 'Account security' })
    expect(screen.queryByRole('menu')).toBeNull()
  })
})

describe('the theme choices', () => {
  it('start on System, the default, with exactly one checked', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    expect(radio('System')).toHaveAttribute('aria-checked', 'true')
    expect(radio('Light')).toHaveAttribute('aria-checked', 'false')
    expect(radio('Dark')).toHaveAttribute('aria-checked', 'false')
  })

  it('apply at once, keep the menu open and focus on the choice, and are remembered', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    await user.click(radio('Dark'))

    expect(document.documentElement.dataset.theme).toBe('dark') // painted at once
    expect(menu()).toBeVisible() // not closed by choosing
    expect(trigger()).toHaveAttribute('aria-expanded', 'true')
    expect(radio('Dark')).toHaveAttribute('aria-checked', 'true')
    expect(radio('System')).toHaveAttribute('aria-checked', 'false')
    expect(radio('Dark')).toHaveFocus()
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')).toEqual({ v: 1, theme: 'dark' })
  })

  it('can be chosen from the keyboard, and keep the menu open', async () => {
    const user = userEvent.setup()
    await signedIn()
    trigger().focus()
    await user.keyboard('{Enter}{ArrowDown}{ArrowRight}{Enter}')
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(radio('Light')).toHaveAttribute('aria-checked', 'true')
    expect(menu()).toBeVisible()
  })

  it('go back to following the system when System is chosen again', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    await user.click(radio('Dark'))
    await user.click(radio('System'))
    expect(radio('System')).toHaveAttribute('aria-checked', 'true')
    expect(document.documentElement.dataset.theme).toBe('light') // the stub OS is not dark
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')).toEqual({
      v: 1,
      theme: 'system',
    })
  })

  it('are remembered on the next visit', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    await user.click(radio('Dark'))
    document.body.innerHTML = ''
    await signedIn()
    await userEvent.setup().click(trigger())
    expect(radio('Dark')).toHaveAttribute('aria-checked', 'true')
  })
})

describe('Account security', () => {
  it('closes the menu and takes the person there, with the heading focused', async () => {
    const user = userEvent.setup()
    await signedIn()
    await user.click(trigger())
    await user.click(item('Account security'))

    expect(await screen.findByRole('heading', { level: 1, name: 'Account security' })).toHaveFocus()
    expect(screen.queryByRole('menu')).toBeNull()
  })

  it('is marked current when the person is already there, and keeps focus on the button', async () => {
    const user = userEvent.setup()
    await signedIn('/account/security')
    await screen.findByRole('heading', { level: 1, name: 'Account security' })
    await user.click(trigger())
    expect(item('Account security')).toHaveAttribute('aria-current', 'page')
    await user.click(item('Account security'))
    expect(screen.queryByRole('menu')).toBeNull()
    expect(trigger()).toHaveFocus() // nothing else would move focus
  })
})

describe('Sign out', () => {
  it('signs out once, says it is working meanwhile, and ignores a second press', async () => {
    const user = userEvent.setup()
    const api = await signedIn()
    let finish: (response: Response) => void = () => undefined
    api.on(
      'POST /api/v1/logout',
      () =>
        new Promise<Response>((resolve) => {
          finish = resolve
        }),
    )
    await user.click(trigger())
    await user.click(item('Sign out'))

    const pending = await screen.findByRole('menuitem', { name: 'Signing out…' })
    expect(pending).toHaveAttribute('aria-disabled', 'true')
    await user.click(pending)
    await user.click(pending)
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(1)

    finish(empty())
    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(1)
  })

  it('does not pretend to be signed out when the server could not be told: it closes the menu and says so', async () => {
    const user = userEvent.setup()
    const api = await signedIn()
    api.on('POST /api/v1/logout', () => json({ message: 'down' }, 503))
    await user.click(trigger())
    await user.click(item('Sign out'))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('You could not be signed out')
    expect(alert).toHaveFocus() // announced, and where the person is looking
    expect(screen.queryByRole('menu')).toBeNull()
    expect(screen.getByRole('main')).toContainElement(alert) // at the top of the page content
    expect(trigger()).toBeVisible() // still signed in
    expect(screen.queryByRole('heading', { name: 'Sign in' })).toBeNull()
  })

  it('can be tried again after a failure, and a second failure is announced afresh', async () => {
    const user = userEvent.setup()
    const api = await signedIn()
    api.on('POST /api/v1/logout', () => json({ message: 'down' }, 503))
    await user.click(trigger())
    await user.click(item('Sign out'))
    const first = await screen.findByRole('alert')

    await user.click(trigger())
    expect(item('Sign out')).not.toHaveAttribute('aria-disabled')
    await user.click(item('Sign out'))
    await waitFor(() => {
      expect(screen.getByRole('alert')).not.toBe(first) // a new element, so it is announced again
    })
    expect(api.callsTo('POST /api/v1/logout')).toHaveLength(2)
  })
})
