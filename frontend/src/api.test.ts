import { http, HttpResponse } from 'msw'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, clearToken, getToken, me, setToken } from './api'
import { server, TEST_USER } from './test/server'

/**
 * The token accessors must survive storage that throws rather than returns null.
 *
 * This is not hypothetical: getToken() runs inside AuthProvider's useState initializer,
 * during render and outside any try, with no error boundary above it — so an unguarded
 * throw white-screens the entire app at boot. Safari's "Block All Cookies", a partitioned
 * third-party context and a full quota all produce it.
 *
 * vi.stubGlobal, not vi.spyOn: jsdom wraps Storage in a Proxy that silently refuses a spy.
 */
function denyStorage() {
  vi.stubGlobal('localStorage', {
    getItem: () => {
      throw new DOMException('SecurityError')
    },
    setItem: () => {
      throw new DOMException('SecurityError')
    },
    removeItem: () => {
      throw new DOMException('SecurityError')
    },
    clear: () => {},
    key: () => null,
    length: 0,
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  clearToken()
})

describe('the token accessors when the browser blocks site data', () => {
  it('reads without throwing', () => {
    denyStorage()

    expect(() => getToken()).not.toThrow()
  })

  it('writes without throwing, and the token still works for this session', () => {
    denyStorage()

    expect(() => setToken('a-token')).not.toThrow()
    // The in-memory fallback is what keeps the user signed in until they reload.
    expect(getToken()).toBe('a-token')
  })

  it('clears without throwing', () => {
    denyStorage()
    setToken('a-token')

    expect(() => clearToken()).not.toThrow()
    expect(getToken()).toBeNull()
  })
})

describe('the token accessors when storage works', () => {
  it('round-trips through real storage', () => {
    setToken('stored-token')

    expect(localStorage.getItem('gsd_token')).toBe('stored-token')
    expect(getToken()).toBe('stored-token')

    clearToken()
    expect(getToken()).toBeNull()
  })
})

/**
 * Nothing else in the suite inspects the Authorization header, so removing it from
 * api.ts entirely used to leave all 20 tests green — including in the file whose whole
 * subject is the auth flow. These two tests are what make an authenticated request
 * distinguishable from an anonymous one.
 */
describe('the bearer token on the wire', () => {
  it('is attached when a token is stored', async () => {
    setToken('a-real-token')
    let seen: string | null = null
    server.use(
      http.get('*/api/me', ({ request }) => {
        seen = request.headers.get('authorization')

        return HttpResponse.json(TEST_USER)
      }),
    )

    await me()

    expect(seen).toBe('Bearer a-real-token')
  })

  it('is omitted when there is no token', async () => {
    let seen: string | null = 'unset'
    server.use(
      http.get('*/api/me', ({ request }) => {
        seen = request.headers.get('authorization')

        return HttpResponse.json(TEST_USER)
      }),
    )

    await me()

    expect(seen).toBeNull()
  })

  it('is discarded on a 401, so a doomed retry is impossible', async () => {
    setToken('an-expired-token')
    server.use(
      http.get('*/api/me', () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })),
    )

    await expect(me()).rejects.toBeInstanceOf(ApiError)
    expect(getToken()).toBeNull()
  })
})
