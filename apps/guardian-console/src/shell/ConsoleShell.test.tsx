import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { operator, page, serveOperator, wire } from '../test/admin.ts'
import { empty, json, type FakeApi } from '../test/fakeApi.ts'
import { membersPage } from '../test/membership.ts'
import { renderApp } from '../test/renderApp.tsx'
import { installViewport, resizeTo } from '../test/viewport.ts'
import { clearNavPreference, setNavPreference } from '../ui/preferences.ts'

const STORAGE_KEY = 'flowlife.console.ui'
const EVERYTHING = [
  'console.access',
  'identity.accounts.view',
  'identity.invitations.issue',
  'membership.records.view',
  'membership.records.manage',
]

beforeEach(() => {
  localStorage.clear()
  clearNavPreference()
  localStorage.clear()
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.documentElement.removeAttribute('data-theme')
})

function serve(capabilities: string[] = EVERYTHING): FakeApi {
  const api = serveOperator(operator(capabilities))
  api.on('GET /api/v1/admin/accounts', () => json(page([wire()])))
  api.on('GET /api/v1/admin/members', () => json(membersPage([])))
  api.on('POST /api/v1/logout', () => empty())
  return api
}

const railNav = () => screen.getByRole('navigation', { name: 'Console' })
const drawer = () => document.querySelector<HTMLElement>('[data-drawer]')
/** The open drawer, or a failure that says so. */
const openDrawer = (): HTMLElement => {
  const element = drawer()
  if (element === null) throw new Error('No drawer is showing.')
  return element
}
const heading = (name: string) => screen.findByRole('heading', { level: 1, name })
const menuButton = () => screen.getByRole('button', { name: /account menu/i })

async function open(path: string, width: number, capabilities?: string[]) {
  installViewport(width)
  serve(capabilities)
  const view = renderApp(path)
  await screen.findByRole('button', { name: /account menu/i })
  // findBy resolves as the DOM appears, before passive effects (heading focus, subscriptions) have run.
  await act(() => Promise.resolve())
  return view
}

describe('the desktop rail', () => {
  it('has the badge, and a control for each section with an icon AND its label', async () => {
    await open('/', 1400)
    const rail = railNav()
    expect(within(rail).getByRole('img', { name: 'Flow Life Commons' })).toBeInTheDocument()
    for (const label of ['Overview', 'Admin']) {
      const control = within(rail).getByRole('link', { name: label })
      expect(control).toHaveTextContent(label) // visible words, never icon-only
      expect(control.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
    }
  })

  it('marks the current section, and only that one', async () => {
    await open('/admin/accounts', 1400)
    expect(within(railNav()).getByRole('link', { name: 'Admin' })).toHaveAttribute(
      'aria-current',
      'true',
    )
    expect(within(railNav()).getByRole('link', { name: 'Overview' })).not.toHaveAttribute(
      'aria-current',
    )
  })

  it('marks Overview as the current page on the landing page', async () => {
    await open('/', 1400)
    expect(within(railNav()).getByRole('link', { name: 'Overview' })).toHaveAttribute(
      'aria-current',
      'page',
    )
  })

  it('has no Account security in the navigation, and no section current on that page', async () => {
    await open('/account/security', 1400)
    expect(within(railNav()).queryByText(/security/i)).toBeNull()
    for (const link of within(railNav()).getAllByRole('link')) {
      expect(link).not.toHaveAttribute('aria-current')
    }
    expect(drawer()).toBeNull()
  })

  it('imposes no maximum width on the page, and does not centre it', async () => {
    await open('/', 1400)
    const main = screen.getByRole('main')
    const shell = main.closest('div.flex.min-h-dvh')
    for (const element of [main, shell, main.parentElement]) {
      expect(element?.className).not.toMatch(/\bmax-w-/)
      expect(element?.className).not.toMatch(/\bmx-auto\b/)
    }
  })

  it('names the drawer by its section, and takes only capable operators there', async () => {
    await open('/', 1400, ['console.access'])
    expect(within(railNav()).queryByRole('link', { name: 'Admin' })).toBeNull()
    expect(within(railNav()).getByRole('link', { name: 'Overview' })).toBeVisible()
  })
})

describe('the pinned drawer', () => {
  it('is the default on a wide window, in the layout, with the current page marked', async () => {
    await open('/admin/accounts', 1400)
    const panel = drawer()
    expect(panel).toHaveAttribute('data-drawer', 'pinned')
    expect(panel?.tagName).toBe('NAV')
    expect(panel).toHaveAccessibleName('Administration')
    expect(panel?.className).toContain('sticky')
    expect(panel?.className).not.toMatch(/\bfixed\b/) // it is a column, not a layer
    expect(within(openDrawer()).getByRole('link', { name: 'All accounts' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    expect(
      within(openDrawer()).getByRole('button', { name: 'Unpin navigation panel' }),
    ).toBeVisible()
  })

  it('shows the groups and only the pages the operator may use', async () => {
    await open('/admin/members', 1400, ['console.access', 'membership.records.view'])
    const panel = openDrawer()
    expect(within(panel).getByRole('link', { name: 'All members' })).toBeVisible()
    expect(within(panel).queryByRole('link', { name: 'Add a member' })).toBeNull()
    expect(within(panel).queryByText('Accounts')).toBeNull() // that whole group is gone
    expect(within(panel).getByText('Members')).toBeVisible()
  })

  it('is omitted, with no empty column, on a section with nothing beneath it', async () => {
    await open('/', 1400)
    expect(drawer()).toBeNull()
  })

  it('stays current on a detail page, and follows the section onto a form page', async () => {
    await open('/admin/accounts/01J0000000000000000000ACCT', 1400)
    expect(within(openDrawer()).getByRole('link', { name: 'All accounts' })).toHaveAttribute(
      'aria-current',
      'page',
    )
  })

  it('goes to the first page of a section from its rail control', async () => {
    await open('/', 1400)
    expect(within(railNav()).getByRole('link', { name: 'Admin' })).toHaveAttribute(
      'href',
      '/admin/accounts',
    )
  })

  it('stays in the tab order between the rail and the page', async () => {
    await open('/admin/accounts', 1400)
    expect(drawer()?.querySelector('[tabindex="-1"]')).toBeNull()
    const order = Array.from(document.querySelectorAll<HTMLElement>('nav, main')).map(
      (element) => element.getAttribute('aria-label') ?? element.tagName,
    )
    expect(order.indexOf('Console')).toBeLessThan(order.indexOf('Administration'))
    expect(order.indexOf('Administration')).toBeLessThan(order.indexOf('MAIN'))
  })
})

describe('the overlay drawer', () => {
  const adminToggle = () => within(railNav()).getByRole('button', { name: 'Admin' })

  it('is the default in the middle band: the rail control toggles it', async () => {
    await open('/', 1100)
    expect(drawer()).toBeNull()
    expect(adminToggle()).toHaveAttribute('aria-expanded', 'false')
    expect(adminToggle()).toHaveAttribute('aria-controls', 'navigation-drawer')
    expect(within(railNav()).getByRole('link', { name: 'Overview' })).toBeVisible()
  })

  it('opens non-modal over the content: no dialog, no scrim, on the raised surface', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())

    const panel = openDrawer()
    expect(panel).toHaveAttribute('data-drawer', 'overlay')
    expect(adminToggle()).toHaveAttribute('aria-expanded', 'true')
    expect(document.getElementById('navigation-drawer')).toBe(panel)
    expect(panel.className).toContain('bg-surface-raised')
    expect(panel.className).toContain('shadow-pop')
    expect(panel.className).toMatch(/\bfixed\b/)
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.querySelector('dialog')).toBeNull()
    expect(screen.getByRole('main')).not.toHaveAttribute('inert')
  })

  it('moves focus to the first item when it is not on a page of this section', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    expect(screen.getByRole('link', { name: 'All accounts' })).toHaveFocus()
  })

  it('moves focus to the current page when it is on one', async () => {
    const user = userEvent.setup()
    await open('/admin/accounts/invite', 1100)
    await user.click(adminToggle())
    const current = within(openDrawer()).getByRole('link', {
      name: 'Invite an operator',
    })
    expect(current).toHaveAttribute('aria-current', 'page')
    expect(current).toHaveFocus()
  })

  it('closes on Escape and returns focus to the rail control', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    await user.keyboard('{Escape}')

    expect(drawer()).toBeNull()
    expect(adminToggle()).toHaveFocus()
    expect(adminToggle()).toHaveAttribute('aria-expanded', 'false')
  })

  it('closes on a press outside it, leaving focus where the person put it', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    fireEvent.pointerDown(screen.getByRole('main'))

    await waitFor(() => {
      expect(drawer()).toBeNull()
    })
    expect(adminToggle()).not.toHaveFocus()
  })

  it('does not close when the press is inside it', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    fireEvent.pointerDown(within(openDrawer()).getByRole('heading'))
    expect(drawer()).not.toBeNull()
  })

  it('toggles closed from its own rail control', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    await user.click(adminToggle())
    expect(drawer()).toBeNull()
  })

  it('closes when a page is chosen, and the new page takes its heading focus', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    await user.click(screen.getByRole('link', { name: 'All members' }))

    expect(await heading('Members')).toHaveFocus()
    expect(drawer()).toBeNull()
  })

  it('closes when the page chosen is the one already showing, and focus returns to the rail', async () => {
    const user = userEvent.setup()
    await open('/admin/accounts', 1100)
    await heading('Accounts')
    await user.click(adminToggle())
    await user.click(within(openDrawer()).getByRole('link', { name: 'All accounts' }))
    expect(drawer()).toBeNull()
  })

  it('closes when the window narrows past the rail breakpoint', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    await user.click(adminToggle())
    resizeTo(800)
    expect(drawer()).toBeNull()
    resizeTo(1100)
    expect(drawer()).toBeNull() // and does not spring back open
  })

  it('never writes a preference by being opened, closed, or navigated with', async () => {
    const user = userEvent.setup()
    await open('/', 1100)
    const before = localStorage.getItem(STORAGE_KEY)
    const write = vi.spyOn(Storage.prototype, 'setItem')
    await user.click(adminToggle())
    await user.keyboard('{Escape}')
    await user.click(adminToggle())
    await user.click(screen.getByRole('link', { name: 'All members' }))
    await heading('Members')
    expect(write).not.toHaveBeenCalled()
    expect(localStorage.getItem(STORAGE_KEY)).toBe(before)
  })
})

describe('an explicit drawer choice', () => {
  it('pinned is honoured in the middle band, where the default is overlay', async () => {
    setNavPreference('pinned')
    await open('/admin/accounts', 1100)
    expect(drawer()).toHaveAttribute('data-drawer', 'pinned')
  })

  it('overlay is honoured on a wide window, where the default is pinned', async () => {
    setNavPreference('overlay')
    await open('/admin/accounts', 1400)
    expect(drawer()).toBeNull()
    expect(within(railNav()).getByRole('button', { name: 'Admin' })).toBeVisible()
  })

  it('is stored only by the pin controls: unpin stores overlay, pin stores pinned', async () => {
    const user = userEvent.setup()
    await open('/admin/accounts', 1400)
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{"v":1}')).not.toHaveProperty('nav')

    await user.click(screen.getByRole('button', { name: 'Unpin navigation panel' }))
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({
      nav: 'overlay',
    })
    expect(drawer()).toBeNull()

    await user.click(within(railNav()).getByRole('button', { name: 'Admin' }))
    await user.click(screen.getByRole('button', { name: 'Pin navigation panel' }))
    expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({ nav: 'pinned' })
    expect(drawer()).toHaveAttribute('data-drawer', 'pinned')
  })

  it('stays stored, unused and untouched, while the mobile sheet is showing', async () => {
    setNavPreference('pinned')
    const stored = localStorage.getItem(STORAGE_KEY)
    await open('/admin/accounts', 1400)
    expect(drawer()).toHaveAttribute('data-drawer', 'pinned')

    resizeTo(700)
    expect(drawer()).toBeNull()
    expect(screen.getByRole('button', { name: 'Navigation menu' })).toBeVisible()
    expect(localStorage.getItem(STORAGE_KEY)).toBe(stored)

    resizeTo(1400)
    expect(drawer()).toHaveAttribute('data-drawer', 'pinned')
    expect(localStorage.getItem(STORAGE_KEY)).toBe(stored)
  })
})

describe('the mobile navigation sheet', () => {
  const opener = () => screen.getByRole('button', { name: 'Navigation menu' })

  it('replaces the rail below the rail breakpoint, with the menu button and the brand', async () => {
    await open('/', 1023)
    expect(screen.queryByRole('navigation', { name: 'Console' })).toBeNull()
    expect(opener()).toHaveAttribute('aria-haspopup', 'dialog')
    expect(opener()).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByText('Flow Life Commons')).toBeInTheDocument()
    expect(screen.getByText('Guardian Console')).toBeInTheDocument()
  })

  it('is a rail exactly at the breakpoint, and the sheet exactly below it', async () => {
    await open('/', 1024)
    expect(railNav()).toBeVisible()
    resizeTo(1023)
    expect(screen.queryByRole('navigation', { name: 'Console' })).toBeNull()
    expect(opener()).toBeVisible()
    resizeTo(1024)
    expect(railNav()).toBeVisible()
  })

  it('opens as a native modal dialog on the scrim, named Navigation', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    await user.click(opener())

    const sheet = screen.getByRole('dialog', { name: 'Navigation' })
    expect(sheet.tagName).toBe('DIALOG')
    expect(sheet).toHaveAttribute('open')
    expect(sheet.className).toContain('backdrop:bg-scrim')
    expect(sheet.className).toContain('bg-surface-raised')
    expect(opener()).toHaveAttribute('aria-expanded', 'true')
  })

  it('is opened modally with showModal(), which is what makes the page behind it inert and traps focus', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    const showModal = vi.spyOn(HTMLDialogElement.prototype, 'showModal')
    await user.click(opener())
    expect(showModal).toHaveBeenCalledTimes(1)
    expect(showModal.mock.contexts[0]).toBe(screen.getByRole('dialog', { name: 'Navigation' }))
  })

  it('lists the full hierarchy with full labels', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    await user.click(opener())

    const sheet = screen.getByRole('dialog', { name: 'Navigation' })
    expect(within(sheet).getByRole('link', { name: 'Overview' })).toBeVisible()
    expect(within(sheet).getByRole('heading', { name: 'Administration' })).toBeVisible()
    expect(within(sheet).getByRole('heading', { name: 'Accounts' })).toBeVisible()
    expect(within(sheet).getByRole('heading', { name: 'Members' })).toBeVisible()
    for (const label of ['All accounts', 'Invite an operator', 'All members', 'Add a member']) {
      expect(within(sheet).getByRole('link', { name: label })).toBeVisible()
    }
    expect(within(sheet).queryByText(/security/i)).toBeNull()
  })

  it('shows only what the operator may use', async () => {
    const user = userEvent.setup()
    await open('/', 600, ['console.access', 'membership.records.view'])
    await user.click(opener())

    const sheet = screen.getByRole('dialog', { name: 'Navigation' })
    expect(within(sheet).getByRole('link', { name: 'All members' })).toBeVisible()
    for (const label of ['All accounts', 'Invite an operator', 'Add a member']) {
      expect(within(sheet).queryByRole('link', { name: label })).toBeNull()
    }
    expect(within(sheet).queryByRole('heading', { name: 'Accounts' })).toBeNull()
  })

  it('shows Overview alone, and no Administration, to an operator with no capability', async () => {
    const user = userEvent.setup()
    await open('/', 600, ['console.access'])
    await user.click(opener())
    const sheet = screen.getByRole('dialog', { name: 'Navigation' })
    expect(
      within(sheet)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Overview'])
    expect(within(sheet).queryByRole('heading', { name: 'Administration' })).toBeNull()
  })

  it('focuses the current page first, deterministically', async () => {
    const user = userEvent.setup()
    await open('/admin/members', 600)
    await user.click(opener())
    const sheet = screen.getByRole('dialog', { name: 'Navigation' })
    const current = within(sheet).getByRole('link', { name: 'All members' })
    expect(current).toHaveAttribute('aria-current', 'page')
    expect(current).toHaveFocus()
  })

  it('focuses the first link when the page is not in the navigation', async () => {
    const user = userEvent.setup()
    await open('/account/security', 600)
    await user.click(opener())
    expect(
      within(screen.getByRole('dialog', { name: 'Navigation' })).getByRole('link', {
        name: 'Overview',
      }),
    ).toHaveFocus()
  })

  it('closes on Escape and returns focus to the menu button', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    await user.click(opener())
    fireEvent(screen.getByRole('dialog'), new Event('cancel', { cancelable: true }))

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(opener()).toHaveFocus()
    expect(opener()).toHaveAttribute('aria-expanded', 'false')
  })

  it('closes from its own button, and from a press on the scrim', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    await user.click(opener())
    await user.click(screen.getByRole('button', { name: 'Close navigation' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(opener()).toHaveFocus()

    await user.click(opener())
    fireEvent.click(screen.getByRole('dialog')) // a press on the backdrop targets the dialog itself
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('closes on choosing a page, and the page heading takes focus rather than the menu button', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    await user.click(opener())
    await user.click(screen.getByRole('link', { name: 'All members' }))

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(await heading('Members')).toHaveFocus()
    expect(opener()).not.toHaveFocus()
  })

  it('returns focus to the menu button when the page chosen is the one already showing', async () => {
    const user = userEvent.setup()
    await open('/admin/members', 600)
    await heading('Members')
    await user.click(opener())
    await user.click(screen.getByRole('link', { name: 'All members' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(opener()).toHaveFocus()
  })

  it('closes when the window widens into the desktop shell, and does not spring back', async () => {
    const user = userEvent.setup()
    await open('/', 600)
    await user.click(opener())
    resizeTo(1400)
    expect(screen.queryByRole('dialog')).toBeNull()
    resizeTo(600)
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('shows the account menu as the avatar alone', async () => {
    await open('/', 600)
    const trigger = screen.getByRole('button', { name: 'Account menu' })
    expect(trigger).toHaveAttribute('aria-label', 'Account menu')
    expect(trigger).not.toHaveTextContent(operator().person.display_name)
  })
})

describe('the breadcrumbs', () => {
  it('are absent on the landing and list pages', async () => {
    await open('/admin/accounts', 1400)
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).toBeNull()
  })

  it('name the parent and the form page, from the navigation model', async () => {
    await open('/admin/accounts/invite', 1400)
    const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' })
    expect(within(crumbs).getByRole('link', { name: 'Accounts' })).toHaveAttribute(
      'href',
      '/admin/accounts',
    )
    expect(within(crumbs).getByText('Invite an operator')).toHaveAttribute('aria-current', 'page')
  })

  it('name a detail page by the person it loaded, and go up to the list with heading focus', async () => {
    const user = userEvent.setup()
    installViewport(1400)
    const api = serve()
    api.on('GET /api/v1/admin/roles', json({ data: [] }))
    api.on(`GET /api/v1/admin/accounts/${wire().id as string}`, () => json(wire()))
    renderApp(`/admin/accounts/${wire().id as string}`)

    const crumbs = await screen.findByRole('navigation', { name: 'Breadcrumb' })
    await waitFor(() => {
      expect(within(crumbs).getByText('Tara Target')).toHaveAttribute('aria-current', 'page')
    })
    await user.click(within(crumbs).getByRole('link', { name: 'Accounts' }))
    expect(await heading('Accounts')).toHaveFocus()
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).toBeNull()
  })
})

describe('focus after navigation, from every origin', () => {
  it('rail: a direct section', async () => {
    const user = userEvent.setup()
    await open('/account/security', 1400)
    await user.click(within(railNav()).getByRole('link', { name: 'Overview' }))
    expect(await heading('Flow Life Guardian Console')).toHaveFocus()
  })

  it('pinned drawer', async () => {
    const user = userEvent.setup()
    await open('/admin/accounts', 1400)
    await user.click(within(openDrawer()).getByRole('link', { name: 'All members' }))
    expect(await heading('Members')).toHaveFocus()
  })

  it('account menu: Account security', async () => {
    const user = userEvent.setup()
    await open('/', 1400)
    await user.click(menuButton())
    await user.click(screen.getByRole('menuitem', { name: 'Account security' }))
    expect(await heading('Account security')).toHaveFocus()
    expect(screen.queryByRole('menu')).toBeNull()
  })
})

describe('accessibility of the shell', () => {
  it.each(['light', 'dark'] as const)(
    'has no axe violations on the wide shell in %s',
    async (theme) => {
      document.documentElement.dataset.theme = theme
      await open('/admin/accounts', 1400)
      await heading('Accounts')
      await expectNoAxeViolations()
    },
  )

  it.each(['light', 'dark'] as const)(
    'has no axe violations with the account menu open in %s',
    async (theme) => {
      const user = userEvent.setup()
      document.documentElement.dataset.theme = theme
      await open('/', 1400)
      await user.click(menuButton())
      await expectNoAxeViolations()
    },
  )

  it.each(['light', 'dark'] as const)(
    'has no axe violations with the overlay drawer open in %s',
    async (theme) => {
      const user = userEvent.setup()
      document.documentElement.dataset.theme = theme
      await open('/admin/accounts', 1100)
      await user.click(within(railNav()).getByRole('button', { name: 'Admin' }))
      await expectNoAxeViolations()
    },
  )

  it.each(['light', 'dark'] as const)(
    'has no axe violations on the mobile shell and its open sheet in %s',
    async (theme) => {
      const user = userEvent.setup()
      document.documentElement.dataset.theme = theme
      await open('/admin/accounts', 600)
      await expectNoAxeViolations()
      await user.click(screen.getByRole('button', { name: 'Navigation menu' }))
      await expectNoAxeViolations()
    },
  )

  it('has a skip link to the main content that moves focus there', async () => {
    const user = userEvent.setup()
    await open('/', 1400)
    await user.click(screen.getByRole('link', { name: 'Skip to main content' }))
    expect(screen.getByRole('main')).toHaveFocus()
  })
})
