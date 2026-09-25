import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { expectNoAxeViolations } from '../test/a11y.ts'
import { Alert } from './Alert.tsx'
import { Badge } from './Badge.tsx'
import { Button } from './Button.tsx'
import { Checkbox } from './Checkbox.tsx'
import { ConfirmDialog } from './ConfirmDialog.tsx'
import {
  DataTable,
  DataTableCell,
  DataTableHead,
  DataTableHeaderCell,
  DataTableRow,
  DataTableRowHeader,
} from './DataTable.tsx'
import { EmptyState } from './EmptyState.tsx'
import { Field } from './Field.tsx'
import { Input } from './Input.tsx'
import { Page } from './Page.tsx'
import { PageHeader } from './PageHeader.tsx'
import { Pagination } from './Pagination.tsx'
import { Panel } from './Panel.tsx'
import { Property, PropertyList } from './PropertyList.tsx'
import { Select } from './Select.tsx'
import { Skeleton, SkeletonRegion, SkeletonText } from './Skeleton.tsx'

/** One of everything, in the states that matter to a screen reader. */
function Gallery() {
  return (
    <Page width="detail">
      <PageHeader
        title="Members"
        status={<Badge variant="success">Active</Badge>}
        description="Everyone who has held a membership."
        action={<Button variant="primary">Add member</Button>}
      />
      <Alert tone="error">Something failed.</Alert>
      <Alert tone="info">A note.</Alert>
      <Panel title="Details" description="About this member" actions={<Button>Edit</Button>}>
        <PropertyList>
          <Property term="Email">ada@example.org</Property>
          <Property term="Id" mono>
            01J0ABCDEF
          </Property>
        </PropertyList>
      </Panel>
      <Panel title="Danger zone" tone="danger">
        <Button variant="danger">Disable account</Button>
      </Panel>
      <form className="flex flex-col gap-3">
        <Field label="Name" hint="As on your passport">
          {(control) => <Input {...control} />}
        </Field>
        <Field label="Email" error="Enter a valid email.">
          {(control) => <Input {...control} type="email" />}
        </Field>
        <Field label="Role">
          {(control) => (
            <Select {...control}>
              <option>Guardian</option>
            </Select>
          )}
        </Field>
        <Checkbox label="Send an invitation" hint="They will get an email." />
        <Button type="submit" variant="primary" pending pendingLabel="Saving…">
          Save
        </Button>
      </form>
      <DataTable caption="Members">
        <DataTableHead>
          <tr>
            <DataTableHeaderCell>Name</DataTableHeaderCell>
            <DataTableHeaderCell>State</DataTableHeaderCell>
          </tr>
        </DataTableHead>
        <tbody>
          <DataTableRow>
            <DataTableRowHeader>
              <a href="/m/1">Ada</a>
            </DataTableRowHeader>
            <DataTableCell>
              <Badge variant="warning">Invited</Badge>
            </DataTableCell>
          </DataTableRow>
        </tbody>
      </DataTable>
      <Pagination
        page={2}
        lastPage={3}
        total={60}
        noun={{ one: 'member', other: 'members' }}
        onPageChange={vi.fn()}
      />
      <EmptyState title="No members yet" action={<a href="/new">Add a member</a>}>
        Add someone to get started.
      </EmptyState>
      <SkeletonRegion label="Loading members…">
        <Skeleton className="h-8 w-40" />
        <SkeletonText />
      </SkeletonRegion>
    </Page>
  )
}

afterEach(() => {
  delete document.documentElement.dataset.theme
})

describe.each(['light', 'dark'] as const)('the primitives, in the %s theme', (theme) => {
  it('pass axe together', async () => {
    document.documentElement.dataset.theme = theme
    const { container } = render(<Gallery />)
    await expectNoAxeViolations(container)
  })

  it('pass axe as a confirmation dialog', async () => {
    document.documentElement.dataset.theme = theme
    render(
      <ConfirmDialog
        title="Disable this account?"
        confirmLabel="Disable"
        destructive
        onConfirm={() => Promise.resolve({ kind: 'done' })}
        onCancel={vi.fn()}
      >
        They will be signed out.
      </ConfirmDialog>,
    )
    expect(screen.getByRole('dialog')).toBeVisible()
    await expectNoAxeViolations(document.body)
  })
})

/** React's `useId` numbers differ between renders; nothing else should. */
const withoutGeneratedIds = (html: string) => html.replaceAll(/_r_[a-z0-9]+_/g, 'ID')

describe('theme independence', () => {
  it('renders identical markup in both themes: the difference is in the tokens, not the components', () => {
    document.documentElement.dataset.theme = 'light'
    const light = withoutGeneratedIds(render(<Gallery />).container.innerHTML)
    document.body.innerHTML = ''
    document.documentElement.dataset.theme = 'dark'
    const dark = withoutGeneratedIds(render(<Gallery />).container.innerHTML)
    expect(dark).toBe(light)
    expect(light.length).toBeGreaterThan(1000)
  })
})
