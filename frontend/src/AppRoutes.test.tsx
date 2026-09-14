import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { setToken } from './api'
import { AppRoutes } from './AppRoutes'
import { AuthProvider } from './auth/AuthContext'
import { makeItem, server, TEST_USER } from './test/server'

/**
 * The REAL route tree, not a replica.
 *
 * Every other page test mounts its own <Routes> stand-in, which means the actual tree is
 * unverified: deleting the bucket route, or moving it outside ProtectedRoute so every bucket
 * view becomes public, both left the suite fully green. AppRoutes' own docblock says it was
 * extracted "so tests can mount the real composition rather than a replica" — this file is
 * what makes that true.
 */
function renderApp(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </MemoryRouter>,
  )
}

describe('the route tree', () => {
  it('keeps bucket views behind authentication', async () => {
    // No token: ProtectedRoute must send an anonymous visitor to the login screen rather than
    // rendering somebody's GTD lists.
    renderApp('/bucket/reference')

    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: /buckets/i })).not.toBeInTheDocument()
  })

  it('serves a bucket view to a signed-in user', async () => {
    setToken('a-valid-looking-token')
    server.use(
      http.get('*/api/me', () => HttpResponse.json(TEST_USER)),
      http.get('*/api/items', () =>
        HttpResponse.json([makeItem({ id: 1, title: 'a reference item', bucket: 'reference' })]),
      ),
    )

    renderApp('/bucket/reference')

    expect(await screen.findByText('a reference item')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Reference' })).toBeInTheDocument()
  })

  it('keeps the Inbox behind authentication too', async () => {
    renderApp('/')

    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
  })
})

describe('rehydrating a stored session', () => {
  it('holds the screen instead of rendering a blank document', async () => {
    // AuthProvider used to return null while /me was in flight, so every reload carrying a
    // token showed a blank white page for the length of a round trip.
    setToken('a-valid-looking-token')
    let release!: () => void
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    server.use(
      http.get('*/api/me', async () => {
        await gate

        return HttpResponse.json(TEST_USER)
      }),
      http.get('*/api/items', () => HttpResponse.json([])),
    )

    const { container } = renderApp('/')

    expect(container.querySelector('main')).not.toBeNull()
    // And not the login screen either — the user IS signed in, we just do not know it yet.
    expect(screen.queryByRole('heading', { name: /sign in/i })).not.toBeInTheDocument()
    // Withheld at first: a rehydration that answers in 10ms should explain nothing. The
    // message is MOUNTED late rather than faded in, because opacity:0 still leaves text in
    // the accessibility tree — a screen reader would hear what nobody could see.
    expect(screen.queryByText(/restoring your session/i)).not.toBeInTheDocument()

    expect(await screen.findByText(/restoring your session/i)).toBeInTheDocument()

    release()
    expect(await screen.findByRole('heading', { level: 1, name: 'Inbox' })).toBeInTheDocument()
  })
})

describe('an address that matches no route', () => {
  /**
   * Before this route existed the app rendered a blank white document, which is
   * indistinguishable from a crash. The route sits OUTSIDE ProtectedRoute on purpose, so the
   * page it renders must be identical for a stranger and for the signed-in user — moving it
   * inside the guard would bounce a typo'd URL to the login screen and thereby answer
   * "does an account exist here?" for anyone who mistypes.
   */
  it('shows a page rather than nothing, and says nothing about accounts', async () => {
    renderApp('/nie-ma-takiej-strony')

    expect(await screen.findByRole('heading', { name: /page not found/i })).toBeInTheDocument()
    // Not bounced to login, and no hint that signing in is even a thing here.
    expect(screen.queryByRole('heading', { name: /sign in/i })).not.toBeInTheDocument()
    expect(screen.queryByText(/account|session|signed in/i)).not.toBeInTheDocument()
  })

  it('shows the signed-in user exactly the same page', async () => {
    setToken('a-valid-looking-token')
    server.use(http.get('*/api/me', () => HttpResponse.json(TEST_USER)))

    renderApp('/nie-ma-takiej-strony')

    expect(await screen.findByRole('heading', { name: /page not found/i })).toBeInTheDocument()
    // The frame belongs to the guarded tree, so a 404 must not leak that somebody is signed in.
    expect(screen.queryByRole('button', { name: /log out/i })).not.toBeInTheDocument()
  })
})

describe('the app frame', () => {
  it('carries the sign-out control onto every guarded screen, not just the Inbox', async () => {
    // The frame exists because leaving the Inbox used to lose both the product name and the
    // only way out. Asserted on a BUCKET view: that is the screen it was built for, and
    // removing it there left the whole suite green before this test.
    const user = userEvent.setup()
    setToken('a-valid-looking-token')
    server.use(
      http.get('*/api/me', () => HttpResponse.json(TEST_USER)),
      http.get('*/api/items', () => HttpResponse.json([])),
      http.post('*/api/logout', () => HttpResponse.json({})),
    )

    renderApp('/bucket/reference')
    await screen.findByRole('heading', { level: 1, name: 'Reference' })

    await user.click(screen.getByRole('button', { name: /log out/i }))

    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
  })

  it('is a banner landmark, not chrome buried inside the page content', async () => {
    // A <header> that descends from <main> is generic, not a banner. Rendering the frame per
    // page put the product name and the sign-out control inside the landmark reserved for
    // page-unique content, and left the app with no banner at all.
    setToken('a-valid-looking-token')
    server.use(
      http.get('*/api/me', () => HttpResponse.json(TEST_USER)),
      http.get('*/api/items', () => HttpResponse.json([])),
    )

    renderApp('/bucket/reference')
    await screen.findByRole('heading', { level: 1, name: 'Reference' })

    expect(screen.getByRole('banner')).toBeInTheDocument()
  })
})
