import { Page } from '../ui/Page.tsx'
import { PageHeader } from '../ui/PageHeader.tsx'
import { TextLink } from '../ui/TextLink.tsx'

export function NotFoundPage() {
  return (
    <Page width="prose">
      <PageHeader title="Page not found" description="There is nothing at this address." />
      <TextLink to="/" className="self-start">
        Back to the Console
      </TextLink>
    </Page>
  )
}
