// API client for the GSD SPA. Same-origin by default; override with VITE_API_BASE_URL.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''
// Without this a black-holing connection (captive portal, dropped VPN) leaves the
// promise unsettled forever, so the UI can never report success or failure.
const REQUEST_TIMEOUT_MS = 10_000
const TIMEOUT_STATUS = 408
const TOKEN_KEY = 'gsd_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
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
      signal: options.signal ?? AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    })
  } catch (err) {
    if (err instanceof DOMException && err.name === 'TimeoutError') {
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
  createdAt: string
  updatedAt: string
}

export function captureItem(title: string, note?: string): Promise<Item> {
  return request<Item>('/api/items', {
    method: 'POST',
    body: JSON.stringify(note === undefined ? { title } : { title, note }),
  })
}

export function listItems(bucket?: GtdBucket): Promise<Item[]> {
  const query = bucket === undefined ? '' : `?bucket=${encodeURIComponent(bucket)}`

  return request<Item[]>(`/api/items${query}`)
}
