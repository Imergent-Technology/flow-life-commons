import { describe, expect, it } from 'vitest'

import { accountFor } from '../test/fakeApi.ts'
import { returnPathFrom, safeReturnPath } from './returnPath.ts'

const guardian = accountFor({ capabilities: ['console.access'] })
const member = accountFor({ capabilities: [] })

describe('safeReturnPath', () => {
  it.each([
    ['/', '/'],
    ['/account/security', '/account/security'],
    ['/some/console/route?tab=2', '/some/console/route?tab=2'],
    ['/account/security#frag', '/account/security'], // a fragment is never carried
  ])('follows the internal path %s', (input, expected) => {
    expect(safeReturnPath(input)).toBe(expected)
  })

  it.each([
    'https://evil.example/',
    'http://evil.example',
    '//evil.example',
    '///evil.example',
    '/\\evil.example',
    '\\\\evil.example',
    'javascript:alert(1)',
    'evil.example',
    '',
    '/ok\nX-Injected: 1',
    '/with space',
    '/\u0000',
    '/%2F%2Fevil.example/../../', // stays a path on this origin, checked below
  ])('never follows %j off the Console', (input) => {
    const result = safeReturnPath(input)
    expect(result.startsWith('/')).toBe(true)
    expect(result.startsWith('//')).toBe(false)
    expect(new URL(result, 'http://console.invalid').origin).toBe('http://console.invalid')
  })

  it.each([
    '/api/v1/me',
    '/api',
    '/up',
    '/login',
    '/reset-password',
    '/accept-invitation',
    '/forgot-password',
  ])('refuses %s: not a Console page, or a place a secret arrives', (input) => {
    expect(safeReturnPath(input)).toBe('/')
  })

  it('refuses anything that is not a string, and absurd lengths', () => {
    for (const value of [undefined, null, 42, {}, ['/x']]) expect(safeReturnPath(value)).toBe('/')
    expect(safeReturnPath(`/${'a'.repeat(3000)}`)).toBe('/')
  })
})

describe('returnPathFrom', () => {
  it('honors any safe internal path for console.access, unrestricted', () => {
    expect(returnPathFrom({ from: '/some/console/route' }, guardian)).toBe('/some/console/route')
    expect(returnPathFrom({ from: '/account/security' }, guardian)).toBe('/account/security')
    expect(returnPathFrom({ from: '/admin/accounts' }, guardian)).toBe('/admin/accounts')
    // Guardians may also use the Member surface as a return path: holding console.access refuses it nothing.
    expect(returnPathFrom({ from: '/my/membership' }, guardian)).toBe('/my/membership')
  })

  it('honors /my and /my/... for an Account without console.access: its own surface, in full', () => {
    for (const from of ['/my', '/my/', '/my/membership', '/my/security']) {
      expect(returnPathFrom({ from }, member)).toBe(from)
    }
  })

  it('falls back to this Account’s own default surface with no explicit request', () => {
    expect(returnPathFrom(null, guardian)).toBe('/')
    expect(returnPathFrom({}, guardian)).toBe('/')
    expect(returnPathFrom('/account/security', guardian)).toBe('/') // not an object with `from`: ignored
    expect(returnPathFrom(null, member)).toBe('/my')
    expect(returnPathFrom({}, member)).toBe('/my')
  })

  it('falls back to the default surface when the requested path was rejected outright', () => {
    expect(returnPathFrom({ from: 'https://evil.example' }, guardian)).toBe('/')
    expect(returnPathFrom({ from: 'https://evil.example' }, member)).toBe('/my')
  })

  it(
    'falls back to /my for an Account without console.access: an ALLOW-list of its own surface, not a ' +
      'deny-list of Guardian routes — so it need not name every Console route, present or future',
    () => {
      for (const from of [
        '/account/security',
        '/admin',
        '/admin/accounts',
        '/admin/accounts/01J0',
        '/admin?x=1',
        '/ADMIN/accounts', // React Router matches case-insensitively; this module must not assume otherwise
        '/administrivia', // a merely similar path is not Guardian-only, but it is also not /my: same fallback
        '/people', // no route exists yet, and this module must not need to know that
        '/discussions',
        '/some-future-console-route',
      ]) {
        expect(returnPathFrom({ from }, member)).toBe('/my')
      }
    },
  )
})
