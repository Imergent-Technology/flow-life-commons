import { Route, Routes } from 'react-router'

import { AuthProvider } from './auth/AuthProvider.tsx'
import { RequireAuthentication } from './auth/RequireAuthentication.tsx'
import { RequireConsoleAccess } from './auth/RequireConsoleAccess.tsx'
import { AcceptInvitationPage } from './pages/AcceptInvitationPage.tsx'
import { AccountSecurityPage } from './pages/AccountSecurityPage.tsx'
import { ForgotPasswordPage } from './pages/ForgotPasswordPage.tsx'
import { HomePage } from './pages/HomePage.tsx'
import { LoginPage } from './pages/LoginPage.tsx'
import { NotFoundPage } from './pages/NotFoundPage.tsx'
import { ResetPasswordPage } from './pages/ResetPasswordPage.tsx'
import { ConsoleLayout } from './ui/ConsoleLayout.tsx'

/**
 * The Console's routes. Public pages need no session; everything else sits behind the authentication
 * boundary and then the Console-access boundary. Console routes must never start with /api or be /up:
 * the gateway sends those to the platform, not to this app.
 */
function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/accept-invitation" element={<AcceptInvitationPage />} />

        <Route element={<RequireAuthentication />}>
          <Route element={<RequireConsoleAccess />}>
            <Route element={<ConsoleLayout />}>
              <Route index element={<HomePage />} />
              <Route path="account/security" element={<AccountSecurityPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>
          </Route>
        </Route>
      </Routes>
    </AuthProvider>
  )
}

export default App
