import type { ReactNode } from 'react'

import { Badge, Wordmark } from '../shell/Brand.tsx'
import { usePageHeading } from './usePageHeading.ts'

/**
 * The frame for every page a person sees outside the signed-in shell: sign-in and its second step,
 * invitation, password reset, and the full-page statuses (access denied, service unavailable). A centred
 * 400px column under the window's wash and horizon line: the badge and wordmark, the page's one h1 in the
 * display face, and then everything the page has to say or ask inside one surface.
 *
 * The h1 names the document and takes focus on arrival, as `PageHeader` does inside the shell.
 */
export function AuthLayout({
  title,
  intro,
  children,
}: {
  title: string
  intro?: ReactNode
  children: ReactNode
}) {
  const ref = usePageHeading(title)

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-[25rem] flex-col justify-center gap-6 px-4 py-10">
      <header className="flex flex-col items-center gap-3 text-center">
        <Badge className="size-24 sm:size-(--logo-auth)" />
        <Wordmark />
        <h1
          ref={ref}
          tabIndex={-1}
          className="mt-2 font-display text-title font-medium text-foreground outline-none"
        >
          {title}
        </h1>
        {intro ? <div className="text-body text-muted-foreground">{intro}</div> : null}
      </header>
      <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-5 shadow-panel">
        {children}
      </div>
    </main>
  )
}
