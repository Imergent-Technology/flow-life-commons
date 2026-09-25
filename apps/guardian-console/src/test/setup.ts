import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach } from 'vitest'

// The same allowance for `findBy*`/`waitFor` (default 1s): see testTimeout in vite.config.ts.
configure({ asyncUtilTimeout: 4000 })

// Vitest globals are off (explicit imports), so RTL's auto-cleanup is wired by hand.
afterEach(() => {
  cleanup()
})

// jsdom does not implement the modal <dialog> methods. This stands in for the two the Console uses: open and close. It does
// not emulate focus trapping or inertness (real browsers do that; the Playwright journeys prove it).
if (typeof HTMLDialogElement.prototype.showModal !== 'function') {
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  }
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
    this.dispatchEvent(new Event('close'))
  }
}
