import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import { AppRoutes } from '../AppRoutes'
import { setToken } from '../api'
import { AuthProvider } from '../auth/AuthContext'
import { server, TEST_USER } from '../test/server'

/**
 * The 401 path, through the real app shell.
 *
 * This mounts AppRoutes inside AuthProvider — the same composition main.tsx uses — because
 * the behaviour under test only exists there. On a 401 api.ts calls onUnauthorized() before
 * throwing, which flips AuthProvider to logged-out; React batches that with CaptureForm's
 * own setError, so ProtectedRoute swaps <Outlet /> for <Navigate /> in the same render and
 * the form unmounts before any message it set can paint. A test that rendered CaptureForm
 * on its own would pass against the broken code and prove nothing — the same shape as the
 * archived ph2 F2 finding, "a test that cannot fail".
 */
function renderApp() {
  return {
    user: userEvent.setup(),
    ...render(
      <MemoryRouter initialEntries={['/']}>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </MemoryRouter>,
    ),
  }
}

beforeEach(() => {
  // A token present at mount is what gets the user past ProtectedRoute in the first place.
  setToken('a-valid-looking-token')
  server.use(http.get('*/api/me', () => HttpResponse.json(TEST_USER)))
})

describe('a 401 during capture', () => {
  it('explains the expired session instead of bouncing the user silently', async () => {
    const { user } = renderApp()

    const field = await screen.findByLabelText(/catch an idea/i)
    await user.type(field, 'an idea mid-session')

    server.use(
      http.post('*/api/items', () =>
        HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
      ),
    )
    await user.click(screen.getByRole('button', { name: /capture/i }))

    // Landed on the login screen...
    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
    // ...and the user is told why they are there.
    expect(await screen.findByText(/session expired/i)).toBeInTheDocument()
  })

  it('still has the typed idea waiting after signing back in', async () => {
    const { user } = renderApp()

    const field = await screen.findByLabelText(/catch an idea/i)
    await user.type(field, 'the idea that survived')

    server.use(
      http.post('*/api/items', () =>
        HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
      ),
    )
    await user.click(screen.getByRole('button', { name: /capture/i }))
    await screen.findByRole('heading', { name: /sign in/i })

    server.use(
      http.post('*/api/login', () =>
        HttpResponse.json({ token: 'a-fresh-token', user: TEST_USER }),
      ),
    )
    await user.type(screen.getByLabelText(/email/i), TEST_USER.email)
    await user.type(screen.getByLabelText(/password/i), 'correct horse')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    const restored = await screen.findByLabelText(/catch an idea/i)
    await waitFor(() => expect(restored).toHaveValue('the idea that survived'))
  })

  it('drops the expiry notice once the user is back in', async () => {
    const { user } = renderApp()
    await screen.findByLabelText(/catch an idea/i)

    server.use(
      http.post('*/api/items', () =>
        HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
      ),
      http.post('*/api/login', () =>
        HttpResponse.json({ token: 'a-fresh-token', user: TEST_USER }),
      ),
    )
    await user.type(screen.getByLabelText(/catch an idea/i), 'anything')
    await user.click(screen.getByRole('button', { name: /capture/i }))
    await screen.findByText(/session expired/i)

    await user.type(screen.getByLabelText(/email/i), TEST_USER.email)
    await user.type(screen.getByLabelText(/password/i), 'correct horse')
    await user.click(screen.getByRole('button', { name: /sign in/i }))
    await screen.findByLabelText(/catch an idea/i)

    // Returning to /login is what actually asserts the flag was cleared. Checking for the
    // notice straight after login proves nothing: a successful login navigates away and
    // unmounts LoginPage wholesale, so the notice is absent whether the flag was reset,
    // left untouched, or latched true forever — all three were verified to pass.
    await user.click(screen.getByRole('button', { name: /log out/i }))

    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
    expect(screen.queryByText(/session expired/i)).not.toBeInTheDocument()
  })
})

describe('an explicit logout', () => {
  it('keeps the typed idea waiting, and does not claim the session expired', async () => {
    const { user } = renderApp()

    await user.type(await screen.findByLabelText(/catch an idea/i), 'typed then logged out')
    await user.click(screen.getByRole('button', { name: /log out/i }))
    await screen.findByRole('heading', { name: /sign in/i })

    // Leaving voluntarily is not an expired session — the notice would be a lie here.
    expect(screen.queryByText(/session expired/i)).not.toBeInTheDocument()

    server.use(
      http.post('*/api/login', () =>
        HttpResponse.json({ token: 'a-fresh-token', user: TEST_USER }),
      ),
    )
    await user.type(screen.getByLabelText(/email/i), TEST_USER.email)
    await user.type(screen.getByLabelText(/password/i), 'correct horse')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    const restored = await screen.findByLabelText(/catch an idea/i)
    await waitFor(() => expect(restored).toHaveValue('typed then logged out'))
  })
})
