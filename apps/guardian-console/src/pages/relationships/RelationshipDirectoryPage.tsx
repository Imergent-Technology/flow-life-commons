import { RelationshipDirectory } from '../../relationships/RelationshipDirectory.tsx'
import { useNotice } from '../resources/useNotice.ts'
import { RelationshipRoute } from './RelationshipRoute.tsx'

/** `/relationships/:slug`: the directory of one type, for an operator who may view it. */
export function RelationshipDirectoryPage() {
  const notice = useNotice()
  return (
    <RelationshipRoute needs="view">
      {(type) => <RelationshipDirectory key={type.slug} type={type} notice={notice} />}
    </RelationshipRoute>
  )
}
