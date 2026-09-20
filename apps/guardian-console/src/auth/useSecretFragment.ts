import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'

/**
 * Reads secrets a link carries in its URL FRAGMENT (`/reset-password#token=...&email=...`), keeps them
 * in memory, and removes them from the visible URL straight away.
 *
 * A fragment is never sent to a server, so it stays out of access logs and `Referer` headers; but it
 * is still visible in the address bar and lands in browser history, so it is scrubbed as soon as it has
 * been captured. The scrub REPLACES the current history entry (the router's `replace`), so no
 * additional entry holding the secret is created and going back cannot bring it up again.
 *
 * The values live only in this hook's state: not in a query string, not in browser storage, not in a
 * log. Reloading the page therefore loses them, deliberately: the page then says the link is unusable.
 */
export function useSecretFragment<K extends string>(
  keys: readonly K[],
): Partial<Record<K, string>> {
  const location = useLocation()
  const navigate = useNavigate()

  const [captured, setCaptured] = useState(() => capture(location.hash, keys))
  const [seenHash, setSeenHash] = useState(location.hash)
  // Adjusting state while rendering (React's documented pattern): a NEW fragment arriving while this
  // page is already open (the same tab following another link) replaces what was captured.
  if (location.hash !== seenHash) {
    setSeenHash(location.hash)
    if (location.hash !== '') setCaptured(capture(location.hash, keys))
  }

  useEffect(() => {
    if (location.hash !== '') {
      void navigate({ pathname: location.pathname, search: location.search }, { replace: true })
    }
  }, [location.hash, location.pathname, location.search, navigate])

  return captured
}

function capture<K extends string>(hash: string, keys: readonly K[]): Partial<Record<K, string>> {
  const params = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash)
  const found: Partial<Record<K, string>> = {}
  for (const key of keys) {
    const value = params.get(key)
    if (value !== null && value !== '') found[key] = value
  }
  return found
}
