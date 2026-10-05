import { Link } from 'react-router'

import {
  audienceLabel,
  describeResourcesFailure,
  isOrderMismatch,
  mediaTypeLabel,
  sizeLabel,
} from '../../admin/resourcesWording.ts'
import { reorderCards, type ManagedCardOutline, type ManagedPack } from '../../api/resources.ts'
import { buttonVariants } from '../../ui/button-variants.ts'
import { Panel } from '../../ui/Panel.tsx'
import { TextLink } from '../../ui/TextLink.tsx'
import { StateBadge, TypeBadge } from './badges.tsx'
import { ReorderList, type ReorderOutcome } from './ReorderList.tsx'

function audienceNote(card: ManagedCardOutline): string {
  return card.audienceMode === 'inherit'
    ? 'Inherits the Pack’s audiences'
    : `Narrowed to ${card.audiences.map(audienceLabel).join(', ')}`
}

/**
 * A Pack's Cards in the server's order, Drafts included. Each is opened to be edited; the order is changed here, by Move up and
 * Move down, and the new order is sent whole (`order_mismatch` if someone added, removed or moved a Card meanwhile, in which case
 * the Pack is read again). A Card's Type is shown and never changes: a different Type is a new Card.
 */
export function PackCardsSection({
  pack,
  onChange,
  onReload,
}: {
  pack: ManagedPack
  onChange: (next: ManagedPack) => void
  onReload: () => void
}) {
  const cards = pack.cards ?? []

  async function onReorder(ids: string[]): Promise<ReorderOutcome> {
    const result = await reorderCards(pack.id, ids)
    if (result.ok) {
      onChange(result.value)
      return { ok: true }
    }
    if (isOrderMismatch(result.failure)) onReload()
    return { ok: false, message: describeResourcesFailure(result.failure, 'card').message }
  }

  return (
    <Panel
      title="Cards"
      description="The Pack’s Cards, in the order they are shown."
      actions={
        <Link
          to={`/resources/packs/${pack.id}/cards/new`}
          className={buttonVariants({ variant: 'primary', size: 'sm' })}
        >
          Add a Card
        </Link>
      }
    >
      {cards.length === 0 ? (
        <p className="text-body text-muted-foreground">
          This Pack has no Cards yet. Add one: a Pack needs a Published Card before it can be
          published.
        </p>
      ) : (
        <ReorderList
          label="Cards"
          onReorder={onReorder}
          items={cards.map((card) => ({
            id: card.id,
            name: card.title,
            children: (
              <>
                <TextLink
                  to={`/resources/packs/${pack.id}/cards/${card.id}`}
                  className="font-medium wrap-anywhere text-foreground decoration-border-strong hover:text-foreground hover:decoration-current"
                >
                  {card.title}
                </TextLink>
                <span className="flex flex-wrap items-center gap-2">
                  <TypeBadge type={card.type} />
                  <StateBadge state={card.state} />
                </span>
                <span className="text-meta wrap-anywhere text-muted-foreground">
                  {audienceNote(card)}
                  {card.file !== null
                    ? ` · ${card.file.name} (${mediaTypeLabel(card.file.mediaType)}, ${sizeLabel(card.file.byteSize)})`
                    : ''}
                  {card.type === 'external_link' && card.uri !== null ? ` · ${card.uri}` : ''}
                </span>
              </>
            ),
          }))}
        />
      )}
    </Panel>
  )
}
