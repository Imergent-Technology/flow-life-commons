/** A promise settled from outside, so a test can hold a server answer back and look at the page in the meantime. */
export function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve: (value: T) => void = () => undefined
  const promise = new Promise<T>((settle) => {
    resolve = settle
  })
  return { promise, resolve }
}

/** The item at `index`, or a failure that says so (the repository allows neither a cast nor a non-null assertion). */
export function nth<T>(list: readonly T[], index: number): T {
  const item = list[index]
  if (item === undefined) throw new Error(`There is no item ${String(index)}.`)
  return item
}

/** The page's own content column (not the navigation around it), so a query can say "on the page". */
export function pageBody(): HTMLElement {
  const page = document.querySelector<HTMLElement>('[data-page-width]')
  if (page === null) throw new Error('There is no page on screen.')
  return page
}
