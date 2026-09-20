import { describe, expect, it } from 'vitest'

import { returnPathFrom, safeReturnPath } from './returnPath.ts'

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
  it('reads the validated path out of router state and defaults to home', () => {
    expect(returnPathFrom({ from: '/account/security' })).toBe('/account/security')
    expect(returnPathFrom({ from: 'https://evil.example' })).toBe('/')
    expect(returnPathFrom(null)).toBe('/')
    expect(returnPathFrom({})).toBe('/')
    expect(returnPathFrom('/account/security')).toBe('/')
  })
})
