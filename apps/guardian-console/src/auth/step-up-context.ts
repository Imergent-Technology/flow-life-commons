import { createContext, useContext } from 'react'

/**
 * Recent verification ("step-up"): a sensitive action is refused with `verification_required` until the person has proved
 * their password AND a second factor recently. `request` opens the proof prompt and resolves `true` once they have proved
 * it, `false` if they cancelled or the prompt was dismissed. It NEVER repeats the action that was refused: the caller
 * tells the person to confirm again, so nothing happens on a click made before the prompt.
 */
export interface StepUp {
  request: () => Promise<boolean>
}

export const StepUpContext = createContext<StepUp | null>(null)

export function useStepUp(): StepUp {
  const value = useContext(StepUpContext)
  if (value === null) throw new Error('useStepUp must be used inside <StepUpProvider>.')
  return value
}
