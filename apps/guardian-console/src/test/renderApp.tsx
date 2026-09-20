import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

import App from '../App.tsx'
import { LocationProbe } from './LocationProbe.tsx'

export function renderApp(initialEntry = '/') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <App />
      <LocationProbe />
    </MemoryRouter>,
  )
}
