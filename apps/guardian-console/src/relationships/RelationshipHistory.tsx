import type { RelationshipRecord, RelationshipType } from '../api/relationships.ts'
import { shown } from '../admin/time.ts'
import { Panel } from '../ui/Panel.tsx'
import { personName, stateLabel } from './definitions.ts'
import { RelationshipStatusBadge } from './RelationshipStatusBadge.tsx'

/**
 * The status history of one relationship, as the view read supplies it: one entry for the recording and one for each change
 * of status, newest last as the server keeps them. It is the relationship's own history, not CRM interactions and not
 * Account security events. Only an operator with the type's view capability is given it, and only they are shown it.
 */
export function RelationshipHistory({
  type,
  record,
  headingLevel = 2,
}: {
  type: RelationshipType
  record: RelationshipRecord
  headingLevel?: 2 | 3
}) {
  return (
    <Panel title="History" headingLevel={headingLevel}>
      {record.history.length === 0 ? (
        <p className="text-body text-muted-foreground">Nothing has been recorded yet.</p>
      ) : (
        <ol aria-label="Status history" className="flex flex-col divide-y divide-border">
          {record.history.map((entry, index) => (
            <li
              key={`${entry.changedAt}-${String(index)}`}
              className="flex flex-col gap-1 py-2 first:pt-0 last:pb-0"
            >
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-body text-foreground">
                {entry.fromStatus === null ? (
                  <>
                    <span>Recorded as</span>
                    <RelationshipStatusBadge type={type} status={entry.toStatus} />
                  </>
                ) : (
                  <>
                    <span className="sr-only">
                      Changed from {stateLabel(type, entry.fromStatus)} to
                    </span>
                    <RelationshipStatusBadge type={type} status={entry.fromStatus} />
                    <span aria-hidden="true">→</span>
                    <RelationshipStatusBadge type={type} status={entry.toStatus} />
                  </>
                )}
              </div>
              <p className="text-meta wrap-anywhere text-muted-foreground">
                {shown(entry.changedAt)} by {personName(entry.changedBy)}
              </p>
            </li>
          ))}
        </ol>
      )}
    </Panel>
  )
}
