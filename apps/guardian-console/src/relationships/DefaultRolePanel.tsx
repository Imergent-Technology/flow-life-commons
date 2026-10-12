import { useState } from 'react'

import { decideDefaultRole, type DefaultRoleState } from '../api/relationships.ts'
import { shown } from '../admin/time.ts'
import { Button } from '../ui/Button.tsx'
import { ConfirmDialog } from '../ui/ConfirmDialog.tsx'
import { Panel } from '../ui/Panel.tsx'
import { Property, PropertyList } from '../ui/PropertyList.tsx'
import { personName, stateLabel, stateQualifies } from './definitions.ts'
import { confirmResultFor } from './outcome.ts'
import type { RelationshipData } from './useRelationshipData.ts'

type Pending = 'grant' | 'withdraw'

/** What the stored decision and the grant add up to, in words. Only about THIS relationship's own default role. */
function describeState(
  state: DefaultRoleState,
  qualifying: boolean,
  statusLabel: string,
): { headline: string; detail: string } {
  const role = state.displayName
  if (state.decision === 'granted') {
    if (state.effective) {
      return {
        headline: 'Granted, and in effect',
        detail: `${role} access is given through this relationship. It confers access once this person signs in with an Account; recording a relationship never creates one.`,
      }
    }
    return qualifying
      ? {
          headline: 'Granted, but not in effect',
          detail: `${role} access was granted but is not currently in effect. Withdraw it and grant it again, or ask an administrator to check it.`,
        }
      : {
          headline: 'Granted, withdrawn while not active',
          detail: `${role} access was withdrawn because the relationship is ${statusLabel}. It is not restored automatically: reactivating asks for a fresh decision.`,
        }
  }
  if (state.decision === 'declined') {
    return { headline: 'Declined', detail: `${role} access was declined, so none is given.` }
  }
  return { headline: 'Not decided', detail: `No decision about ${role} access has been made.` }
}

/**
 * The default-role panel (ADR 0038, C4, K8): the decision about THIS relationship's own default role, who made it and when,
 * and whether it is in effect. It never lists the Person's other roles and cannot see them. Granting and withdrawing are
 * decisions on the relationship, each confirmed deliberately and each behind the shared recent-verification prompt; they
 * send the instance and revision that were shown.
 *
 * "Grant" appears only while the relationship counts, and only to an operator the definition's hint says may provision. The
 * hint never authorizes: the server refuses a grant the operator may not make and this says so. "Withdraw" needs the manage
 * capability alone, not the authority to assign roles.
 */
export function DefaultRolePanel({
  data,
  onNotice,
  headingLevel = 2,
}: {
  data: RelationshipData
  onNotice: (message: string) => void
  headingLevel?: 2 | 3
}) {
  const { type, management, view } = data
  const state = management?.defaultRole ?? view?.defaultRole ?? null
  const [pending, setPending] = useState<Pending | null>(null)

  if (type.defaultRole === null || state === null) return null

  const status = management?.status ?? view?.status.key ?? ''
  const qualifying = stateQualifies(type, status)
  const described = describeState(state, qualifying, stateLabel(type, status))
  const name = personName(management?.person ?? view?.person ?? { id: '', displayName: null })
  const maySee = management !== null && type.actions.manage && !data.mismatch
  const mayGrant =
    maySee &&
    type.actions.provisionDefaultRole &&
    qualifying &&
    !(state.decision === 'granted' && state.effective)
  // An access that is already withdrawn (the relationship is not active) has nothing left to withdraw, and no restoration is offered.
  const mayWithdraw = maySee && (state.effective || (state.decision === 'granted' && qualifying))

  return (
    <Panel
      title={`${state.displayName} access`}
      headingLevel={headingLevel}
      actions={
        mayGrant || mayWithdraw ? (
          <>
            {mayGrant ? (
              <Button
                size="sm"
                onClick={() => {
                  setPending('grant')
                }}
              >
                Grant access
              </Button>
            ) : null}
            {mayWithdraw ? (
              <Button
                size="sm"
                variant="danger"
                onClick={() => {
                  setPending('withdraw')
                }}
              >
                Withdraw access
              </Button>
            ) : null}
          </>
        ) : undefined
      }
    >
      <div className="flex flex-col gap-3">
        <PropertyList>
          <Property term="Decision">{described.headline}</Property>
          {state.decidedBy === null || state.decidedAt === null ? null : (
            <Property term="Decided by">
              {personName(state.decidedBy)}, {shown(state.decidedAt)}
            </Property>
          )}
        </PropertyList>
        <p className="text-body text-muted-foreground">{described.detail}</p>
        {maySee && qualifying && !type.actions.provisionDefaultRole && !state.effective ? (
          <p className="text-meta text-muted-foreground">
            Granting {state.displayName} access needs permission to assign roles, which you do not
            have.
          </p>
        ) : null}
      </div>

      {pending === null ? null : (
        <ConfirmDialog
          title={
            pending === 'grant'
              ? `Grant ${state.displayName} access to ${name}?`
              : `Withdraw ${state.displayName} access from ${name}?`
          }
          confirmLabel={pending === 'grant' ? 'Grant access' : 'Withdraw access'}
          destructive={pending === 'withdraw'}
          onCancel={() => {
            setPending(null)
          }}
          onConfirm={async () =>
            confirmResultFor(
              await data.mutate((shown) =>
                decideDefaultRole(type.slug, data.personId, {
                  relationshipId: shown.relationshipId,
                  revision: shown.revision,
                  decision: pending === 'grant' ? 'grant' : 'decline',
                }),
              ),
              {
                type,
                onDone: () => {
                  setPending(null)
                  onNotice(
                    pending === 'grant'
                      ? `${state.displayName} access was granted to ${name}.`
                      : `${state.displayName} access was withdrawn from ${name}.`,
                  )
                },
                onStale: () => {
                  setPending(null)
                },
              },
            )
          }
        >
          {pending === 'grant' ? (
            <>
              <p>
                {name} will be given {state.displayName} access through this relationship. It takes
                effect on their very next request, once they have an Account that can sign in.
              </p>
              <p>
                It is attributed to you, and withdrawn if this relationship becomes inactive or is
                deleted.
              </p>
            </>
          ) : (
            <>
              <p>
                The {state.displayName} access given through this relationship stops applying on
                their very next request, and the decision is recorded as declined.
              </p>
              <p>Roles granted to them directly are not affected.</p>
            </>
          )}
        </ConfirmDialog>
      )}
    </Panel>
  )
}
