import { useLocation } from 'react-router'

/** Shows where the router is, so a test can assert a redirect without reaching into the router. */
export function LocationProbe() {
  const { pathname, search, hash } = useLocation()
  return <span data-testid="location">{`${pathname}${search}${hash}`}</span>
}
