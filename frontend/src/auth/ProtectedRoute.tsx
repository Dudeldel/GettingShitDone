import { Navigate, Outlet } from 'react-router-dom'
import { AppHeader } from '../AppHeader'
import { useAuth } from './context'

/**
 * The guard, and the place the app frame belongs.
 *
 * AppHeader renders HERE rather than inside each page because a `<header>` that descends from
 * `<main>` is not a `banner` landmark — it is generic. Rendered per page, the product name and
 * the sign-out control sat inside the landmark reserved for page-unique content, and the app
 * had no banner at all. As a sibling of each page's `<main>`, it is one.
 */
export function ProtectedRoute() {
  const { isAuthenticated } = useAuth()

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return (
    <>
      <AppHeader />
      <Outlet />
    </>
  )
}
