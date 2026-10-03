import { matchPath } from 'react-router'

import {
  ACCOUNTS_VIEW,
  DISCUSSIONS_PARTICIPATE,
  DISCUSSIONS_VIEW,
  INVITATIONS_ISSUE,
  MEMBERSHIP_MANAGE,
  MEMBERSHIP_VIEW,
  PEOPLE_MANAGE,
  PEOPLE_VIEW,
} from '../auth/capabilities.ts'

/**
 * The one definition of where an operator can go. The rail, the secondary drawer, the mobile sheet and the
 * breadcrumbs are all derived from it, so they cannot drift apart. What is SHOWN follows the operator's
 * capabilities (a presentation courtesy: the server decides every request, and RequireCapability still guards
 * each route). Account security is not here on purpose: it belongs to the person, and lives in the account menu.
 */
export type IconName = 'home' | 'people' | 'contacts' | 'discussions'

export interface NavItem {
  label: string
  /** The item's own page. It is current on an exact match only, so `/admin/accounts/invite` is not "All accounts". */
  to: string
  capability?: string
  /** A page beneath this item (`/admin/accounts/:id`): the item stays current, and the page gets a breadcrumb. */
  detail?: { pattern: string; label: string }
}

export interface NavGroup {
  label: string
  items: NavItem[]
}

export interface NavSection {
  id: string
  /** The short label under the rail icon. */
  label: string
  /** The drawer's own title, where it differs. */
  drawerTitle?: string
  icon: IconName
  /** A section that is itself a destination, with nothing beneath it. */
  to?: string
  groups?: NavGroup[]
}

export const navigation: readonly NavSection[] = [
  { id: 'overview', label: 'Overview', icon: 'home', to: '/' },
  {
    id: 'people',
    label: 'People',
    icon: 'contacts',
    groups: [
      {
        label: 'People',
        items: [
          {
            label: 'All people',
            to: '/people',
            capability: PEOPLE_VIEW,
            detail: { pattern: '/people/:personId', label: 'Person' },
          },
          { label: 'Add a person', to: '/people/new', capability: PEOPLE_MANAGE },
          { label: 'Tags', to: '/people/tags', capability: PEOPLE_VIEW },
        ],
      },
    ],
  },
  {
    id: 'discussions',
    label: 'Discussions',
    icon: 'discussions',
    groups: [
      {
        label: 'Discussions',
        items: [
          {
            label: 'All discussions',
            to: '/discussions',
            capability: DISCUSSIONS_VIEW,
            detail: { pattern: '/discussions/:discussionId', label: 'Discussion' },
          },
          {
            label: 'Start a discussion',
            to: '/discussions/new',
            capability: DISCUSSIONS_PARTICIPATE,
          },
        ],
      },
    ],
  },
  {
    id: 'admin',
    label: 'Admin',
    drawerTitle: 'Administration',
    icon: 'people',
    groups: [
      {
        label: 'Accounts',
        items: [
          {
            label: 'All accounts',
            to: '/admin/accounts',
            capability: ACCOUNTS_VIEW,
            detail: { pattern: '/admin/accounts/:id', label: 'Account' },
          },
          {
            label: 'Invite an operator',
            to: '/admin/accounts/invite',
            capability: INVITATIONS_ISSUE,
          },
        ],
      },
      {
        label: 'Members',
        items: [
          {
            label: 'All members',
            to: '/admin/members',
            capability: MEMBERSHIP_VIEW,
            detail: { pattern: '/admin/members/:personId', label: 'Member' },
          },
          { label: 'Add a member', to: '/admin/members/new', capability: MEMBERSHIP_MANAGE },
        ],
      },
    ],
  },
]

/**
 * What the operator may see. An item needs its capability; a group with no visible items is dropped, and so is a
 * section with no visible groups. One capability never implies another (manage does not imply view).
 */
export function visibleNavigation(
  sections: readonly NavSection[],
  can: (capability: string) => boolean,
): NavSection[] {
  const visible: NavSection[] = []
  for (const section of sections) {
    if (section.groups === undefined) {
      visible.push(section)
      continue
    }
    const groups = section.groups
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => item.capability === undefined || can(item.capability)),
      }))
      .filter((group) => group.items.length > 0)
    if (groups.length > 0) visible.push({ ...section, groups })
  }
  return visible
}

export interface Location {
  section: NavSection
  group?: NavGroup
  item?: NavItem
  /** On a page beneath the item rather than the item's own page. */
  detail: boolean
}

/**
 * Where a path sits in the navigation. Exact pages win over detail patterns, because `/admin/accounts/:id` would
 * otherwise claim `/admin/accounts/invite`. Uses the router's own matcher, not string prefixes.
 */
export function locate(sections: readonly NavSection[], pathname: string): Location | null {
  for (const section of sections) {
    if (section.to !== undefined && matchPath({ path: section.to, end: true }, pathname)) {
      return { section, detail: false }
    }
    for (const group of section.groups ?? []) {
      for (const item of group.items) {
        if (matchPath({ path: item.to, end: true }, pathname)) {
          return { section, group, item, detail: false }
        }
      }
    }
  }
  for (const section of sections) {
    for (const group of section.groups ?? []) {
      for (const item of group.items) {
        if (
          item.detail !== undefined &&
          matchPath({ path: item.detail.pattern, end: true }, pathname)
        ) {
          return { section, group, item, detail: true }
        }
      }
    }
  }
  return null
}

/**
 * How a drawer or sheet item says it is current. On the item's own page it is THE current page. On a page beneath
 * it (an account's own page under "All accounts") the item is only where you are within the site, so it says `true`:
 * the breadcrumb's last crumb is the one current page, and a page has one.
 */
export function currentMarker(
  location: Location | null,
  item: NavItem,
): 'page' | 'true' | undefined {
  if (location?.item !== item) return undefined
  return location.detail ? 'true' : 'page'
}

export interface Crumb {
  label: string
  /** Absent on the last crumb: the page itself. */
  to?: string
}

/**
 * The trail for a detail or form page ("Accounts / Ada Lovelace"). A group's first item is its list page and gets
 * none: a breadcrumb on a root page says nothing. `leaf` is the name the detail page has loaded, if any.
 */
export function breadcrumbs(location: Location | null, leaf?: string): Crumb[] {
  if (location?.group === undefined || location.item === undefined) return []
  const landing = location.group.items[0]
  if (landing === undefined) return []
  if (!location.detail && location.item === landing) return []
  const last = location.detail
    ? (leaf ?? location.item.detail?.label ?? location.item.label)
    : location.item.label
  return [{ label: location.group.label, to: landing.to }, { label: last }]
}

/** The first page a section leads to: where its rail control goes when the drawer is pinned. */
export function firstDestination(section: NavSection): string | undefined {
  return section.to ?? section.groups?.[0]?.items[0]?.to
}
