import { useState } from 'react'

import { resetAccountMfa, type ManagedAccount } from '../../api/admin.ts'
import { toConfirmResult } from '../../admin/confirmResult.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { Alert } from '../../ui/Alert.tsx'
import { dangerButton } from '../../ui/classes.ts'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'

/**
 * Recover someone who has lost their authenticator AND every recovery code. Deliberate: it says what will happen to the
 * person, needs a recent proof from you, and needs an explicit confirmation. Never offered on your own account.
 */
export function MfaRecoverySection({
  account,
  own,
  mayRecover,
  onChanged,
}: {
  account: ManagedAccount
  own: boolean
  mayRecover: boolean
  onChanged: (next: ManagedAccount) => void
}) {
  const run = useAdminAction()
  const [open, setOpen] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  return (
    <section aria-labelledby="mfa-recovery-heading" className="flex flex-col gap-3">
      <h2 id="mfa-recovery-heading" className="text-lg font-medium">
        Two-step verification
      </h2>
      {notice ? (
        <Alert key={notice} tone="success">
          {notice}
        </Alert>
      ) : null}
      <p className="text-sm text-slate-700">
        {account.mfa.enrolled
          ? `Set up, with ${String(account.mfa.recoveryCodesRemaining)} recovery ${account.mfa.recoveryCodesRemaining === 1 ? 'code' : 'codes'} left.`
          : 'Not set up.'}
      </p>
      {!mayRecover ? null : own ? (
        <p className="text-sm text-slate-600">
          To replace your own authenticator, use Account security. If you have lost it, another
          administrator or the server operator can reset it.
        </p>
      ) : account.mfa.enrolled ? (
        <div>
          <button
            type="button"
            className={dangerButton}
            onClick={() => {
              setNotice(null)
              setOpen(true)
            }}
          >
            Reset two-step verification
          </button>
        </div>
      ) : null}

      {open ? (
        <ConfirmDialog
          title={`Reset two-step verification for ${account.displayName}?`}
          confirmLabel="Reset two-step verification"
          destructive
          onCancel={() => {
            setOpen(false)
          }}
          onConfirm={async () =>
            toConfirmResult(await run(() => resetAccountMfa(account.id)), (next) => {
              onChanged(next)
              setOpen(false)
              setNotice(
                `Two-step verification was reset for ${next.displayName}. They will set it up again when they next sign in.`,
              )
            })
          }
        >
          <p>
            This is for someone who has lost their authenticator and all their recovery codes. Check
            that it is really them before you continue.
          </p>
          <ul className="list-disc pl-5">
            <li>
              Their authenticator app and recovery codes for <strong>{account.email}</strong> will
              stop working.
            </li>
            <li>They will be signed out everywhere.</li>
            <li>
              They must sign in with their password and set up two-step verification again before
              they can use the Console.
            </li>
            <li>Their password, access and history are not changed.</li>
          </ul>
        </ConfirmDialog>
      ) : null}
    </section>
  )
}
