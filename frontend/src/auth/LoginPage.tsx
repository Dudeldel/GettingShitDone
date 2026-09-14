import { type FormEvent, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError } from '../api'
import { useAuth } from './context'

export function LoginPage() {
  const { login, sessionExpired } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(email, password)
      navigate('/', { replace: true })
    } catch (err) {
      setError(
        err instanceof ApiError && err.status === 401
          ? 'Invalid email or password.'
          : 'Login failed. Please try again.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="mx-auto flex min-h-svh max-w-sm flex-col justify-center px-4 py-8">
      <div className="card p-6">
        <h1>Sign in</h1>
        {/* Why the user is looking at this screen. A 401 unmounts whatever they were doing
            before its error can paint, so without this the bounce is unexplained. */}
        {sessionExpired && error === null && (
          <p role="status" className="mt-2 text-sm text-muted">
            Your session expired. Sign in again — anything you had typed is still saved.
          </p>
        )}
        <form onSubmit={handleSubmit} className="mt-4">
          {/* The label WRAPS its input rather than using htmlFor/id, and six assertions
              resolve the field through that nesting — so the structure stays as it is. */}
          <label className="mb-3 block text-sm font-medium text-ink">
            Email
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoComplete="username"
              aria-invalid={error !== null}
              className="field mt-1.5 font-normal"
            />
          </label>
          <label className="mb-3 block text-sm font-medium text-ink">
            Password
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              autoComplete="current-password"
              aria-invalid={error !== null}
              className="field mt-1.5 font-normal"
            />
          </label>
          {/* Always mounted and announced. This was the one failure message in the product
              that no screen reader ever heard: conditionally rendered, with no role, and in
              a hardcoded `crimson` that was also the app's only colour failing contrast. */}
          <div className="live-line">
            <p role="alert" className="text-danger">
              {error ?? ''}
            </p>
          </div>
          <button type="submit" disabled={submitting} className="btn btn-primary mt-2 w-full">
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
      </div>
    </main>
  )
}
