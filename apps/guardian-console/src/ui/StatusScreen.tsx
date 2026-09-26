import type { ReactNode } from 'react'

/** A full-page, announced message: used while something must resolve before a page can be shown. */
export function StatusScreen({ children }: { children: ReactNode }) {
  return (
    <main className="mx-auto flex min-h-dvh max-w-md items-center justify-center p-6">
      <p role="status" className="text-body text-muted-foreground">
        {children}
      </p>
    </main>
  )
}
