import { expect, test } from '@playwright/test'

import { aMemberId, apiFrom, signedInAs } from './support.ts'

/**
 * e2e/support.ts's own helpers, where no real journey already exercises their less common path.
 *
 * `aMemberId`'s fallback — creating a Member when the list comes back empty — is one of those: the
 * development database almost always already has a Member from an earlier run, so nothing else in this
 * suite forces that branch. Forced here, deterministically, by intercepting only the LIST call; the
 * CREATE call beneath it is real, against the real backend, so this proves the fallback against the
 * real contract (openapi.yaml's `registerMember` operation names `Member`, not a page of them, as its
 * 201 schema; `MembershipOpenApiContractTest` asserts a real `POST /admin/members` body matches `Member`
 * directly) rather than a second mock layered on a mock. The grant it creates is revoked once the id is
 * confirmed, the same tidying `membership.spec.ts`'s own grant test already does — the Person record
 * itself is permanent either way (ADR 0028: Persons are never deleted), exactly as every other e2e
 * fixture that registers one already leaves behind.
 */
test.describe('aMemberId', () => {
  test("creates a Member when none are listed, and returns THAT Member's own Person id", async ({
    browser,
    baseURL,
  }) => {
    const admin = await signedInAs(browser, baseURL ?? '', 'admin-read')
    await admin.goto('/') // apiFrom evaluates a same-origin fetch; the minted session starts at about:blank

    await admin.route('**/api/v1/admin/members?per_page=1', (route) =>
      route.fulfill({
        json: { data: [], meta: { page: 1, per_page: 1, total: 0, last_page: 0 } },
      }),
    )

    const id = await aMemberId(admin)

    // A real Person id (a lowercase ULID, as every route in this codebase accepts), not `undefined`
    // stringified or a TypeError thrown reading a `data` wrapper the real response never had.
    expect(id).toMatch(/^[0-7][0-9a-hjkmnp-tv-z]{25}$/)
    const record = await apiFrom(admin, 'GET', `/api/v1/admin/members/${id}`)
    expect(record.status).toBe(200)
    const member = record.body as {
      person: { display_name: string }
      grants: { id: string }[]
    }
    expect(member.person.display_name).toBe('E2E Accessibility Member')

    // Tidy the grant this run created; the Person record persists (ADR 0028), exactly as every other
    // fixture that registers one already leaves behind.
    const grantId = member.grants[0]?.id
    if (grantId !== undefined) {
      await apiFrom(admin, 'POST', `/api/v1/admin/membership-grants/${grantId}/revoke`)
    }
  })
})
