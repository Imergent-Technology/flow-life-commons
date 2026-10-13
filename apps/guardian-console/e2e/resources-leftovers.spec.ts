import { expect, test } from '@playwright/test'

import { ROOT } from './resources.ts'
import { CATEGORY, PACK } from './resourcesDemo.ts'
import { apiFrom } from './support.ts'
import { workerSession } from './verified.ts'

// After the whole suite, the development database holds the demo's Resources and nothing a journey made (G10 WP7). Every Resources
// journey removes exactly what it registered (`RemoveAfter`, which fails loudly when it cannot), so anything else here is a leak: a
// journey that registered nothing, or a cleanup that was cut off. It runs in the serial maintenance pass, which `./flow test e2e` starts
// only after every other journey has finished. A Pack or Category a person made by hand in their own development database is reported
// too: the check is exact, by design, and names what it found.

test('@maintenance the suite leaves exactly the demo Resources behind: every demo Pack and Category, and nothing else', async ({
  browser,
}) => {
  const session = workerSession(browser)
  const page = await session.ready()
  await expect.poll(async () => (await apiFrom(page, 'GET', '/api/v1/health')).status).toBe(200)

  const titles: string[] = []
  for (let pageNumber = 1; ; pageNumber++) {
    const listed = await session.request(
      'GET',
      `${ROOT}/packs?per_page=100&page=${String(pageNumber)}`,
    )
    expect(listed.status, JSON.stringify(listed.body)).toBe(200)
    const body = listed.body as { data: { title: string }[]; meta: { last_page: number } }
    titles.push(...body.data.map((p) => p.title))
    if (pageNumber >= body.meta.last_page) break
  }
  const categories = await session.request('GET', `${ROOT}/categories`)
  expect(categories.status).toBe(200)
  const names = (categories.body as { data: { name: string }[] }).data.map((c) => c.name)

  expect([...titles].sort(), 'Packs left behind').toEqual([...Object.values(PACK)].sort())
  expect([...names].sort(), 'Categories left behind').toEqual([...Object.values(CATEGORY)].sort())
})
