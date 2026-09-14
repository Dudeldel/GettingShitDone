import { render, screen } from '@testing-library/react'
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
