import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import App from './App.tsx'
import { fetchHealth } from './api/health.ts'

vi.mock('./api/health.ts')

const fetchHealthMock = vi.mocked(fetchHealth)

describe('App', () => {
  beforeEach(() => {
    fetchHealthMock.mockReset()
  })

  it('renders the development shell', () => {
    fetchHealthMock.mockReturnValue(new Promise(() => undefined))
    render(<App />)

    expect(
      screen.getByRole('heading', { level: 1, name: 'Flow Life Guardian Console' }),
    ).toBeVisible()
    expect(screen.getByText('Checking…')).toBeVisible()
  })

  it('shows the API as ok with its checks', async () => {
    fetchHealthMock.mockResolvedValue({
      status: 'ok',
      service: 'flowlife-platform',
      api_version: 'v1',
      checks: { database: 'ok' },
    })
    render(<App />)

    expect(await screen.findByText('API ok')).toBeVisible()
    expect(screen.getByText('database: ok')).toBeVisible()
  })

  it('shows a degraded API distinctly', async () => {
    fetchHealthMock.mockResolvedValue({
      status: 'degraded',
      service: 'flowlife-platform',
      api_version: 'v1',
      checks: { database: 'fail' },
    })
    render(<App />)

    expect(await screen.findByText('API degraded')).toBeVisible()
    expect(screen.getByText('database: fail')).toBeVisible()
  })

  it('shows when the API cannot be reached', async () => {
    fetchHealthMock.mockRejectedValue(new TypeError('Failed to fetch'))
    render(<App />)

    expect(await screen.findByText('API unreachable')).toBeVisible()
  })
})
