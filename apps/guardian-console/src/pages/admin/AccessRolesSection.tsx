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
import { Button } from '../../ui/Button.tsx'
import { ConfirmDialog } from '../../ui/ConfirmDialog.tsx'
import { Field } from '../../ui/Field.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { Select } from '../../ui/Select.tsx'

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
    <Panel title="Access">
      <div className="flex flex-col gap-3">
        {notice ? (
          <Alert key={notice} tone="success">
            {notice}
          </Alert>
        ) : null}
        {account.assignments.length === 0 ? (
          <p className="text-body text-muted-foreground">
            They hold no access. They cannot use the Console.
          </p>
        ) : (
          <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
            {account.assignments.map((assignment) => (
              <li
                key={assignment.key}
                className="flex flex-wrap items-start justify-between gap-3 p-3"
              >
                <div className="min-w-0 text-body">
                  <p className="font-medium text-foreground">{assignment.name}</p>
                  <p className="text-muted-foreground">{assignment.description}</p>
                </div>
                {mayAssign ? (
                  <Button
                    variant="danger"
                    size="sm"
                    aria-label={`Remove ${assignment.name}`}
                    onClick={() => {
                      setNotice(null)
                      setPending({ kind: 'revoke', key: assignment.key, name: assignment.name })
                    }}
                  >
                    Remove
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        )}

        {mayAssign ? (
          <div className="flex flex-wrap items-end gap-3">
            <div className="w-full sm:w-72">
              <Field label="Give them access">
                {(control) => (
                  <Select
                    {...control}
                    value={choice}
                    disabled={catalog.status !== 'loaded' || available.length === 0}
                    onChange={(event) => {
                      setChoice(event.target.value)
                    }}
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
                  </Select>
                )}
              </Field>
            </div>
            <Button
              disabled={chosen === undefined}
              onClick={() => {
                if (chosen === undefined) return
                setNotice(null)
                setPending({ kind: 'grant', entry: chosen })
              }}
            >
              Add access
            </Button>
          </div>
        ) : null}
      </div>

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
    </Panel>
  )
}
