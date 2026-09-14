// API client for the GSD SPA. Same-origin by default; override with VITE_API_BASE_URL.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''
// Without this a black-holing connection (captive portal, dropped VPN) leaves the
// promise unsettled forever, so the UI can never report success or failure.
export const DEFAULT_REQUEST_TIMEOUT_MS = 10_000

// Overridable purely as a test seam: exercising the timeout path against the real ten
// seconds cost ~91% of the frontend suite's wall time on every push, and left only 50%
// headroom before a slow runner would report it as a broken 408 mapping. Production never
// calls the setter.
let requestTimeoutMs = DEFAULT_REQUEST_TIMEOUT_MS

export function setRequestTimeoutForTests(ms: number): void {
  requestTimeoutMs = ms
}
const TIMEOUT_STATUS = 408
const TOKEN_KEY = 'gsd_token'

// Storage access can throw outright, not just return null, when the browser blocks site
// data — Safari's "Block All Cookies", a partitioned third-party context, a full quota.
// getToken() runs inside AuthProvider's useState initializer, i.e. during render and
// outside any try, so an unguarded throw there white-screens the whole app before an error
// boundary could even catch it. Falling back to memory costs the session its survival
// across a reload in those browsers, which is a far better failure than a blank page.
let memoryToken: string | null = null

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return memoryToken
  }
}

export function setToken(token: string): void {
  memoryToken = token
  try {
    localStorage.setItem(TOKEN_KEY, token)
  } catch {
    // The in-memory copy above is the fallback.
  }
}

export function clearToken(): void {
  memoryToken = null
  try {
    localStorage.removeItem(TOKEN_KEY)
  } catch {
    // The in-memory copy above is already cleared.
  }
}

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

// Called when any request gets a 401, so the app can drop to the login screen.
let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }
  const token = getToken()
  if (token !== null) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  let res: Response
  try {
    res = await fetch(`${API_BASE_URL}${path}`, {
      ...options,
      headers,
      signal: options.signal ?? AbortSignal.timeout(requestTimeoutMs),
    })
  } catch (err) {
    // Matched by name rather than `instanceof DOMException`, because the check has to
    // survive a realm boundary: under jsdom the rejection originates in Node's realm while
    // the DOMException global is jsdom's, so instanceof is false and this branch would
    // never fire under test — the timeout path would ship unguarded. A spec TimeoutError
    // always carries this name, so a browser behaves exactly as before.
    if (err instanceof Error && err.name === 'TimeoutError') {
      throw new ApiError(TIMEOUT_STATUS, 'The server did not respond in time.')
    }
    throw err
  }

  if (res.status === 401) {
    clearToken()
    onUnauthorized?.()
    throw new ApiError(401, 'Unauthorized')
  }

  if (!res.ok) {
    let message = `HTTP ${res.status}`
    try {
      const body = (await res.json()) as { message?: string }
      if (typeof body.message === 'string') {
        message = body.message
      }
    } catch {
      // non-JSON error body; keep the status message
    }
    throw new ApiError(res.status, message)
  }

  if (res.status === 204) {
    return undefined as T
  }

  return (await res.json()) as T
}

export interface User {
  id: number
  name: string
  email: string
}

export interface AuthResult {
  token: string
  user: User
}

export function login(email: string, password: string): Promise<AuthResult> {
  return request<AuthResult>('/api/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export function register(
  name: string,
  email: string,
  password: string,
  passwordConfirmation: string,
): Promise<AuthResult> {
  return request<AuthResult>('/api/register', {
    method: 'POST',
    body: JSON.stringify({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    }),
  })
}

export function me(): Promise<User> {
  return request<User>('/api/me')
}

export function logout(): Promise<void> {
  return request<void>('/api/logout', { method: 'POST' })
}

// --- GTD items ---

/** Mirrors App\Domain\Item\GtdBucket — the backend validates against exactly these. */
export type GtdBucket =
  | 'inbox'
  | 'next_actions'
  | 'projects'
  | 'calendar'
  | 'delegation'
  | 'someday_maybe'
  | 'reference'
  | 'trash'

/** Mirrors App\Const\ItemConst::TITLE_MAX_LENGTH. */
export const TITLE_MAX_LENGTH = 255

/** Mirrors App\Const\ClarifyConst::TWO_MINUTE_SECONDS — the GTD two-minute rule (FR-006). */
export const TWO_MINUTE_SECONDS = 120

export interface Item {
  id: number
  title: string
  note: string | null
  bucket: GtdBucket
  // Dormant until S-06 (dates) and S-07 (metadata): the API always returns null today,
  // so the shape stays stable when those slices start filling them.
  dueDate: string | null
  tags: string[] | null
  context: string | null
  important: boolean | null
  urgent: boolean | null
  // Filled by clarify when the item is delegated (FR-007); null for the other seven
  // buckets. FR-007's "done flag" is completedAt, shared with every action bucket.
  delegatedTo: string | null
  // Set only when clarify's two-minute timer ended in "done" (FR-006). There is no Done
  // bucket among the eight, so completion is state: the item sits in Next Actions carrying
  // this. null means "not finished", never "unknown".
  completedAt: string | null
  createdAt: string
  updatedAt: string
}

export function captureItem(title: string, note?: string): Promise<Item> {
  return request<Item>('/api/items', {
    method: 'POST',
    body: JSON.stringify(note === undefined ? { title } : { title, note }),
  })
}

/** Every bucket an item can be filed INTO. The Inbox is where capture arrives, not a target. */
export type Destination = Exclude<GtdBucket, 'inbox'>

/** The three destinations FR-004 offers for a non-actionable item. */
export type NonActionableDestination = Extract<
  GtdBucket,
  'trash' | 'someday_maybe' | 'reference'
>

/** Mirrors App\Domain\Clarify\TwoMinuteOutcome — how a two-minute timer ended (FR-006). */
export type TwoMinuteOutcome = 'done' | 'deferred'

/**
 * The answers the clarify endpoint accepts, as a discriminated union rather than a bag of
 * optional fields — every member here is a complete, terminating path through the GTD tree,
 * so an incomplete answer set cannot be constructed. Mirrors ClarifyItemPayload's two modes:
 * the guided tree, and the FR-002 quick-route.
 *
 * Note what is absent: there is no general `bucket` field on the tree path. The client sends
 * answers; the server derives the destination.
 */
export type ClarifyAnswers =
  | { quickRouteBucket: GtdBucket }
  | { actionable: false; nonActionableDestination: NonActionableDestination }
  | { actionable: true; singleStep: false }
  // FR-006: "< 2 min?" sits between "single step?" and "can it be delegated?". A single-step
  // path without it is no longer representable here — the backend rejects one with a 422, so
  // leaving the old member in would only move that failure from compile time to runtime.
  | {
      actionable: true
      singleStep: true
      twoMinutes: true
      twoMinuteOutcome: 'done'
      twoMinuteLoops: number
    }
  // A deferred timer does not file the item: it rejoins the tree at the last question, so
  // these two carry a delegation answer as well.
  | {
      actionable: true
      singleStep: true
      twoMinutes: true
      twoMinuteOutcome: 'deferred'
      twoMinuteLoops: number
      delegable: false
    }
  | {
      actionable: true
      singleStep: true
      twoMinutes: true
      twoMinuteOutcome: 'deferred'
      twoMinuteLoops: number
      delegable: true
      delegatedTo: string
    }
  | { actionable: true; singleStep: true; twoMinutes: false; delegable: false }
  | { actionable: true; singleStep: true; twoMinutes: false; delegable: true; delegatedTo: string }

export function clarifyItem(id: number, answers: ClarifyAnswers): Promise<Item> {
  return request<Item>(`/api/items/${id}/clarify`, {
    method: 'POST',
    body: JSON.stringify(answers),
  })
}

/**
 * Move an already-clarified item to a different destination (FR-010).
 *
 * A destination is named here, unlike clarify where the server derives it — that is the
 * difference between the two operations, not an inconsistency. The Inbox is not among the
 * legal values; the backend refuses it with a 422.
 */
export function refileItem(id: number, bucket: Destination): Promise<Item> {
  return request<Item>(`/api/items/${id}/refile`, {
    method: 'POST',
    body: JSON.stringify({ bucket }),
  })
}

/**
 * Mark an item done, or un-mark it. A toggle, not two endpoints: the product has exactly one
 * irreversible operation and it is guarded by a confirmation.
 */
export function completeItem(id: number, completed: boolean): Promise<Item> {
  return request<Item>(`/api/items/${id}/complete`, {
    method: 'POST',
    body: JSON.stringify({ completed }),
  })
}

/**
 * Permanently discard everything in the Trash. No item id anywhere: this is scoped to the
 * bucket by design, so it cannot become a generic delete (see the S-05 plan).
 */
export function emptyTrash(): Promise<{ deleted: number }> {
  return request<{ deleted: number }>('/api/trash', { method: 'DELETE' })
}

export function listItems(bucket?: GtdBucket): Promise<Item[]> {
  const query = bucket === undefined ? '' : `?bucket=${encodeURIComponent(bucket)}`

  return request<Item[]>(`/api/items${query}`)
}
