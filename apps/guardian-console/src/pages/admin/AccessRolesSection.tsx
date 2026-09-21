import { useCallback, useState } from 'react'

import {
  grantRole,
  listRoleCatalog,
  revokeRole,
  type ManagedAccount,
  type RoleDescriptor,
} from '../../api/admin.ts'
import { toConfirmResult } from '../../admin/confirmResult.ts'
import { useAdminAction } from '../../admin/useAdminAction.ts'
import { useLoad } from '../../admin/useLoad.ts'
import { Alert } from '../../ui/Alert.tsx'
import { dangerButton, secondaryButton } from '../../ui/classes.ts'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'

type Pending =
  { kind: 'grant'; entry: RoleDescriptor } | { kind: 'revoke'; key: string; name: string }

/**
 * What an Account can do. The names and descriptions are whatever the server offers: this screen defines no access role and
 * decides nothing from one. The server refuses what it must (for example leaving the platform without an active
 * administrator) and this says so plainly rather than trying to predict it.
 */
export function AccessRolesSection({
  account,
  mayAssign,
  onChanged,
}: {
  account: ManagedAccount
  mayAssign: boolean
  onChanged: (next: ManagedAccount) => void
}) {
  const run = useAdminAction()
  const load = useCallback((signal: AbortSignal) => listRoleCatalog(signal), [])
  const [catalog] = useLoad(load)
  const [pending, setPending] = useState<Pending | null>(null)
  const [choice, setChoice] = useState('')
  const [notice, setNotice] = useState<string | null>(null)

  const held = new Set(account.assignments.map((a) => a.key))
  const available =
    catalog.status === 'loaded' ? catalog.value.filter((entry) => !held.has(entry.key)) : []
  const chosen = available.find((entry) => entry.key === choice)

  return (
    <section aria-labelledby="access-heading" className="flex flex-col gap-3">
      <h2 id="access-heading" className="text-lg font-medium">
        Access
      </h2>
      {notice ? (
        <Alert key={notice} tone="success">
          {notice}
        </Alert>
      ) : null}
      {account.assignments.length === 0 ? (
        <p className="text-sm text-slate-600">They hold no access. They cannot use the Console.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {account.assignments.map((assignment) => (
            <li
              key={assignment.key}
              className="flex flex-wrap items-start justify-between gap-3 rounded-md border border-slate-200 p-3"
            >
              <div className="text-sm">
                <p className="font-medium">{assignment.name}</p>
                <p className="text-slate-600">{assignment.description}</p>
              </div>
              {mayAssign ? (
                <button
                  type="button"
                  className={dangerButton}
                  aria-label={`Remove ${assignment.name}`}
                  onClick={() => {
                    setNotice(null)
                    setPending({ kind: 'revoke', key: assignment.key, name: assignment.name })
                  }}
                >
                  Remove
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {mayAssign ? (
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1">
            <label htmlFor="add-access" className="text-sm font-medium text-slate-800">
              Give them access
            </label>
            <select
              id="add-access"
              value={choice}
              disabled={catalog.status !== 'loaded' || available.length === 0}
              onChange={(event) => {
                setChoice(event.target.value)
              }}
              className="rounded-md border border-slate-300 bg-white px-3 py-1.5 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900 disabled:bg-slate-100"
            >
              <option value="">
                {catalog.status === 'loaded' && available.length === 0
                  ? 'They hold everything on offer'
                  : 'Choose…'}
              </option>
              {available.map((entry) => (
                <option key={entry.key} value={entry.key}>
                  {entry.name}
                </option>
              ))}
            </select>
          </div>
          <button
            type="button"
            className={secondaryButton}
            disabled={chosen === undefined}
            onClick={() => {
              if (chosen === undefined) return
              setNotice(null)
              setPending({ kind: 'grant', entry: chosen })
            }}
          >
            Add access
          </button>
        </div>
      ) : null}

      {pending?.kind === 'grant' ? (
        <ConfirmDialog
          title={`Give ${account.displayName} the “${pending.entry.name}” access?`}
          confirmLabel="Give access"
          onCancel={() => {
            setPending(null)
          }}
          onConfirm={async () =>
            toConfirmResult(await run(() => grantRole(account.id, pending.entry.key)), (next) => {
              onChanged(next)
              setPending(null)
              setChoice('')
              setNotice(`${next.displayName} now has “${pending.entry.name}” access.`)
            })
          }
        >
          <p>{pending.entry.description}</p>
          <p>It takes effect on their very next request.</p>
        </ConfirmDialog>
      ) : null}

      {pending?.kind === 'revoke' ? (
        <ConfirmDialog
          title={`Remove “${pending.name}” from ${account.displayName}?`}
          confirmLabel="Remove access"
          destructive
          onCancel={() => {
            setPending(null)
          }}
          onConfirm={async () =>
            toConfirmResult(await run(() => revokeRole(account.id, pending.key)), (next) => {
              onChanged(next)
              setPending(null)
              setNotice(`“${pending.name}” was removed from ${next.displayName}.`)
            })
          }
        >
          <p>It stops applying on their very next request. Their sessions are not ended.</p>
        </ConfirmDialog>
      ) : null}
    </section>
  )
}
