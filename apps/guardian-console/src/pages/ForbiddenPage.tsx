import { useCurrentAccount } from '../auth/auth-context.ts'
import { PageHeading } from '../ui/PageHeading.tsx'
import { SignOutButton } from '../ui/SignOutButton.tsx'

/**
 * Signed in, but without Console access: 403, not 401. So it offers sign-out and does NOT send the
 * person to the login page (which would send them straight back). It says nothing about why: what
 * grants access is the platform's business, not something to describe to someone who lacks it.
 */
export function ForbiddenPage() {
  const current = useCurrentAccount()

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center gap-4 p-6">
      <PageHeading title="Access denied" />
      <p className="text-slate-700">
        You are signed in as <strong>{current.person.display_name}</strong> ({current.account.email}
        ), but this account cannot use the Guardian Console.
      </p>
      <p className="text-sm text-slate-600">
        If you think this is a mistake, ask the person who invited you.
      </p>
      <div className="flex flex-col items-start gap-3">
        <SignOutButton />
      </div>
    </main>
  )
}
