import { useState } from 'react'
import { useNavigate } from 'react-router'

import { useAdminAction } from '../../admin/useAdminAction.ts'
import { deletePack, type ManagedPack } from '../../api/resources.ts'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { toResourceConfirmResult } from './confirm.ts'

/**
 * Permanent deletion of a Pack (ADR 0037, decisions 53, 55, 59). It needs `resources.manage` AND a recent check of who is asking:
 * a stolen session is not enough to destroy content. There is no Trash or Restore. The dialog says exactly what goes: the Pack,
 * every Card in it, and the files File Cards hold. It does not offer a restore, because there is none for a person to use.
 */
export function PackDeleteSection({ pack }: { pack: ManagedPack }) {
  const navigate = useNavigate()
  const run = useAdminAction()
  const [open, setOpen] = useState(false)
  const cards = pack.cards ?? []
  const files = cards.filter((card) => card.type === 'file').length

  async function confirm(): Promise<ConfirmResult> {
    const outcome = await run(() => deletePack(pack.id))
    return toResourceConfirmResult(
      outcome,
      () => {
        void navigate('/resources', {
          state: { notice: `The Resource Pack “${pack.title}” was permanently deleted.` },
        })
      },
      'pack',
    )
  }

  return (
    <Panel
      tone="danger"
      title="Delete this Pack"
      description="Permanent. There is no Trash and no way to restore a deleted Pack."
    >
      <Button
        variant="danger"
        onClick={() => {
          setOpen(true)
        }}
      >
        Delete Resource Pack…
      </Button>
      {open ? (
        <ConfirmDialog
          title="Permanently delete this Pack?"
          confirmLabel="Delete permanently"
          destructive
          onConfirm={confirm}
          onCancel={() => {
            setOpen(false)
          }}
        >
          <p>
            This permanently deletes <strong className="wrap-anywhere">{pack.title}</strong> and{' '}
            {cards.length === 0
              ? 'it has no Cards.'
              : `all ${String(cards.length)} ${cards.length === 1 ? 'Card' : 'Cards'} in it.`}{' '}
            It cannot be restored.
          </p>
          {files > 0 ? (
            <p>
              {files === 1
                ? 'The managed file of its File Card is'
                : `The managed files of its ${String(files)} File Cards are`}{' '}
              removed from the store once the deletion succeeds.
            </p>
          ) : null}
          <p>
            Deleting needs a recent check of who you are, so you may be asked to confirm it is you.
          </p>
        </ConfirmDialog>
      ) : null}
    </Panel>
  )
}
