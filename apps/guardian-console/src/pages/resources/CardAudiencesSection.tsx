import { useId, useState, type SyntheticEvent } from 'react'

import { audienceLabel, describeResourcesFailure } from '../../admin/resourcesWording.ts'
import {
  setCardAudiences,
  type Audience,
  type AudienceMode,
  type ManagedCard,
} from '../../api/resources.ts'
import { Panel } from '../../ui/Panel.tsx'
import { SubmitButton } from '../../ui/SubmitButton.tsx'
import { AudienceFields } from './AudienceFields.tsx'
import { FeedbackAlert } from './FeedbackAlert.tsx'
import { useFeedback } from './useFeedback.ts'

const same = (a: readonly Audience[], b: readonly Audience[]) =>
  a.length === b.length && a.every((each) => b.includes(each))

/**
 * Who a Card is for (ADR 0037, decisions 40-41). A Card either INHERITS its Pack's audiences, following any later change to them,
 * or is NARROWED to a fixed subset of them. It can never be broader than its Pack, so only the Pack's own audiences are offered
 * to narrow to; and the server holds that rule regardless, refusing anything else, so what it says is shown.
 */
export function CardAudiencesSection({
  card,
  packAudiences,
  onChange,
}: {
  card: ManagedCard
  packAudiences: readonly Audience[]
  onChange: (next: ManagedCard) => void
}) {
  const name = useId()
  const [mode, setMode] = useState<AudienceMode>(card.audienceMode)
  const [chosen, setChosen] = useState<Audience[]>(() => [...card.audiences])
  const [pending, setPending] = useState(false)
  const { feedback, say, clear } = useFeedback()
  const noneToNarrowTo = packAudiences.length === 0

  async function save() {
    const unchanged =
      mode === card.audienceMode && (mode === 'inherit' || same(chosen, card.audiences))
    if (unchanged) {
      say('info', 'There is nothing to save: the audience has not changed.')
      return
    }
    setPending(true)
    clear()
    const result = await setCardAudiences(
      card.packId,
      card.id,
      mode,
      mode === 'inherit' ? [] : chosen,
    )
    setPending(false)
    if (result.ok) {
      onChange(result.value)
      setMode(result.value.audienceMode)
      setChosen([...result.value.audiences])
      say('success', 'The Card’s audience was saved.')
      return
    }
    if (result.failure.kind === 'unauthenticated') return
    const problem = describeResourcesFailure(result.failure, 'card')
    say('error', problem.message, problem.items)
  }

  return (
    <Panel
      title="Audience"
      description="A Card can be for its Pack’s whole audience, or for part of it. It can never reach beyond its Pack."
    >
      <form
        aria-label="Card audience"
        onSubmit={(event: SyntheticEvent) => {
          event.preventDefault()
          void save()
        }}
        className="flex flex-col gap-3"
      >
        <FeedbackAlert feedback={feedback} />
        <fieldset className="flex flex-col gap-2">
          <legend className="text-label font-medium text-foreground">Who sees this Card</legend>
          <label className="flex items-start gap-2.5 text-body text-foreground">
            <input
              type="radio"
              name={name}
              checked={mode === 'inherit'}
              onChange={() => {
                setMode('inherit')
              }}
              className="mt-0.5 size-4 shrink-0 accent-primary"
            />
            <span>
              Everyone the Pack is for
              <span className="block text-meta text-muted-foreground">
                Currently:{' '}
                {packAudiences.length === 0
                  ? 'the Pack has no audience yet'
                  : packAudiences.map(audienceLabel).join(', ')}
                . This follows later changes to the Pack’s audiences.
              </span>
            </span>
          </label>
          <label className="flex items-start gap-2.5 text-body text-foreground">
            <input
              type="radio"
              name={name}
              checked={mode === 'narrowed'}
              disabled={noneToNarrowTo && card.audienceMode !== 'narrowed'}
              onChange={() => {
                setMode('narrowed')
              }}
              className="mt-0.5 size-4 shrink-0 accent-primary"
            />
            <span>
              Only part of that audience
              <span className="block text-meta text-muted-foreground">
                {noneToNarrowTo
                  ? 'Choose the Pack’s audiences first.'
                  : 'A fixed choice that does not grow when the Pack’s audiences do.'}
              </span>
            </span>
          </label>
        </fieldset>
        {mode === 'narrowed' ? (
          <AudienceFields
            legend="Narrowed to"
            options={packAudiences}
            value={chosen}
            onChange={setChosen}
          />
        ) : null}
        <SubmitButton pending={pending} pendingLabel="Saving…">
          Save audience
        </SubmitButton>
      </form>
    </Panel>
  )
}
