import type { ReactNode } from 'react'

import { PageHeading } from './PageHeading.tsx'

/** The frame for the pages a person sees before they are signed in. */
export function AuthLayout({
  title,
  intro,
  children,
}: {
  title: string
  intro?: ReactNode
  children: ReactNode
}) {
  return (
    <main className="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center gap-6 p-6">
      <header className="flex flex-col gap-2">
        <p className="text-sm font-medium text-slate-500">Flow Life Guardian Console</p>
        <PageHeading title={title} />
        {intro ? <div className="text-sm text-slate-600">{intro}</div> : null}
      </header>
      {children}
    </main>
  )
}
