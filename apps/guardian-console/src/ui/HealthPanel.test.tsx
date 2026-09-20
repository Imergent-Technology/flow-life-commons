import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { fetchHealth } from '../api/health.ts'
import { HealthPanel } from './HealthPanel.tsx'

vi.mock('../api/health.ts')

const fetchHealthMock = vi.mocked(fetchHealth)

describe('HealthPanel', () => {
  beforeEach(() => {
    fetchHealthMock.mockReset()
  })

  it('shows that it is checking', () => {
    fetchHealthMock.mockReturnValue(new Promise(() => undefined))
    render(<HealthPanel />)

    expect(screen.getByRole('heading', { level: 2, name: 'Platform API' })).toBeVisible()
    expect(screen.getByText('Checking…')).toBeVisible()
  })

  it('shows the API as ok with its checks', async () => {
    fetchHealthMock.mockResolvedValue({
      status: 'ok',
      service: 'flowlife-platform',
      api_version: 'v1',
      checks: { database: 'ok' },
    })
    render(<HealthPanel />)

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
    render(<HealthPanel />)

    expect(await screen.findByText('API degraded')).toBeVisible()
    expect(screen.getByText('database: fail')).toBeVisible()
  })

  it('shows when the API cannot be reached', async () => {
    fetchHealthMock.mockRejectedValue(new TypeError('Failed to fetch'))
    render(<HealthPanel />)

    expect(await screen.findByText('API unreachable')).toBeVisible()
  })
})
