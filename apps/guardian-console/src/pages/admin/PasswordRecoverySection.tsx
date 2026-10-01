import { useState } from 'react'

import { sendPasswordReset, type ManagedAccount } from '../../api/admin.ts'
import { toConfirmResult } from '../../admin/confirmResult.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'
import { Panel } from '../../ui/Panel.tsx'

/**
 * Help someone who cannot sign in recover by sending them the NORMAL password-reset email. The operator never chooses,
 * sees or receives the password, the token or the link: the account holder follows the usual link and sets their own.
 * Deliberate: it says what will happen, needs a recent proof from you, and needs an explicit confirmation. Offered only for
 * an active account that is not your own. It does not touch two-step verification, which has its own panel.
 */
export function PasswordRecoverySection({
  account,
  own,
}: {
  account: ManagedAccount
  own: boolean
}) {
  const run = useAdminAction()
  const [open, setOpen] = useState(false)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  if (own || account.status !== 'active') return null

  return (
    <Panel title="Password">
      <div className="flex flex-col gap-3">
        {notice ? (
          <Alert key={notice.text} tone={notice.tone}>
            {notice.text}
          </Alert>
        ) : null}
        <p className="text-body text-foreground">
          Send a password reset email to this account holder. They choose their own new password.
        </p>
        <div>
          <Button
            onClick={() => {
              setNotice(null)
              setOpen(true)
            }}
          >
            Send password reset email
          </Button>
        </div>

        {open ? (
          <ConfirmDialog
            title={`Send ${account.displayName} a password reset email?`}
            confirmLabel="Send reset email"
            onCancel={() => {
              setOpen(false)
            }}
            onConfirm={async () =>
              toConfirmResult(await run(() => sendPasswordReset(account.id)), (result) => {
                setOpen(false)
                setNotice(
                  result.delivery === 'sent'
                    ? {
                        tone: 'success',
                        text: `A password reset email was sent to ${result.account.email}.`,
                      }
                    : {
                        tone: 'error',
                        text: 'The password reset email could not be sent. Try again in a moment.',
                      },
                )
              })
            }
          >
            <p>
              A fresh password reset email goes to <strong>{account.email}</strong>. Any earlier
              reset link stops working.
            </p>
            <ul className="list-disc pl-5">
              <li>You will not see or set the new password. They choose it from the link.</li>
              <li>Their two-step verification is not reset.</li>
            </ul>
          </ConfirmDialog>
        ) : null}
      </div>
    </Panel>
  )
}
