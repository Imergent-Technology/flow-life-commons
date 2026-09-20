import { Link } from 'react-router'

import { PageHeading } from '../ui/PageHeading.tsx'

export function NotFoundPage() {
  return (
    <div className="flex flex-col gap-3">
      <PageHeading title="Page not found" />
      <p className="text-slate-600">There is nothing at this address.</p>
      <Link to="/" className="self-start text-slate-700 underline">
        Back to the Console
      </Link>
    </div>
  )
}
