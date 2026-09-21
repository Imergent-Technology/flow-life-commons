import { useState } from 'react'

import { reissueInvitation, type ManagedAccount } from '../../api/admin.ts'
import { toConfirmResult } from '../../admin/confirmResult.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { shown } from '../../admin/time.ts'
import { Alert } from '../../ui/Alert.tsx'
import { secondaryButton } from '../../ui/classes.ts'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'

/** For an Account that is still invited: what its invitation is, and a way to send a fresh one. */
export function InvitationSection({
  account,
  mayIssue,
  onChanged,
}: {
  account: ManagedAccount
  mayIssue: boolean
  onChanged: (next: ManagedAccount) => void
}) {
  const run = useAdminAction()
  const [open, setOpen] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const { invitation } = account

  return (
    <section aria-labelledby="invitation-heading" className="flex flex-col gap-3">
      <h2 id="invitation-heading" className="text-lg font-medium">
        Invitation
      </h2>
      {notice ? (
        <Alert key={notice.text} tone={notice.tone}>
          {notice.text}
        </Alert>
      ) : null}
      {invitation === null ? (
        <p className="text-sm text-slate-600">There is no outstanding invitation.</p>
      ) : (
        <p className="text-sm text-slate-700">
          {invitation.expired ? 'Their invitation expired on ' : 'Their invitation is valid until '}
          <strong>{shown(invitation.expiresAt)}</strong>.
        </p>
      )}
      {mayIssue ? (
        <div>
          <button
            type="button"
            className={secondaryButton}
            onClick={() => {
              setNotice(null)
              setOpen(true)
            }}
          >
            Send a new invitation
          </button>
        </div>
      ) : null}

      {open ? (
        <ConfirmDialog
          title={`Send ${account.displayName} a new invitation?`}
          confirmLabel="Send invitation"
          onCancel={() => {
            setOpen(false)
          }}
          onConfirm={async () =>
            toConfirmResult(await run(() => reissueInvitation(account.id)), (result) => {
              onChanged(result.account)
              setOpen(false)
              setNotice(
                result.delivery === 'sent'
                  ? {
                      tone: 'success',
                      text: `A new invitation was sent to ${result.account.email}.`,
                    }
                  : {
                      tone: 'error',
                      text: 'The new invitation could not be sent. Try again in a moment.',
                    },
              )
            })
          }
        >
          <p>
            A new invitation goes to <strong>{account.email}</strong>. The old one stops working the
            moment the new one exists.
          </p>
        </ConfirmDialog>
      ) : null}
    </section>
  )
}
