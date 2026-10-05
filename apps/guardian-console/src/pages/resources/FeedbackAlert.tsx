import { Alert } from '../../ui/Alert.tsx'
import type { Feedback } from './useFeedback.ts'

/** The outcome of the last action (see `useFeedback`): announced, and holding keyboard focus. */
export function FeedbackAlert({ feedback }: { feedback: Feedback | null }) {
  if (feedback === null) return null
  return (
    <Alert key={feedback.attempt} tone={feedback.tone} focusOnMount>
      <p className="wrap-anywhere">{feedback.text}</p>
      {feedback.items.length > 0 ? (
        <ul className="mt-1 list-disc pl-5">
          {feedback.items.map((item) => (
            <li key={item} className="wrap-anywhere">
              {item}
            </li>
          ))}
        </ul>
      ) : null}
    </Alert>
  )
}

/**
 * A line the previous screen left (see `useNotice`), as a status. It can carry a title the person gave something, which may be one
 * long unbroken word, so it wraps anywhere rather than widening the page.
 */
export function Notice({ text }: { text: string | null }) {
  if (text === null) return null
  return (
    <Alert tone="success">
      <span className="wrap-anywhere">{text}</span>
    </Alert>
  )
}
