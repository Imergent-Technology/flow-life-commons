import { useCurrentAccount } from '../auth/auth-context.ts'
import { MfaSection } from '../pages/MfaSection.tsx'
import { ChangePasswordPanel } from '../ui/ChangePasswordPanel.tsx'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { Panel } from '../ui/Panel.tsx'
import { SessionSummary } from '../ui/SessionSummary.tsx'

/**
 * `/my/security`: the same self-service security operations any authenticated Account already has,
 * presented on the Member surface (ADR 0032, Work Package 4). Nothing here is a new backend capability —
 * `password/change`, and (when an authenticator already exists) `mfa/recovery-codes` and
 * `mfa/authenticator` all need only `auth:web`, not `console.access`.
 *
 * Deliberately NOT offered: first-time MFA enrolment. An ordinary Member is not required to enrol
 * (SecondFactorRequirement/ConsoleMultiFactorPolicy tie that requirement to `console.access`, ADR 0023),
 * and gaining `console.access` still forces the existing sign-in-time enrolment flow (`MfaEnrollment`) —
 * this page must not invent a second way in. So `MfaSection` (which assumes an authenticator already
 * exists, and offers no "not set up" state of its own) is shown only once `current.mfa.enrolled` says one
 * does; an Account with none sees password change alone, not an "Enable MFA" control that does not exist
 * anywhere else in this design.
 */
export function SecurityPage() {
  const current = useCurrentAccount()

  return (
    <Page width="form">
      <PageHeader title="Security" />

      <Panel title="Your session">
        <SessionSummary />
      </Panel>

      {current.mfa.enrolled ? <MfaSection /> : null}

      <ChangePasswordPanel />
    </Page>
  )
}
