import { useParams } from 'react-router'

import { RelationshipRecord } from '../../relationships/RelationshipRecord.tsx'
import { RelationshipRoute } from './RelationshipRoute.tsx'

/** `/relationships/:slug/:personId`: one person's relationship, for an operator who may view or manage the type. */
export function RelationshipRecordPage() {
  const { personId = '' } = useParams()
  return (
    <RelationshipRoute needs="either">
      {(type) => (
        <RelationshipRecord key={`${type.slug}:${personId}`} type={type} personId={personId} />
      )}
    </RelationshipRoute>
  )
}
