import { useEffect, useRef } from 'react'

/**
 * After a submission is refused with messages beside particular fields, keyboard focus goes to the first of those fields (the one
 * marked `aria-invalid`), so a person is taken to what needs attention instead of being left on a button that has just stopped
 * being pressable. `attempt` changes with every refusal; the form gets the returned ref. A refusal with no field message is the
 * alert's business (it takes focus itself).
 */
export function useFocusFirstInvalid(attempt: number | undefined) {
  const form = useRef<HTMLFormElement>(null)
  useEffect(() => {
    if (attempt === undefined) return
    form.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }, [attempt])
  return form
}
