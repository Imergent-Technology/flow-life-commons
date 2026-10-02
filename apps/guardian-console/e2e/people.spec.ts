import { expect, test } from '@playwright/test'

import { signedInAs } from './support.ts'

// The People workflow (ADR 0034, G1 Work Package 4), in real Chromium through the real gateway, as the person it is for: a
// Guardian (the seeded `plain-guardian` holds crm.people.view and crm.people.manage through the Guardian role, and nothing
// administrative). One journey: add a person, find them, correct their profile, and manage their contact methods. This is the
// smallest browser coverage of the new routes; the behaviour is proved in detail by the component tests.

test.describe('people', () => {
  test('a Guardian adds a person, finds them, corrects their profile and manages their contact methods', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    const tag = crypto.randomUUID().slice(0, 8)
    const name = `E2E Person ${tag}`
    const email = `person.${tag}@example.org`
    const second = `work.${tag}@example.org`

    await guardian.goto('/')
    await guardian
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'People' })
      .click()
    await expect(guardian.getByRole('heading', { level: 1, name: 'People' })).toBeVisible()

    // Add: the ordinary path asks for little, and the new person has no account.
    await guardian.getByRole('link', { name: 'Add person' }).click()
    await guardian.getByLabel('Display name').fill(name)
    await guardian.getByLabel('Email').fill(email)
    await guardian.getByLabel('Affiliation').fill('E2E Guild')
    await guardian.getByRole('button', { name: 'Add person' }).click()
    await expect(guardian.getByRole('heading', { level: 1, name: 'Person added' })).toBeVisible()
    await expect(guardian.getByText('They have no account and no sign-in.')).toBeVisible()

    // Find: the directory's own search, by the contact method just recorded.
    await guardian
      .getByRole('navigation', { name: 'Console' })
      .getByRole('link', { name: 'People' })
      .click()
    await guardian.getByRole('searchbox', { name: 'Name, email or phone' }).fill(tag)
    await guardian.getByRole('button', { name: 'Search' }).click()
    const table = guardian.getByRole('table', { name: 'People' })
    await expect(table.getByRole('link', { name })).toBeVisible()
    await expect(table.getByText(email)).toBeVisible()
    await table.getByRole('link', { name }).click()
    await expect(guardian.getByRole('heading', { level: 1, name })).toBeVisible()
    await expect(guardian.getByText('E2E Guild')).toBeVisible()

    // Correct the profile: only what was changed is saved.
    await guardian.getByRole('button', { name: 'Edit profile' }).click()
    await guardian.getByLabel('Affiliation').fill('E2E Guild of Makers')
    await guardian.getByLabel('How we know them').fill('Met in the browser test')
    await guardian.getByRole('button', { name: 'Save profile' }).click()
    await expect(guardian.getByText('Saved.')).toBeVisible()
    await expect(guardian.getByText('E2E Guild of Makers')).toBeVisible()
    await expect(guardian.getByText('Met in the browser test')).toBeVisible()

    // Contact methods: the first email is primary; a second is added, promoted, and then the first is removed.
    const methods = guardian.getByRole('list', { name: 'Contact methods' })
    await expect(methods.getByText('Primary', { exact: true })).toHaveCount(1)
    await guardian.getByRole('button', { name: 'Add contact method' }).click()
    await guardian.getByLabel('Email or phone number').fill(second)
    await guardian.getByLabel('Label').fill('work')
    await guardian
      .getByRole('form', { name: 'Add contact method' })
      .getByRole('button', { name: 'Add contact method' })
      .click()
    await expect(guardian.getByText('Contact method added.')).toBeVisible()
    await expect(methods.getByRole('listitem')).toHaveCount(2)

    await guardian.getByRole('button', { name: `Make ${second} primary` }).click()
    await expect(guardian.getByText(`${second} is now the primary email.`)).toBeVisible()
    await expect(
      methods
        .getByRole('listitem')
        .filter({ hasText: second })
        .getByText('Primary', { exact: true }),
    ).toBeVisible()
    await expect(methods.getByText('Primary', { exact: true })).toHaveCount(1)

    await guardian.getByRole('button', { name: `Remove ${email}` }).click()
    await guardian.getByRole('dialog').getByRole('button', { name: 'Remove' }).click()
    await expect(guardian.getByText(`${email} was removed.`)).toBeVisible()
    await expect(methods.getByRole('listitem')).toHaveCount(1)

    // Nothing about the Person's Account, access or Membership is on the page: those belong to other screens.
    await expect(guardian.getByRole('main')).not.toContainText(/account|membership|password|role/i)
  })

  test('a registration that looks like someone already there is advice, and nothing is added until the Guardian says so', async ({
    browser,
    baseURL,
  }) => {
    const guardian = await signedInAs(browser, baseURL ?? '', 'plain-guardian')
    const name = `E2E Twin ${crypto.randomUUID().slice(0, 8)}`

    for (const attempt of [1, 2]) {
      await guardian.goto('/people/new')
      await guardian.getByLabel('Display name').fill(name)
      await guardian.getByRole('button', { name: 'Add person' }).click()
      if (attempt === 1) {
        await expect(
          guardian.getByRole('heading', { level: 1, name: 'Person added' }),
        ).toBeVisible()
      }
    }
    // The second, same-name registration was refused as possible duplicate; the form is still there and nothing was added.
    const advice = guardian.getByRole('list', { name: 'Possible duplicates' })
    await expect(advice.getByRole('link', { name })).toBeVisible()
    await expect(guardian.getByRole('heading', { level: 1, name: 'Person added' })).toHaveCount(0)

    await guardian.getByRole('button', { name: 'This is a different person: add anyway' }).click()
    await expect(guardian.getByRole('heading', { level: 1, name: 'Person added' })).toBeVisible()
  })
})
