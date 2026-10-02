import { Route, Routes } from 'react-router'

import { AuthProvider } from './auth/AuthProvider.tsx'
import {
  ACCOUNTS_VIEW,
  INVITATIONS_ISSUE,
  MEMBERSHIP_MANAGE,
  MEMBERSHIP_VIEW,
  PEOPLE_MANAGE,
  PEOPLE_VIEW,
} from './auth/capabilities.ts'
import { RequireAuthentication } from './auth/RequireAuthentication.tsx'
import { RequireCapability } from './auth/RequireCapability.tsx'
import { RequireConsoleAccess } from './auth/RequireConsoleAccess.tsx'
import { HomePage as MemberHomePage } from './member/HomePage.tsx'
import { MemberShell } from './member/MemberShell.tsx'
import { MembershipPage as MemberMembershipPage } from './member/MembershipPage.tsx'
import { SecurityPage as MemberSecurityPage } from './member/SecurityPage.tsx'
import { AcceptInvitationPage } from './pages/AcceptInvitationPage.tsx'
import { AccountDetailPage } from './pages/admin/AccountDetailPage.tsx'
import { AccountsPage } from './pages/admin/AccountsPage.tsx'
import { InviteOperatorPage } from './pages/admin/InviteOperatorPage.tsx'
import { MemberDetailPage } from './pages/admin/MemberDetailPage.tsx'
import { MembersPage } from './pages/admin/MembersPage.tsx'
import { RegisterMemberPage } from './pages/admin/RegisterMemberPage.tsx'
import { PeoplePage } from './pages/people/PeoplePage.tsx'
import { PersonDetailPage } from './pages/people/PersonDetailPage.tsx'
import { RegisterPersonPage } from './pages/people/RegisterPersonPage.tsx'
import { AccountSecurityPage } from './pages/AccountSecurityPage.tsx'
import { ForgotPasswordPage } from './pages/ForgotPasswordPage.tsx'
import { LoginPage } from './pages/LoginPage.tsx'
import { NotFoundPage } from './pages/NotFoundPage.tsx'
import { OverviewPage } from './pages/OverviewPage.tsx'
import { ResetPasswordPage } from './pages/ResetPasswordPage.tsx'
import { ConsoleShell } from './shell/ConsoleShell.tsx'
import { ThemeProvider } from './ui/ThemeProvider.tsx'

/**
 * The Console's and the Member surface's routes together (ADR 0032, Work Package 4): public pages need no
 * session; everything else sits behind the authentication boundary, and THEN splits by capability, never
 * by role — `/my/*` needs only authentication, `console.access` decides the rest. Routes must never start
 * with /api or be /up: the gateway sends those to the platform, not to this app.
 *
 * ThemeProvider wraps everything, including the public pages: the theme is a device/interface fact
 * (ADR 0030), not an account one, so it applies whether or not anyone is signed in.
 */
function App() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/accept-invitation" element={<AcceptInvitationPage />} />

          <Route element={<RequireAuthentication />}>
            {/*
             * The Member self-service surface: authenticated is the whole requirement (ADR 0032). No
             * console.access, no capability, no membership check — an Account with none of those still
             * gets a normal answer here, exactly as GET /my/membership itself does. A Guardian may reach
             * it too (13): holding console.access refuses nothing on this surface.
             */}
            <Route path="my" element={<MemberShell />}>
              <Route index element={<MemberHomePage />} />
              <Route path="membership" element={<MemberMembershipPage />} />
              <Route path="security" element={<MemberSecurityPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>

            <Route element={<RequireConsoleAccess />}>
              <Route element={<ConsoleShell />}>
                <Route index element={<OverviewPage />} />
                <Route path="account/security" element={<AccountSecurityPage />} />
                <Route
                  path="people"
                  element={
                    <RequireCapability capability={PEOPLE_VIEW}>
                      <PeoplePage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="people/new"
                  element={
                    <RequireCapability capability={PEOPLE_MANAGE}>
                      <RegisterPersonPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="people/:personId"
                  element={
                    <RequireCapability capability={PEOPLE_VIEW}>
                      <PersonDetailPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="admin/accounts"
                  element={
                    <RequireCapability capability={ACCOUNTS_VIEW}>
                      <AccountsPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="admin/accounts/invite"
                  element={
                    <RequireCapability capability={INVITATIONS_ISSUE}>
                      <InviteOperatorPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="admin/accounts/:id"
                  element={
                    <RequireCapability capability={ACCOUNTS_VIEW}>
                      <AccountDetailPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="admin/members"
                  element={
                    <RequireCapability capability={MEMBERSHIP_VIEW}>
                      <MembersPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="admin/members/new"
                  element={
                    <RequireCapability capability={MEMBERSHIP_MANAGE}>
                      <RegisterMemberPage />
                    </RequireCapability>
                  }
                />
                <Route
                  path="admin/members/:personId"
                  element={
                    <RequireCapability capability={MEMBERSHIP_VIEW}>
                      <MemberDetailPage />
                    </RequireCapability>
                  }
                />
                <Route path="*" element={<NotFoundPage />} />
              </Route>
            </Route>
          </Route>
        </Routes>
      </AuthProvider>
    </ThemeProvider>
  )
}

export default App
