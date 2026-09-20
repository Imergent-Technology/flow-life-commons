import { useCurrentAccount } from '../auth/auth-context.ts'
import { HealthPanel } from '../ui/HealthPanel.tsx'
import { PageHeading } from '../ui/PageHeading.tsx'
import { SessionSummary } from '../ui/SessionSummary.tsx'

/** The authenticated landing page: the Console boundary, not the product. */
export function HomePage() {
  const current = useCurrentAccount()

  return (
    <div className="flex flex-col gap-6">
      <header>
        <PageHeading title="Flow Life Guardian Console" />
        <p className="mt-1 text-sm text-slate-600">
          Signed in as <strong>{current.person.display_name}</strong> ({current.account.email}).
        </p>
      </header>
      <section aria-labelledby="session" className="rounded-lg border border-slate-200 p-4">
        <h2 id="session" className="mb-2 text-sm font-medium text-slate-500">
          Your session
        </h2>
        <SessionSummary />
      </section>
      <HealthPanel />
    </div>
  )
}
