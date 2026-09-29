import { ChangePasswordPanel } from '../ui/ChangePasswordPanel.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { SessionSummary } from '../ui/SessionSummary.tsx'
import { MfaSection } from './MfaSection.tsx'

/**
 * The Console's own account-security page: the signed-in session, two-step verification (an
 * Account reaching the Console always has one, ADR 0023), and password change. Console-only, unchanged in
 * shape; the password-change panel it shares with the Member Security page lives in `ui/ChangePasswordPanel`.
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
