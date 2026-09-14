import { http, HttpResponse } from 'msw'
import { setupServer } from 'msw/node'
import type { Item, User } from '../api'

/**
 * MSW rather than a global fetch stub, deliberately.
 *
 * api.ts reads res.status, res.ok and res.json(), and its 408 branch depends on fetch
 * rejecting with a DOMException named TimeoutError. A hand-rolled stub would have to fake
 * all of that, which means the tests would assert what we believe a Response does rather
 * than what one actually does — the test plan's own Risk #2 anti-pattern ("mocking the
 * transport so the real error shape is never exercised").
 */

export const TEST_USER: User = {
  id: 1,
  name: 'Test User',
  email: 'test@example.com',
}

/** Build an Item with the same shape the API returns — every field, none optional. */
export function makeItem(overrides: Partial<Item> = {}): Item {
  return {
    id: 1,
    title: 'an idea',
    note: null,
    bucket: 'inbox',
    dueDate: null,
    tags: null,
    context: null,
    important: null,
    urgent: null,
    delegatedTo: null,
    completedAt: null,
    createdAt: '2026-09-14T10:00:00+00:00',
    updatedAt: '2026-09-14T10:00:00+00:00',
    ...overrides,
  }
}

/**
 * Happy-path defaults. Tests that care about a failure override the one route they are
 * exercising with server.use(...), so each test declares only its own deviation.
 */
export const handlers = [
  http.get('*/api/me', () => HttpResponse.json(TEST_USER)),
  http.get('*/api/items', () => HttpResponse.json([])),
  http.post('*/api/items', async ({ request }) => {
    const body = (await request.json()) as { title: string; note?: string }

    return HttpResponse.json(
      makeItem({ id: 101, title: body.title, note: body.note ?? null }),
      { status: 201 },
    )
  }),
  http.post('*/api/logout', () => new HttpResponse(null, { status: 204 })),
]

export const server = setupServer(...handlers)
