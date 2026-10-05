import { useState } from 'react'

import { describeResourcesFailure } from '../../admin/resourcesWording.ts'
import { setCardPublication, type ManagedCard } from '../../api/resources.ts'
import { Button } from '../../ui/Button.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { StateBadge } from './badges.tsx'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { useFeedback } from './useFeedback.ts'

const NEEDS = {
  basic: 'a title and some text in its content',
  external_link: 'a title and a web address',
  file: 'a title and its file, present in the store',
} as const

/**
 * Publishing a Card, independently of its Pack (ADR 0037, decisions 22-23). Reversible, idempotent, and no recent verification.
 * What a Card needs depends on its Type; the server judges it and names what is missing. Unpublishing a Pack's last Published
 * Card while the Pack is Published is refused by the server too, and said plainly: nothing is unpublished on the person's behalf.
 */
export function CardPublicationSection({
  card,
  onChange,
}: {
  card: ManagedCard
  onChange: (next: ManagedCard) => void
}) {
  const [pending, setPending] = useState(false)
  const { feedback, say, clear } = useFeedback()
  const published = card.state === 'published'

  async function change() {
    setPending(true)
    clear()
    const result = await setCardPublication(
      card.packId,
      card.id,
      published ? 'unpublish' : 'publish',
    )
    setPending(false)
    if (result.ok) {
      onChange(result.value)
      say('success', published ? 'The Card is now a Draft again.' : 'The Card is now Published.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const problem = describeResourcesFailure(result.failure, 'card')
    say('error', problem.message, problem.items)
  }

  return (
    <Panel
      title="Publication"
      actions={<StateBadge state={card.state} />}
      description={
        published
          ? 'The Card is Published. Whether anyone sees it also depends on its Pack being Published.'
          : 'The Card is a Draft: only editors can see it.'
      }
    >
      <div className="flex flex-col gap-3">
        <FeedbackAlert feedback={feedback} />
        <p className="text-body text-muted-foreground">
          To be published, a {card.type === 'external_link' ? 'link' : card.type} Card needs{' '}
          {NEEDS[card.type]}.
        </p>
        <Button
          variant={published ? 'secondary' : 'primary'}
          pending={pending}
          pendingLabel={published ? 'Unpublishing…' : 'Publishing…'}
          className="self-start"
          onClick={() => {
            void change()
          }}
        >
          {published ? 'Unpublish Card' : 'Publish Card'}
        </Button>
      </div>
    </Panel>
  )
}
