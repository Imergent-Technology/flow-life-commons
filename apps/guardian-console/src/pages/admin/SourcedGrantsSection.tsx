import type { ManagedAccount } from '../../api/admin.ts'
import { shown } from '../../admin/time.ts'
import { Panel } from '../../ui/Panel.tsx'
import { TextLink } from '../../ui/TextLink.tsx'

/**
 * Access this Account holds because of a specific source, such as a relationship's default role (ADR 0038, K4). It is
 * read-only and kept apart from the independent assignments above it on purpose: nothing here can be revoked from an
 * Account, because withdrawing it is a decision on its source (the relationship), which follows that relationship's own
 * lifecycle. The words are whatever the server supplies. Nothing is assembled from relationship data here.
 */
export function SourcedGrantsSection({ account }: { account: ManagedAccount }) {
  if (account.sourcedGrants.length === 0) return null

  return (
    <Panel
      title="Access through a source"
      description="Access that was granted because of something else, such as a relationship. It is not an assignment and cannot be removed here."
    >
      <ul
        aria-label="Access through a source"
        className="flex flex-col divide-y divide-border rounded-md border border-border"
      >
        {account.sourcedGrants.map((grant) => (
          <li
            key={`${grant.key}-${grant.sourceType}-${grant.grantedAt}`}
            className="flex flex-col gap-1 p-3 text-body"
          >
            <p className="font-medium text-foreground">{grant.name}</p>
            <p className="text-muted-foreground">{grant.description}</p>
            <p className="text-meta text-muted-foreground">
              {grant.sourceLabel} · granted {shown(grant.grantedAt)}
              {grant.grantedByAccountId === null ? null : (
                <>
                  {' '}
                  by{' '}
                  <TextLink to={`/admin/accounts/${grant.grantedByAccountId}`}>
                    this account
                  </TextLink>
                </>
              )}
            </p>
          </li>
        ))}
      </ul>
    </Panel>
  )
}
