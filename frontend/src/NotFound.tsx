import { Link } from 'react-router-dom'

/**
 * Any URL matching no route used to render a blank white document — not a 404 page,
 * literally nothing. A typo in the address bar was indistinguishable from the application
 * having crashed.
 *
 * This sits OUTSIDE ProtectedRoute, so it must say nothing about sessions or accounts: a
 * stranger and the signed-in user see exactly the same page. The way back is a plain link to
 * `/`, which sends an unauthenticated visitor to the login screen through the normal guard
 * rather than by telling them anything here.
 */
export function NotFound() {
  return (
    <main className="mx-auto flex min-h-svh max-w-sm flex-col justify-center px-4 py-8">
      <div className="card p-6">
        <h1>Page not found</h1>
        <p className="mt-2 text-sm text-muted">That address does not match anything here.</p>
        <Link to="/" className="btn btn-primary mt-4 no-underline">
          Go to the Inbox
        </Link>
      </div>
    </main>
  )
}
