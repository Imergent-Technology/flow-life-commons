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
  it('honors a safe internal path this Account can actually use', () => {
    expect(returnPathFrom({ from: '/some/console/route' }, guardian)).toBe('/some/console/route')
    expect(returnPathFrom({ from: '/my/membership' }, member)).toBe('/my/membership')
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

  it('never sends an Account without console.access to a Guardian-only path: it falls back instead', () => {
    expect(returnPathFrom({ from: '/account/security' }, member)).toBe('/my')
    expect(returnPathFrom({ from: '/admin/accounts' }, member)).toBe('/my')
    expect(returnPathFrom({ from: '/admin/accounts/01J0' }, member)).toBe('/my')
    // A Guardian, who CAN use them, still gets them honored.
    expect(returnPathFrom({ from: '/account/security' }, guardian)).toBe('/account/security')
    expect(returnPathFrom({ from: '/admin/accounts' }, guardian)).toBe('/admin/accounts')
  })

  it('does not treat a merely similar path as Guardian-only: only the exact prefix counts', () => {
    expect(returnPathFrom({ from: '/administrivia' }, member)).toBe('/administrivia')
  })
})
