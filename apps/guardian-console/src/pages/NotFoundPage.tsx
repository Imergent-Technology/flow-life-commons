import { useCurrentAccount } from '../auth/auth-context.ts'
import { defaultDestination } from '../auth/destination.ts'
import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { TextLink } from '../ui/TextLink.tsx'

/**
 * Reached under both `RequireAuthentication` branches — an unmatched `/*` inside `/my/*` and inside the
 * Console — so a signed-in Account is always available here. Neutral wording and `defaultDestination`
 * (never a hard-coded `/`): a Member reading "Back to the Console" would be sent somewhere it is not,
 * and Guardian-specific wording on a page any signed-in Account can reach is exactly what ADR 0032 rules
 * out (see `guardrails.test.ts`).
 */
export function NotFoundPage() {
  const current = useCurrentAccount()

  return (
    <Page width="prose">
      <PageHeader title="Page not found" description="There is nothing at this address." />
      <TextLink to={defaultDestination(current)} className="self-start">
        Back to home
      </TextLink>
    </Page>
  )
}
