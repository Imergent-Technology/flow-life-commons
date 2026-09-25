import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router'

import App from './App.tsx'
import './index.css'
import { applyResolvedTheme, readInitialTheme } from './ui/preferences.ts'

// Stamped synchronously, before React produces its first paint, so a System visitor whose OS is dark
// (or an operator who has explicitly chosen a theme) never sees the Garden default flash before the
// right one applies (design spec S9). No inline script: this module IS the one external
// <script type="module"> the production CSP already allows (ADR 0026), so nothing here needs an
// exception. The CSS prefers-color-scheme block is what covers the instant before even this has run.
applyResolvedTheme(readInitialTheme().resolved)

const root = document.getElementById('root')
if (!root) {
  throw new Error('Missing #root element')
}

createRoot(root).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
)
