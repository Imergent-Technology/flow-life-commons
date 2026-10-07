import { Link } from 'react-router'

import type { DeliveredCard } from '../../api/resourceLibrary.ts'
import { buttonVariants } from '../../ui/button-variants.ts'
import { cn, focusRing } from '../../ui/cn.ts'
import { Tooltip } from '../../ui/Tooltip.tsx'

/** The address of one Card of the Pack being read: the same page, with that Card chosen. It is a delivered Card's own id, nothing hidden. */
const cardLocation = (cardId: string): string => `?card=${encodeURIComponent(cardId)}`

/**
 * Direct navigation for a Pack with more than one Card the viewer can see: each Card is a link labelled by its title, in the order
 * delivered, numbered among the Cards delivered (so a Card the viewer cannot see leaves no gap). The Card being read is marked with
 * `aria-current`, and visibly by weight and a heavier edge as well as colour. A Card's summary is its help text: it appears on hover
 * AND on keyboard focus, and is the link's description for a screen reader; nothing needed to navigate is only in it.
 */
export function CardNavigation({
  cards,
  currentId,
}: {
  cards: readonly DeliveredCard[]
  currentId: string
}) {
  return (
    <nav aria-label="Cards in this Resource">
      <ol className="flex list-decimal flex-col gap-1.5 pl-6 marker:text-muted-foreground">
        {cards.map((card) => {
          const current = card.id === currentId
          return (
            <li key={card.id} className="pl-1">
              <Tooltip text={card.summary}>
                {(describedBy) => (
                  <Link
                    to={cardLocation(card.id)}
                    aria-current={current ? 'true' : undefined}
                    {...(describedBy !== undefined && { 'aria-describedby': describedBy })}
                    className={cn(
                      'block rounded-xs border-l-4 py-1 pl-2.5 text-body wrap-anywhere text-foreground hover:bg-muted',
                      focusRing,
                      current
                        ? 'border-primary bg-muted font-semibold'
                        : 'border-transparent font-normal',
                    )}
                  >
                    {card.title}
                  </Link>
                )}
              </Tooltip>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

/**
 * Previous and Next, for a Series of more than one visible Card. They move between the Cards the viewer can see, so a hidden Card is
 * never stepped over visibly and never a gap. At an end the control that has nowhere to go is not drawn (not a dead button). The
 * position says "Card 2 of 5" over the Cards delivered.
 */
export function SeriesControls({
  cards,
  currentId,
}: {
  cards: readonly DeliveredCard[]
  currentId: string
}) {
  const at = cards.findIndex((card) => card.id === currentId)
  const previous = at > 0 ? cards[at - 1] : undefined
  const next = at >= 0 ? cards[at + 1] : undefined

  return (
    <nav
      aria-label="Series"
      className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-border pt-4"
    >
      {previous !== undefined ? (
        <Link
          to={cardLocation(previous.id)}
          aria-label={`Previous Card: ${previous.title}`}
          className={buttonVariants({})}
        >
          Previous
        </Link>
      ) : null}
      <p className="text-meta text-muted-foreground">
        Card {at + 1} of {cards.length}
      </p>
      {next !== undefined ? (
        <Link
          to={cardLocation(next.id)}
          aria-label={`Next Card: ${next.title}`}
          className={cn(buttonVariants({ variant: 'primary' }), 'ml-auto')}
        >
          Next
        </Link>
      ) : null}
    </nav>
  )
}
