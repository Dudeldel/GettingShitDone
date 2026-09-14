import { delay, http, HttpResponse } from 'msw'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
  ApiError,
  DEFAULT_REQUEST_TIMEOUT_MS,
  listItems,
  setRequestTimeoutForTests,
} from '../api'
import { server } from './server'

// Against the real ten-second default this file was ~91% of the whole suite's wall time,
// with only 50% headroom before a slow runner would report it as a broken 408 mapping
// rather than as slowness. The assertions below are unchanged; only the clock is.
beforeEach(() => {
  setRequestTimeoutForTests(50)
})

afterEach(() => {
  setRequestTimeoutForTests(DEFAULT_REQUEST_TIMEOUT_MS)
})

/**
 * Settles research Open Question #1: can the 408 branch of api.ts be tested here?
 *
 * api.ts maps a timed-out request to a 408 ApiError with:
 *
 *     if (err instanceof DOMException && err.name === 'TimeoutError') { ... }
 *
 * Probed on jsdom + Node 22 (local) / Node 20 (CI): the rejection carries the right
 * `name`, but `instanceof DOMException` is FALSE — jsdom installs its own DOMException
 * global while the rejection originates in Node's realm, so the cross-realm instanceof
 * check does not hold. In a browser there is one realm and it does hold, so this is a
 * test-environment limitation, not a production defect.
 *
 * These two tests pin both halves so the next reader does not have to rediscover it:
 * the platform behaviour the app relies on, and the realm caveat that shapes how the
 * timeout path can be asserted.
 */
describe('AbortSignal.timeout in this environment', () => {
  it('rejects a timed-out fetch with an error named TimeoutError', async () => {
    server.use(
      http.get('*/api/never-resolves', async () => {
        await delay('infinite')

        return HttpResponse.json(null)
      }),
    )

    const attempt = fetch('/api/never-resolves', { signal: AbortSignal.timeout(20) })

    await expect(attempt).rejects.toSatisfy(
      (err: unknown) => err instanceof Error && err.name === 'TimeoutError',
    )
  })

  it('surfaces a timeout through the real client as a 408 ApiError', async () => {
    server.use(
      http.get('*/api/items', async () => {
        await delay('infinite')

        return HttpResponse.json([])
      }),
    )

    // Whether this resolves to a 408 ApiError or to the raw rejection is exactly what
    // decides how Phase 3 asserts the timeout path. Asserted against the real client, not
    // against a belief about what the platform throws.
    await expect(listItems()).rejects.toSatisfy(
      (err: unknown) => err instanceof ApiError && err.status === 408,
    )
  })
})
