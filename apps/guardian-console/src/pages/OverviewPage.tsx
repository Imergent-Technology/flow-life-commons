import { useCurrentAccount } from '../auth/auth-context.ts'
import { HealthPanel } from '../ui/HealthPanel.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { SessionSummary } from '../ui/SessionSummary.tsx'

/** The authenticated landing page: who is signed in, and whether the platform is answering. Nothing more. */
export function OverviewPage() {
  const current = useCurrentAccount()

  return (
    <Page width="detail">
      <PageHeader
        title="Overview"
        description={
          <>
            Signed in as <strong className="font-semibold">{current.person.display_name}</strong> (
            {current.account.email}).
          </>
        }
      />
      <div className="@container">
        <div className="grid items-start gap-6 @3xl:grid-cols-2">
          <Panel title="Your session">
            <SessionSummary />
          </Panel>
          <HealthPanel />
        </div>
      </div>
    </Page>
  )
}
