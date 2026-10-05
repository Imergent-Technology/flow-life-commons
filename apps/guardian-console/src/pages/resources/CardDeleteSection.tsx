import { useState } from 'react'
import { useNavigate } from 'react-router'

import { useAdminAction } from '../../admin/useAdminAction.ts'
import { deleteCard, type ManagedCard } from '../../api/resources.ts'
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog, type ConfirmResult } from '../../ui/ConfirmDialog.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { toResourceConfirmResult } from './confirm.ts'

/**
 * Permanent deletion of a Card (ADR 0037, decisions 53, 55, 58). It needs `resources.manage` AND a recent check of who is asking.
 * There is no Trash or Restore, and the dialog does not offer one. A File Card's file is removed from the store once the
 * deletion has succeeded. The server refuses to delete the last Published Card of a Published Pack, and says so in the dialog.
 */
export function CardDeleteSection({ card }: { card: ManagedCard }) {
  const navigate = useNavigate()
  const run = useAdminAction()
  const [open, setOpen] = useState(false)

  async function confirm(): Promise<ConfirmResult> {
    const outcome = await run(() => deleteCard(card.packId, card.id))
    return toResourceConfirmResult(
      outcome,
      () => {
        void navigate(`/resources/packs/${card.packId}`, {
          state: { notice: `The Card “${card.title}” was permanently deleted.` },
        })
      },
      'card',
    )
  }

  return (
    <Panel
      tone="danger"
      title="Delete this Card"
      description="Permanent. There is no Trash and no way to restore a deleted Card."
    >
      <Button
        variant="danger"
        onClick={() => {
          setOpen(true)
        }}
      >
        Delete Card…
      </Button>
      {open ? (
        <ConfirmDialog
          title="Permanently delete this Card?"
          confirmLabel="Delete permanently"
          destructive
          onConfirm={confirm}
          onCancel={() => {
            setOpen(false)
          }}
        >
          <p>
            This permanently deletes <strong className="wrap-anywhere">{card.title}</strong>. It
            cannot be restored.
          </p>
          {card.type === 'file' ? (
            <p>Its managed file is removed from the store once the deletion succeeds.</p>
          ) : null}
          <p>
            Deleting needs a recent check of who you are, so you may be asked to confirm it is you.
          </p>
        </ConfirmDialog>
      ) : null}
    </Panel>
  )
}
