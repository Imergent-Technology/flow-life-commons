import { audienceLabel, cardTypeLabel, stateLabel } from '../../admin/resourcesWording.ts'
import type { Audience, CardType, PublicationState } from '../../api/resources.ts'
import { Badge } from '../../ui/Badge.tsx'

/** Draft or Published, in words and a shape (a filled dot for Published, a hollow ring for Draft): never colour alone. */
export function StateBadge({ state }: { state: PublicationState }) {
  return <Badge variant={state === 'published' ? 'success' : 'neutral'}>{stateLabel(state)}</Badge>
}

/** A Card's Type. It is a label, not a status, and it never changes after the Card is created. */
export function TypeBadge({ type }: { type: CardType }) {
  return (
    <Badge variant="accent" indicator="none">
      {cardTypeLabel(type)}
    </Badge>
  )
}

/** The audiences a Pack or Card is aimed at, as words. None is said, not left blank. */
export function AudienceList({
  audiences,
  empty = 'No audience',
}: {
  audiences: readonly Audience[]
  empty?: string
}) {
  return <>{audiences.length === 0 ? empty : audiences.map(audienceLabel).join(', ')}</>
}
