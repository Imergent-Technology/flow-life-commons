import { createContext, useContext, useEffect } from 'react'

/** Lets a detail page tell the breadcrumbs the name it has loaded ("Ada Lovelace"), instead of a second request. */
export const BreadcrumbLeafContext = createContext<((label: string | undefined) => void) | null>(
  null,
)

/** Call with the loaded name, or `undefined` while there is none. It is cleared when the page goes away. */
export function useBreadcrumbLeaf(label: string | undefined): void {
  const setLeaf = useContext(BreadcrumbLeafContext)
  useEffect(() => {
    setLeaf?.(label)
    return () => {
      setLeaf?.(undefined)
    }
  }, [setLeaf, label])
}
