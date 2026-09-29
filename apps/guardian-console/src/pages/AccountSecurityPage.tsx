import { ChangePasswordPanel } from '../ui/ChangePasswordPanel.tsx'
import { MfaSection } from '../ui/MfaSection.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { SessionSummary } from '../ui/SessionSummary.tsx'

/**
 * The Console's own account-security page: the signed-in session, two-step verification (an
 * Account reaching the Console always has one, ADR 0023), and password change. Console-only, unchanged in
 * shape; `MfaSection` and `ChangePasswordPanel` live in `ui/`, shared with the Member Security page.
 */
export function AccountSecurityPage() {
  return (
    <Page width="form">
      <PageHeader title="Account security" />

      <Panel title="Your session">
        <SessionSummary />
      </Panel>

      <MfaSection />

      <ChangePasswordPanel />
    </Page>
  )
}
