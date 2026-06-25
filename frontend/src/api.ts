// API client for the GSD SPA. Same-origin by default; override with VITE_API_BASE_URL.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''
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

  const res = await fetch(`${API_BASE_URL}${path}`, { ...options, headers })

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

// --- Health check (preserved from the walking skeleton; App.tsx depends on these) ---

export interface HealthStatus {
  status: string
  app: string
  environment: string
  time: string
}

export function fetchHealth(): Promise<HealthStatus> {
  return request<HealthStatus>('/api/health')
}
