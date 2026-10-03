import { stateLabel } from '../../admin/discussionsWording.ts'
import { Badge } from '../../ui/Badge.tsx'

/**
 * A discussion's state in words, never in colour alone (the badge's shape repeats it, and the text is what carries it).
 * Resolved is a quiet neutral, not a warning: it means no new replies, not that the record is frozen or archived.
 */
export function StateBadge({ state }: { state: string }) {
  return <Badge variant={state === 'open' ? 'success' : 'neutral'}>{stateLabel(state)}</Badge>
}
