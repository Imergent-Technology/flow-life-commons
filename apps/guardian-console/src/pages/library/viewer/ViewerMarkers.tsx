import { stateLabel } from '../../../admin/resourcesWording.ts'
import type { ViewerPublication } from '../../../api/resourceLibrary.ts'
import { Badge } from '../../../ui/Badge.tsx'

/** Draft or Published in words and a shape (a filled dot, a hollow ring), never colour alone. */
export function PublicationBadge({ state }: { state: ViewerPublication }) {
  return <Badge variant={state === 'published' ? 'success' : 'neutral'}>{stateLabel(state)}</Badge>
}
