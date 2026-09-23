import { NavLink, Outlet } from 'react-router'

import { useCurrentAccount } from '../auth/auth-context.ts'
import { ACCOUNTS_VIEW, hasCapability, MEMBERSHIP_VIEW } from '../auth/capabilities.ts'
import { StepUpProvider } from '../auth/StepUpProvider.tsx'
import { SignOutButton } from './SignOutButton.tsx'

const link = ({ isActive }: { isActive: boolean }) =>
  `rounded px-2 py-1 text-sm ${isActive ? 'bg-slate-200 font-medium' : 'text-slate-700 hover:bg-slate-100'}`

/** The signed-in frame: who you are, where you can go, and how to leave. */
export function ConsoleLayout() {
  const current = useCurrentAccount()

  return (
    <div className="mx-auto flex min-h-screen max-w-4xl flex-col gap-6 p-6">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
        <div className="flex flex-wrap items-center gap-4">
          <p className="font-semibold">Flow Life Guardian Console</p>
          <nav aria-label="Console" className="flex gap-1">
            <NavLink to="/" end className={link}>
              Home
            </NavLink>
            {hasCapability(current, ACCOUNTS_VIEW) ? (
              <NavLink to="/admin/accounts" className={link}>
                Accounts
              </NavLink>
            ) : null}
            {hasCapability(current, MEMBERSHIP_VIEW) ? (
              <NavLink to="/admin/members" className={link}>
                Members
              </NavLink>
            ) : null}
            <NavLink to="/account/security" className={link}>
              Account security
            </NavLink>
          </nav>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-sm text-slate-600">{current.person.display_name}</span>
          <SignOutButton />
        </div>
      </header>
      <main className="flex-1">
        <StepUpProvider>
          <Outlet />
        </StepUpProvider>
      </main>
    </div>
  )
}
