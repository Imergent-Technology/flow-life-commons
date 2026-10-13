import type { RelationshipType, StateTone } from '../api/relationships.ts'
import { Badge } from '../ui/Badge.tsx'
import { stateOf } from './definitions.ts'

const variants: Record<StateTone, 'success' | 'warning' | 'neutral'> = {
  positive: 'success',
  caution: 'warning',
  neutral: 'neutral',
}

/**
 * A relationship's status as the DEFINITION words it and tones it: its label, with a shape (dot, diamond or ring) that
 * repeats the tone, so the meaning never rests on colour alone. A status the definition does not hold is shown by its key
 * in the neutral tone.
 */
export function RelationshipStatusBadge({
  type,
  status,
}: {
  type: RelationshipType
  status: string
}) {
  const state = stateOf(type, status)
  return (
    <Badge variant={variants[state?.tone ?? 'neutral']} data-status={status}>
      {state?.label ?? status}
    </Badge>
  )
}
