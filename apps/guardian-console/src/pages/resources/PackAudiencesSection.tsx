import { useState, type SyntheticEvent } from 'react'

import { describeResourcesFailure } from '../../admin/resourcesWording.ts'
import { setPackAudiences, type Audience, type ManagedPack } from '../../api/resources.ts'
import { Panel } from '../../ui/Panel.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { AudienceFields } from './AudienceFields.tsx'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { useFeedback } from './useFeedback.ts'

const same = (a: readonly Audience[], b: readonly Audience[]) =>
  a.length === b.length && a.every((each) => b.includes(each))

/**
 * Who a Pack is aimed at (ADR 0037, decisions 38-44). The choice replaces the Pack's whole set; anyone in ANY chosen audience
 * qualifies. A Card inherits its Pack's audiences or narrows to a subset, and can never be broader than its Pack, so removing an
 * audience a narrowed Card still has is refused by the server, which names those Cards: nothing is adjusted for the person. A
 * Published Pack must keep at least one audience.
 */
export function PackAudiencesSection({
  pack,
  onChange,
}: {
  pack: ManagedPack
  onChange: (next: ManagedPack) => void
}) {
  const [chosen, setChosen] = useState<Audience[]>(() => [...pack.audiences])
  const [pending, setPending] = useState(false)
  const { feedback, say, clear } = useFeedback()

  async function save() {
    if (same(chosen, pack.audiences)) {
      say('info', 'There is nothing to save: the audiences have not changed.')
      return
    }
    setPending(true)
    clear()
    const result = await setPackAudiences(pack.id, chosen)
    setPending(false)
    if (result.ok) {
      onChange(result.value)
      setChosen([...result.value.audiences])
      say('success', 'The Pack’s audiences were saved.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const problem = describeResourcesFailure(result.failure)
    // A refusal that names Cards: say which, by title.
    const named =
      result.failure.kind === 'conflict' && result.failure.code === 'card_audience_conflict'
        ? (result.failure.detail?.cards ?? []).map(
            (id) => pack.cards?.find((card) => card.id === id)?.title ?? 'A Card',
          )
        : problem.items
    say('error', problem.message, named)
  }

  return (
    <Panel
      title="Audiences"
      description="Who the Pack is for. Anyone in any chosen audience qualifies."
    >
      <form
        aria-label="Pack audiences"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void save()
        }}
        className="flex flex-col gap-3"
      >
        <FeedbackAlert feedback={feedback} />
        <AudienceFields legend="Aimed at" value={chosen} onChange={setChosen} />
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Save audiences
        </SubmitButton>
      </form>
    </Panel>
  )
}
