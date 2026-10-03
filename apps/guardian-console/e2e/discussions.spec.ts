import { expect, test, type Page } from '@playwright/test'

import { apiFrom, signedInAs } from './support.ts'

// Guardian Discussions (ADR 0035, G2 Work Package 2), in real Chromium through the real gateway, as the person it is for: a
// Guardian (the seeded `plain-guardian` holds discussions.view and discussions.participate through the Guardian role, and
// nothing administrative). One journey through the whole life of a discussion, and the checks that only a real browser and a
// real server can make: a dialog's Escape and focus, a message someone else wrote, and what each capability is shown. The
// behaviour is proved in detail by the component tests; this is not the closeout (a demo dataset and the full suite are WP3).

const body = (page: Page) => page.locator('[data-page-width]')

/** A Guardian whose `/me` says they hold all but these capabilities. The server is untouched: this is only what the Console is TOLD. */
async function reportedAs(page: Page, drop: string[]): Promise<void> {
  const snapshot = (await apiFrom(page, 'GET', '/api/v1/me')).body as { capabilities: string[] }
  const reported = {
    ...snapshot,
    capabilities: snapshot.capabilities.filter((c) => !drop.includes(c)),
  }
  await page.route('**/api/v1/me', (route) => route.fulfill({ json: reported }))
}

test.describe('discussions', () => {
  test('a Guardian starts a discussion, replies, edits, resolves, reopens, removes their own message and finds it again', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    const tag = crypto.randomUUID().slice(0, 8)
    const title = `E2E Discussion ${tag}`
    const opening = `Opening words ${tag}`
    const first = `First reply ${tag}`
    const corrected = `First reply, corrected ${tag}`
    const second = `Second reply ${tag}`

    await guardian.goto('/')
    await guardian
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'Discussions' })
      .click()
    await expect(guardian.getByRole('heading', { level: 1, name: 'Discussions' })).toBeVisible()

    // Start: a title and the opening message, and nothing else.
    await body(guardian).getByRole('link', { name: 'Start a discussion' }).click()
    await guardian.getByRole('textbox', { name: 'Title', exact: true }).fill(title)
    await guardian.getByRole('textbox', { name: 'Opening message' }).fill(opening)
    await guardian.getByRole('button', { name: 'Start discussion' }).click()
    await expect(guardian.getByRole('heading', { level: 1, name: title })).toBeVisible()
    await expect(body(guardian).getByText('Opening message', { exact: true })).toBeVisible()
    await expect(body(guardian).getByText(opening)).toBeVisible()
    await expect(body(guardian).getByText('Open', { exact: true })).toBeVisible()

    // Reply: the form is one box, and the new message is the newest.
    await guardian.getByRole('textbox', { name: 'Your reply' }).fill(first)
    await guardian.getByRole('button', { name: 'Post reply' }).click()
    await expect(body(guardian).getByText(first)).toBeVisible()
    await expect(body(guardian).getByText('Reply posted.')).toBeVisible()
    await expect(guardian.getByRole('textbox', { name: 'Your reply' })).toHaveValue('')

    // Edit your own reply: it is marked as edited.
    await guardian.getByRole('button', { name: 'Edit your message 2' }).click()
    await guardian.getByRole('textbox', { name: 'Your message' }).fill(corrected)
    await guardian.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(body(guardian).getByText(corrected)).toBeVisible()
    await expect(guardian.getByRole('article', { name: /Message 2/ })).toContainText('Edited')

    // Resolve: no new replies. The form gives way to an explanation, and the server agrees.
    await guardian.getByRole('button', { name: 'Mark as resolved' }).click()
    await expect(body(guardian).getByText('Marked as resolved.')).toBeVisible()
    await expect(body(guardian).getByText(/Resolved by/)).toBeVisible()
    await expect(guardian.getByRole('textbox', { name: 'Your reply' })).toHaveCount(0)
    await expect(guardian.getByRole('region', { name: 'Replies are closed' })).toBeVisible()
    const id = new URL(guardian.url()).pathname.split('/').pop() ?? ''
    const refused = await apiFrom(guardian, 'POST', `/api/v1/admin/discussions/${id}/messages`, {
      body: 'Sneaking in',
    })
    expect(refused.status).toBe(409)
    expect((refused.body as { code: string }).code).toBe('discussion_resolved')

    // Resolved is not frozen: your own words can still be corrected, and so can the title.
    await guardian.getByRole('button', { name: 'Edit your message 2' }).click()
    await guardian
      .getByRole('textbox', { name: 'Your message' })
      .fill(`${corrected} (after resolving)`)
    await guardian.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(body(guardian).getByText(`${corrected} (after resolving)`)).toBeVisible()
    await guardian.getByRole('button', { name: 'Edit title' }).click()
    await guardian.getByRole('textbox', { name: 'Title', exact: true }).fill(`${title} (settled)`)
    await guardian.getByRole('button', { name: 'Save title' }).click()
    await expect(
      guardian.getByRole('heading', { level: 1, name: `${title} (settled)` }),
    ).toBeVisible()

    // Reopen: replies are possible again.
    await guardian.getByRole('button', { name: 'Reopen discussion' }).click()
    await expect(guardian.getByRole('textbox', { name: 'Your reply' })).toBeVisible()
    await guardian.getByRole('textbox', { name: 'Your reply' }).fill(second)
    await guardian.getByRole('button', { name: 'Post reply' }).click()
    await expect(body(guardian).getByText(second)).toBeVisible()

    // Remove your own message: Escape is the safe path and gives focus back; then do it.
    const remove = guardian.getByRole('button', { name: 'Remove your message 2' })
    await remove.click()
    const dialog = guardian.getByRole('dialog', { name: 'Remove your message?' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText('This cannot be undone.')
    await guardian.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(remove).toBeFocused()
    expect(
      (
        (await apiFrom(guardian, 'GET', `/api/v1/admin/discussions/${id}/messages`)).body as {
          data: { removed: boolean }[]
        }
      ).data.some((m) => m.removed),
    ).toBe(false)

    await remove.click()
    await guardian.getByRole('button', { name: 'Remove message' }).click()
    const tombstone = guardian.getByRole('article', { name: 'Message 2, removed' })
    await expect(tombstone).toBeVisible()
    await expect(tombstone).toContainText('This message was removed.')
    await expect(guardian.getByRole('article')).toHaveCount(3) // its place is kept
    await expect(body(guardian)).not.toContainText(corrected)
    // The server holds no text for it either: a tombstone has no body at all.
    const messages = (await apiFrom(guardian, 'GET', `/api/v1/admin/discussions/${id}/messages`))
      .body as { data: Record<string, unknown>[] }
    const gone = messages.data.find((m) => m.sequence === 2)
    expect(gone?.removed).toBe(true)
    expect('body' in (gone ?? {})).toBe(false)
    expect(JSON.stringify(messages)).not.toContain(corrected)

    // Find it again: the search is by TITLE only, and the state filter narrows the list.
    await guardian
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'Discussions' })
      .click()
    await guardian.getByRole('searchbox', { name: 'Search titles' }).fill(tag)
    await guardian.getByRole('button', { name: 'Search' }).click()
    const table = guardian.getByRole('table', { name: 'Discussions' })
    await expect(table.getByRole('link', { name: `${title} (settled)` })).toBeVisible()
    await guardian.getByRole('combobox', { name: 'Show' }).selectOption('resolved')
    await expect(guardian.getByText('No discussions match.')).toBeVisible()
    await guardian.getByRole('combobox', { name: 'Show' }).selectOption('open')
    await expect(table.getByRole('link', { name: `${title} (settled)` })).toBeVisible()
    await guardian.getByRole('combobox', { name: 'Show' }).selectOption('')
    await guardian.getByRole('searchbox', { name: 'Search titles' }).fill(second)
    await guardian.getByRole('button', { name: 'Search' }).click()
    await expect(guardian.getByText('No discussions match.')).toBeVisible() // message text is not searched

    await guardian.context().close()
  })

  test("someone else's words cannot be changed from here: no controls, and the server refuses a direct attempt", async ({
    browser,
    baseURL,
  }) => {
    const author = await signedInAs(browser, baseURL ?? '', 'admin-read')
    const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    const tag = crypto.randomUUID().slice(0, 8)
    await author.goto('/')
    const started = await apiFrom(author, 'POST', '/api/v1/admin/discussions', {
      title: `E2E Other ${tag}`,
      body: `Words by the administrator ${tag}`,
    })
    const id = (started.body as { id: string }).id
    const messageId =
      (
        (await apiFrom(author, 'GET', `/api/v1/admin/discussions/${id}/messages`)).body as {
          data: { id: string }[]
        }
      ).data[0]?.id ?? ''

    await guardian.goto(`/discussions/${id}`)
    await expect(
      guardian.getByRole('heading', { level: 1, name: `E2E Other ${tag}` }),
    ).toBeVisible()
    await expect(body(guardian).getByText(`Words by the administrator ${tag}`)).toBeVisible()
    // They may take part (reply, resolve) but nothing is offered on words that are not theirs, nor on the title.
    await expect(guardian.getByRole('textbox', { name: 'Your reply' })).toBeVisible()
    await expect(
      guardian.getByRole('button', { name: /Edit your message|Remove your message/ }),
    ).toHaveCount(0)
    await expect(guardian.getByRole('button', { name: 'Edit title' })).toHaveCount(0)
    await expect(guardian.getByRole('button', { name: 'Mark as resolved' })).toBeVisible()

    // Hiding a button is a courtesy: the server is what refuses.
    for (const attempt of [
      await apiFrom(guardian, 'PATCH', `/api/v1/admin/discussions/${id}/messages/${messageId}`, {
        body: 'Rewritten',
      }),
      await apiFrom(guardian, 'DELETE', `/api/v1/admin/discussions/${id}/messages/${messageId}`),
      await apiFrom(guardian, 'PATCH', `/api/v1/admin/discussions/${id}`, { title: 'Stolen' }),
    ]) {
      expect(attempt.status).toBe(403)
      expect((attempt.body as { code: string }).code).toBe('not_author')
    }
    await guardian.reload()
    await expect(body(guardian).getByText(`Words by the administrator ${tag}`)).toBeVisible()
    await expect(
      guardian.getByRole('heading', { level: 1, name: `E2E Other ${tag}` }),
    ).toBeVisible()

    await author.context().close()
    await guardian.context().close()
  })

  test('shows what each capability allows: view alone reads, no view reaches nothing', async ({
    browser,
    baseURL,
  }) => {
    const author = await signedInAs(browser, baseURL ?? '', 'admin-read')
    const tag = crypto.randomUUID().slice(0, 8)
    await author.goto('/')
    const started = await apiFrom(author, 'POST', '/api/v1/admin/discussions', {
      title: `E2E Capability ${tag}`,
      body: `Readable by anyone who may view ${tag}`,
    })
    const id = (started.body as { id: string }).id

    // View only: everything to read, nothing to change.
    const reader = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    await reader.goto('/')
    await reportedAs(reader, ['discussions.participate'])
    await reader.goto('/discussions')
    await expect(reader.getByRole('heading', { level: 1, name: 'Discussions' })).toBeVisible()
    await expect(reader.getByRole('link', { name: 'Start a discussion' })).toHaveCount(0)
    await reader.goto(`/discussions/${id}`)
    await expect(body(reader).getByText(`Readable by anyone who may view ${tag}`)).toBeVisible()
    await expect(reader.getByRole('textbox')).toHaveCount(0)
    await expect(
      reader.getByRole('button', { name: /Mark as resolved|Reopen|Edit|Remove|Post reply/ }),
    ).toHaveCount(0)

    // Participating alone does not read: the list, and a thread, are refused, and the section is not in the rail.
    const writer = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    await writer.goto('/')
    await reportedAs(writer, ['discussions.view'])
    await writer.goto('/discussions')
    await expect(writer.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    await writer.goto(`/discussions/${id}`)
    await expect(writer.getByRole('heading', { level: 1, name: 'Not permitted' })).toBeVisible()
    await expect(body(writer)).not.toContainText(tag)

    // Neither: no section at all.
    const nobody = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    await nobody.goto('/')
    await reportedAs(nobody, ['discussions.view', 'discussions.participate'])
    await nobody.goto('/')
    await expect(nobody.getByRole('navigation', { name: 'Console' })).toBeVisible()
    await expect(
      nobody
        .getByRole('navigation', { name: 'Console' })
        .getByRole('link', { name: 'Discussions' }),
    ).toHaveCount(0)

    for (const page of [author, reader, writer, nobody]) await page.context().close()
  })
})
