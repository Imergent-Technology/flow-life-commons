import { useState } from 'react'

import { disableAccount, enableAccount, type ManagedAccount } from '../../api/admin.ts'
import { toConfirmResult } from '../../admin/confirmResult.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { Alert } from '../../ui/Alert.tsx'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'
import { Panel } from '../../ui/Panel.tsx'

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
    <Panel title="Account status" tone={own || disabled ? 'default' : 'danger'}>
      <div className="flex flex-col gap-3">
        {notice ? (
          <Alert key={notice} tone="success">
            {notice}
          </Alert>
        ) : null}
        {own ? (
          <p className="text-body text-muted-foreground">
            You cannot disable your own account here. Another administrator can.
          </p>
        ) : (
          <div>
            <Button
              variant={disabled ? 'secondary' : 'danger'}
              onClick={() => {
                setNotice(null)
                setOpen(true)
              }}
            >
              {disabled ? 'Re-enable this account' : 'Disable this account'}
            </Button>
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
              They will be able to sign in again with their password. They are not signed in for
              them.
            </p>
            <p>
              Nothing else changes. They still need their two-step code (or must set it up again if
              it was reset), and what they can do still depends on the access they hold.
            </p>
          </ConfirmDialog>
        ) : null}
      </div>
    </Panel>
  )
}
