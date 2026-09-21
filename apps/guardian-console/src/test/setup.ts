import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

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
