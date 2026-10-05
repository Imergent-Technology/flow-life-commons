import { useState } from 'react'

export interface Feedback {
  tone: 'success' | 'error' | 'warning' | 'info'
  text: string
  /** When a refusal names several things (what a Pack still needs), those things. */
  items: string[]
  attempt: number
}

/**
 * The outcome of the last thing a person did on a screen, shown by `FeedbackAlert`. Each outcome is a fresh alert (keyed by
 * `attempt`) that takes keyboard focus: nearly every action here disables or replaces the control that was pressed, and focus on a
 * control that has gone falls to the top of the page. Saying it where the person is, and announcing it, is the whole point.
 */
export function useFeedback(): {
  feedback: Feedback | null
  say: (tone: Feedback['tone'], text: string, items?: readonly string[]) => void
  clear: () => void
} {
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  return {
    feedback,
    say: (tone, text, items = []) => {
      setFeedback((previous) => ({
        tone,
        text,
        items: [...items],
        attempt: (previous?.attempt ?? 0) + 1,
      }))
    },
    clear: () => {
      setFeedback(null)
    },
  }
}
