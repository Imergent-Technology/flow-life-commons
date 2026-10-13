import { audienceLabel } from '../../../admin/resourcesWording.ts'
import { shown } from '../../../admin/time.ts'
import type { Audience } from '../../../api/audiences.ts'
import type { ViewerEditor } from '../../../api/resourceLibrary.ts'

// What the privileged viewer is told about HOW a Resource was written: whom it is aimed at, how many Cards it has and who last edited
// it. These are facts about the authoring, not about the person reading: a holder of `resources.view` reads every Pack whatever it
// is aimed at (ADR 0038, R16), so the wording says "For Guardians", never "visible only to Guardians" and never "you".

/** "For Guardians, Members": whom something is aimed at. None is said, not left blank. */
export function forAudiences(audiences: readonly Audience[]): string {
  return audiences.length === 0
    ? 'Not aimed at anyone yet'
    : `For ${audiences.map(audienceLabel).join(', ')}`
}

/** "Last edited by Hone Guardian on 12 Oct 2026, 9:00 am", or without the name where the platform gave none. */
export function editedBy(editor: ViewerEditor, updatedAt: string): string {
  const when = shown(updatedAt)
  return editor.displayName === null || editor.displayName === ''
    ? `Last edited ${when}`
    : `Last edited by ${editor.displayName} on ${when}`
}

const plural = (count: number, one: string, other: string): string =>
  `${String(count)} ${count === 1 ? one : other}`

/** "3 Cards, 1 Draft Card" for a Pack the viewer reads whole. An empty Pack says so. */
export function cardCounts(cardCount: number, draftCardCount: number): string {
  if (cardCount === 0) return 'No Cards yet'
  const cards = plural(cardCount, 'Card', 'Cards')
  return draftCardCount === 0
    ? cards
    : `${cards}, ${plural(draftCardCount, 'Draft Card', 'Draft Cards')}`
}
