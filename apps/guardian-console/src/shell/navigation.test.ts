import { describe, expect, it } from 'vitest'

import {
  ACCOUNTS_VIEW,
  INVITATIONS_ISSUE,
  MEMBERSHIP_MANAGE,
  MEMBERSHIP_VIEW,
  PEOPLE_MANAGE,
  PEOPLE_VIEW,
} from '../auth/capabilities.ts'
import {
  breadcrumbs,
  currentMarker,
  firstDestination,
  locate,
  navigation,
  visibleNavigation,
  type NavSection,
} from './navigation.ts'

const everything = [
  ACCOUNTS_VIEW,
  INVITATIONS_ISSUE,
  MEMBERSHIP_VIEW,
  MEMBERSHIP_MANAGE,
  PEOPLE_VIEW,
  PEOPLE_MANAGE,
]
const having = (...held: string[]) => visibleNavigation(navigation, (c) => held.includes(c))
const labels = (sections: NavSection[]) =>
  sections.flatMap((s) => [
    s.label,
    ...(s.groups ?? []).flatMap((g) => g.items.map((i) => i.label)),
  ])

describe('visibleNavigation', () => {
  it('shows every page to someone who holds every capability', () => {
    expect(labels(having(...everything))).toEqual([
      'Overview',
      'People',
      'All people',
      'Add a person',
      'Tags',
      'Admin',
      'All accounts',
      'Invite an operator',
      'All members',
      'Add a member',
    ])
  })

  it('shows Overview alone to someone with no capability: no Admin section at all', () => {
    expect(labels(having())).toEqual(['Overview'])
  })

  it('filters individual items', () => {
    expect(labels(having(ACCOUNTS_VIEW))).toEqual(['Overview', 'Admin', 'All accounts'])
    expect(labels(having(INVITATIONS_ISSUE))).toEqual(['Overview', 'Admin', 'Invite an operator'])
  })

  it('drops a group with no visible items, and keeps the others', () => {
    const sections = having(MEMBERSHIP_VIEW)
    const admin = sections.find((s) => s.id === 'admin')
    expect(admin?.groups?.map((g) => g.label)).toEqual(['Members'])
  })

  it('shows the People section by its own capabilities, independent of Admin', () => {
    expect(labels(having(PEOPLE_VIEW))).toEqual(['Overview', 'People', 'All people', 'Tags'])
    expect(labels(having(ACCOUNTS_VIEW))).not.toContain('People')
  })

  it('never infers People view from People manage', () => {
    const sections = having(PEOPLE_MANAGE)
    expect(labels(sections)).toEqual(['Overview', 'People', 'Add a person'])
    expect(labels(sections)).not.toContain('All people')
  })

  it('never infers view from manage', () => {
    const sections = having(MEMBERSHIP_MANAGE)
    expect(labels(sections)).toEqual(['Overview', 'Admin', 'Add a member'])
    expect(labels(sections)).not.toContain('All members')
  })

  it('does not change the definition it filters', () => {
    having()
    expect(labels([...navigation])).toContain('All accounts')
  })

  it('has no Account security in it: that belongs to the account menu', () => {
    const all = JSON.stringify(navigation).toLowerCase()
    expect(all).not.toContain('security')
    expect(all).not.toContain('/account/')
  })
})

describe('locate', () => {
  const sections = having(...everything)
  const at = (pathname: string) => {
    const found = locate(sections, pathname)
    return found === null
      ? null
      : { section: found.section.id, item: found.item?.label, detail: found.detail }
  }

  it.each([
    ['/', { section: 'overview', item: undefined, detail: false }],
    ['/people', { section: 'people', item: 'All people', detail: false }],
    ['/people/new', { section: 'people', item: 'Add a person', detail: false }],
    ['/people/tags', { section: 'people', item: 'Tags', detail: false }],
    ['/people/01J000', { section: 'people', item: 'All people', detail: true }],
    ['/admin/accounts', { section: 'admin', item: 'All accounts', detail: false }],
    ['/admin/accounts/invite', { section: 'admin', item: 'Invite an operator', detail: false }],
    ['/admin/accounts/01J000', { section: 'admin', item: 'All accounts', detail: true }],
    ['/admin/members', { section: 'admin', item: 'All members', detail: false }],
    ['/admin/members/new', { section: 'admin', item: 'Add a member', detail: false }],
    ['/admin/members/01J000', { section: 'admin', item: 'All members', detail: true }],
  ])('%s', (pathname, expected) => {
    expect(at(pathname)).toEqual(expected)
  })

  it('finds nothing for a page that is not in the navigation', () => {
    expect(at('/account/security')).toBeNull()
    expect(at('/nowhere')).toBeNull()
    expect(at('/admin/accounts/01J000/extra')).toBeNull()
  })

  it('does not mark an item current for a path it merely starts with', () => {
    expect(at('/admin')).toBeNull()
    expect(at('/admin/accountsX')).toBeNull()
  })

  it('finds nothing where the operator may not go', () => {
    expect(locate(having(), '/admin/accounts')).toBeNull()
  })
})

describe('breadcrumbs', () => {
  const sections = having(...everything)
  const trail = (pathname: string, leaf?: string) =>
    breadcrumbs(locate(sections, pathname), leaf).map((c) => [c.label, c.to])

  it('has none on the root, list and unrelated pages', () => {
    expect(trail('/')).toEqual([])
    expect(trail('/admin/accounts')).toEqual([])
    expect(trail('/admin/members')).toEqual([])
    expect(trail('/account/security')).toEqual([])
  })

  it('names the parent and the form page', () => {
    expect(trail('/admin/accounts/invite')).toEqual([
      ['Accounts', '/admin/accounts'],
      ['Invite an operator', undefined],
    ])
    expect(trail('/admin/members/new')).toEqual([
      ['Members', '/admin/members'],
      ['Add a member', undefined],
    ])
  })

  it('names a detail page by what it has loaded, and by a plain word until then', () => {
    expect(trail('/admin/accounts/01J000', 'Ada Lovelace')).toEqual([
      ['Accounts', '/admin/accounts'],
      ['Ada Lovelace', undefined],
    ])
    expect(trail('/admin/accounts/01J000')).toEqual([
      ['Accounts', '/admin/accounts'],
      ['Account', undefined],
    ])
    expect(trail('/admin/members/01J000', 'Hēnare Tāne')[1]).toEqual(['Hēnare Tāne', undefined])
  })

  it('treats the first page the operator may use as the landing, so a form-only group gets no trail', () => {
    const onlyInvite = having(INVITATIONS_ISSUE)
    expect(breadcrumbs(locate(onlyInvite, '/admin/accounts/invite'))).toEqual([])
  })
})

describe('firstDestination', () => {
  it('leads to the section itself, or to the first page beneath it', () => {
    const [overview, people, admin] = having(...everything).map(firstDestination)
    expect(overview).toBe('/')
    expect(people).toBe('/people')
    expect(admin).toBe('/admin/accounts')
  })

  it('leads to the first page the operator may see', () => {
    expect(having(MEMBERSHIP_VIEW).map(firstDestination)).toEqual(['/', '/admin/members'])
  })
})

describe('currentMarker', () => {
  const admin = having(...everything).find((s) => s.id === 'admin')
  const accounts = admin?.groups?.[0]?.items[0]
  const invite = admin?.groups?.[0]?.items[1]
  const at = (pathname: string) => locate(having(...everything), pathname)

  it('marks an item as the current PAGE on its own page, list or form', () => {
    expect(accounts && currentMarker(at('/admin/accounts'), accounts)).toBe('page')
    expect(invite && currentMarker(at('/admin/accounts/invite'), invite)).toBe('page')
  })

  it('marks an item as current but NOT the page on a page beneath it, so a page has one current page', () => {
    expect(accounts && currentMarker(at('/admin/accounts/01J000'), accounts)).toBe('true')
    expect(accounts && currentMarker(at('/admin/accounts/invite'), accounts)).toBeUndefined() // exact pages win
  })

  it('marks nothing elsewhere, or where the page is not in the navigation', () => {
    expect(invite && currentMarker(at('/admin/accounts'), invite)).toBeUndefined()
    expect(accounts && currentMarker(at('/account/security'), accounts)).toBeUndefined()
    expect(accounts && currentMarker(null, accounts)).toBeUndefined()
  })
})
