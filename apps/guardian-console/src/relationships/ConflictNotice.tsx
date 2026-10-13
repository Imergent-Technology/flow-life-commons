import { shown } from '../admin/time.ts'
import { personName } from './definitions.ts'
import { Alert } from '../ui/Alert.tsx'
import { Button } from '../ui/Button.tsx'
import type { Conflict } from './useRelationshipData.ts'

/**
 * Why a change was not made, when the relationship moved under the operator (ADR 0038, F10). The page is already showing
 * the current state. This says that nothing the operator submitted was applied, who last changed the status where their
 * authority lets them see it, and, when the relationship was deleted and recorded again, that what is on screen is a NEW
 * relationship that their change was never applied to. The operator decides again; nothing is sent for them.
 */
export function ConflictNotice({
  conflict,
  singular,
  onDismiss,
}: {
  conflict: Conflict
  singular: string
  onDismiss: () => void
}) {
  const noun = singular.toLowerCase()

  if (conflict.kind === 'gone') {
    return (
      <Alert tone="warning" focusOnMount>
        <p>
          This {noun} no longer exists: it was deleted while you were working. Nothing you submitted
          was applied.
        </p>
      </Alert>
    )
  }

  const recorded =
    conflict.recordedBy === null || conflict.recordedAt === null
      ? null
      : `It was recorded again by ${personName(conflict.recordedBy)} on ${shown(conflict.recordedAt)}.`
  const changed =
    conflict.lastStatusBy === null || conflict.lastStatusAt === null
      ? null
      : `Its status was last set by ${personName(conflict.lastStatusBy)} on ${shown(conflict.lastStatusAt)}.`

  return (
    <Alert tone="warning" focusOnMount>
      <div className="flex flex-col gap-2">
        {conflict.recreated ? (
          <>
            <p className="font-medium">This {noun} was deleted and recorded again.</p>
            <p>
              What you see now is the new {noun}. Your change was meant for the one that was
              deleted, so it was not applied to this one. Look at it, and decide again if you still
              need to.
            </p>
            {recorded === null ? null : <p>{recorded}</p>}
          </>
        ) : (
          <>
            <p className="font-medium">This {noun} was changed while you were working.</p>
            <p>
              Your change was not applied. The current state is shown below. Look at it, and make
              your change again if you still need to.
            </p>
            {changed === null ? null : <p>{changed}</p>}
          </>
        )}
        <div>
          <Button size="sm" onClick={onDismiss}>
            Dismiss
          </Button>
        </div>
      </div>
    </Alert>
  )
}
