import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { server } from '../test/server'
import { AuthProvider } from './AuthContext'
import { LoginPage } from './LoginPage'

/**
 * The login screen had no test file at all until the visual-design pass gave its error a
 * `role`, wired `aria-describedby`, and bound the input border to `aria-invalid` — which
 * made losing any of them a silent VISUAL regression as well as an accessibility one.
 */
function renderLogin() {
  return {
    user: userEvent.setup(),
    ...render(
      <MemoryRouter>
        <AuthProvider>
          <LoginPage />
        </AuthProvider>
      </MemoryRouter>,
    ),
  }
}

async function signIn(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/email/i), 'jakub@example.com')
  await user.type(screen.getByLabelText(/password/i), 'whatever')
  await user.click(screen.getByRole('button', { name: /sign in/i }))
}

describe('the login screen announces its failures', () => {
  it('mounts its live region before it has anything to say', () => {
    renderLogin()

    // Mounted-then-filled, like every other live region in the app: adding aria-live at the
    // same moment as the text is unreliable across screen readers (ph3 F6a).
    const alert = screen.getByRole('alert')
    expect(alert).toBeEmptyDOMElement()
    expect(alert).toHaveAttribute('id', 'login-error')
  })

  it('points both fields at the explanation', () => {
    renderLogin()

    for (const field of [/email/i, /password/i]) {
      expect(screen.getByLabelText(field)).toHaveAttribute('aria-describedby', 'login-error')
    }
  })

  it('flags the fields invalid when the credentials are what was wrong', async () => {
    server.use(
      http.post('*/api/login', () =>
        HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
      ),
    )
    const { user } = renderLogin()

    await signIn(user)
    await screen.findByText(/invalid email or password/i)

    expect(screen.getByLabelText(/email/i)).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText(/password/i)).toHaveAttribute('aria-invalid', 'true')
  })

  it('does NOT flag them when the failure was not theirs', async () => {
    // A 500 or a dropped connection says nothing about what the user typed. Announcing both
    // fields as "invalid entry" — and painting them red — for a server fault is a lie the
    // user cannot act on.
    server.use(http.post('*/api/login', () => HttpResponse.error()))
    const { user } = renderLogin()

    await signIn(user)
    await screen.findByText(/login failed/i)

    expect(screen.getByLabelText(/email/i)).toHaveAttribute('aria-invalid', 'false')
    expect(screen.getByLabelText(/password/i)).toHaveAttribute('aria-invalid', 'false')
  })
})
