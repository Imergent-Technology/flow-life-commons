import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// Vitest globals are off (explicit imports), so RTL's auto-cleanup is wired by hand.
afterEach(() => {
  cleanup()
})
