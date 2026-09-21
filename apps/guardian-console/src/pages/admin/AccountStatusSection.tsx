import { useState } from 'react'

import { disableAccount, enableAccount, type ManagedAccount } from '../../api/admin.ts'
import { toConfirmResult } from '../../admin/confirmResult.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { Alert } from '../../ui/Alert.tsx'
import { dangerButton, secondaryButton } from '../../ui/classes.ts'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'

/** Take an Account out of service, or put it back. Never offered on the operator's own Account. */
export function AccountStatusSection({
  account,
  own,
  onChanged,
}: {
  account: ManagedAccount
  own: boolean
  onChanged: (next: ManagedAccount) => void
}) {
  const run = useAdminAction()
  const [open, setOpen] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const disabled = account.status === 'disabled'

  return (
    <section aria-labelledby="status-heading" className="flex flex-col gap-3">
      <h2 id="status-heading" className="text-lg font-medium">
        Account status
      </h2>
      {notice ? (
        <Alert key={notice} tone="success">
          {notice}
        </Alert>
      ) : null}
      {own ? (
        <p className="text-sm text-slate-600">
          You cannot disable your own account here. Another administrator can.
        </p>
      ) : (
        <div>
          <button
            type="button"
            className={disabled ? secondaryButton : dangerButton}
            onClick={() => {
              setNotice(null)
              setOpen(true)
            }}
          >
            {disabled ? 'Re-enable this account' : 'Disable this account'}
          </button>
        </div>
      )}

      {open && !disabled ? (
        <ConfirmDialog
          title={`Disable ${account.displayName}?`}
          confirmLabel="Disable account"
          destructive
          onCancel={() => {
            setOpen(false)
          }}
          onConfirm={async () =>
            toConfirmResult(await run(() => disableAccount(account.id)), (next) => {
              onChanged(next)
              setOpen(false)
              setNotice(`${next.displayName} is disabled.`)
            })
          }
        >
          <p>They will be signed out everywhere straight away and will not be able to sign in.</p>
          <p>
            Their person record, their access roles and their history are kept. You can re-enable
            them later.
          </p>
        </ConfirmDialog>
      ) : null}

      {open && disabled ? (
        <ConfirmDialog
          title={`Re-enable ${account.displayName}?`}
          confirmLabel="Re-enable account"
          onCancel={() => {
            setOpen(false)
          }}
          onConfirm={async () =>
            toConfirmResult(await run(() => enableAccount(account.id)), (next) => {
              onChanged(next)
              setOpen(false)
              setNotice(`${next.displayName} can sign in again.`)
            })
          }
        >
          <p>
            They will be able to sign in again with their password. They are not signed in for them.
          </p>
          <p>
            Nothing else changes. They still need their two-step code (or must set it up again if it
            was reset), and what they can do still depends on the access they hold.
          </p>
        </ConfirmDialog>
      ) : null}
    </section>
  )
}
