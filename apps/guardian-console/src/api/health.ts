// Temporary hand-written API call that proves the environment end to end.
// Once the API justifies it, the Guardian Console consumes a TypeScript client
// generated from apps/platform/openapi/openapi.yaml (packages/api-client, ADR 0007)
// instead of accumulating calls like this one.

export interface HealthResponse {
  status: 'ok' | 'degraded'
  service: string
  api_version: string
  checks: Record<string, 'ok' | 'fail'>
}

function isHealthResponse(value: unknown): value is HealthResponse {
  if (typeof value !== 'object' || value === null) return false
  const v = value as Record<string, unknown>
  return (
    (v.status === 'ok' || v.status === 'degraded') &&
    typeof v.service === 'string' &&
    typeof v.api_version === 'string' &&
    typeof v.checks === 'object' &&
    v.checks !== null
  )
}

/**
 * Fetches GET /api/v1/health. A 503 still carries a valid "degraded" body and is
 * returned as such; anything else (network failure, malformed body) throws.
 */
export async function fetchHealth(signal?: AbortSignal): Promise<HealthResponse> {
  const init: RequestInit = { headers: { Accept: 'application/json' } }
  if (signal) init.signal = signal

  const response = await fetch(`${import.meta.env.VITE_API_BASE_URL}/api/v1/health`, init)

  const body: unknown = await response.json()
  if (!isHealthResponse(body)) {
    throw new Error(`Unexpected health response (HTTP ${String(response.status)})`)
  }
  return body
}
