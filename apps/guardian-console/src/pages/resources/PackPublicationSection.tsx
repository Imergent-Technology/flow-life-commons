import { useState } from 'react'

import { describeResourcesFailure } from '../../admin/resourcesWording.ts'
import { setPackPublication, type ManagedPack } from '../../api/resources.ts'
import { Button } from '../../ui/Button.tsx'
import { Panel } from '../../ui/Panel.tsx'
import { StatusIcon } from '../../ui/StatusIcon.tsx'
import { StateBadge } from './badges.tsx'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { useFeedback } from './useFeedback.ts'

/**
 * Publishing a Pack, and what publishing asks of it (ADR 0037, decisions 12-14). Publish and Unpublish are reversible and need
 * no recent verification. A Pack must have a Category, an audience and a Published Card to be Published, and must keep them while
 * it is: the list below SHOWS those facts about this Pack, but only the server decides, and it names what is missing when it
 * refuses. Nothing here publishes a Card, picks a Category or adds an audience on the person's behalf.
 */
export function PackPublicationSection({
  pack,
  onChange,
}: {
  pack: ManagedPack
  onChange: (next: ManagedPack) => void
}) {
  const [pending, setPending] = useState(false)
  const { feedback, say, clear } = useFeedback()
  const published = pack.state === 'published'

  async function change() {
    setPending(true)
    clear()
    const result = await setPackPublication(pack.id, published ? 'unpublish' : 'publish')
    setPending(false)
    if (result.ok) {
      onChange(result.value)
      say('success', published ? 'The Pack is now a Draft again.' : 'The Pack is now Published.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const problem = describeResourcesFailure(result.failure)
    say('error', problem.message, problem.items)
  }

  const needs: { met: boolean; text: string }[] = [
    {
      met: pack.category !== null,
      text: pack.category !== null ? `A Category: ${pack.category.name}` : 'A Category: not chosen',
    },
    {
      met: pack.audiences.length > 0,
      text:
        pack.audiences.length > 0
          ? 'At least one audience: chosen'
          : 'At least one audience: none chosen',
    },
    {
      met: pack.publishedCardCount > 0,
      text: `At least one Published Card: ${String(pack.publishedCardCount)} of ${String(pack.cardCount)} published`,
    },
  ]

  return (
    <Panel
      title="Publication"
      actions={<StateBadge state={pack.state} />}
      description={
        published
          ? 'The Pack is Published to its audiences.'
          : 'The Pack is a Draft: only editors can see it.'
      }
    >
      <div className="flex flex-col gap-3">
        <FeedbackAlert feedback={feedback} />
        <div>
          <p className="text-label font-medium text-foreground">A Published Pack needs</p>
          <ul className="mt-1 flex flex-col gap-1 text-body">
            {needs.map((need) => (
              <li key={need.text} className="flex items-start gap-2">
                <StatusIcon
                  kind={need.met ? 'success' : 'warning'}
                  className="mt-0.5 size-4 shrink-0"
                />
                <span className="wrap-anywhere">
                  <span className="sr-only">{need.met ? 'Met: ' : 'Not yet: '}</span>
                  {need.text}
                </span>
              </li>
            ))}
          </ul>
        </div>
        <Button
          variant={published ? 'secondary' : 'primary'}
          pending={pending}
          pendingLabel={published ? 'Unpublishing…' : 'Publishing…'}
          onClick={() => {
            void change()
          }}
          className="self-start"
        >
          {published ? 'Unpublish Pack' : 'Publish Pack'}
        </Button>
      </div>
    </Panel>
  )
}
