import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

import App from '../App.tsx'
import { LocationProbe } from './LocationProbe.tsx'

/** Renders the whole Console at an address, with the navigation state a link would have carried, if a test needs one. */
export function renderApp(initialEntry: string | { pathname: string; state: unknown } = '/') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <App />
      <LocationProbe />
    </MemoryRouter>,
  )
}
