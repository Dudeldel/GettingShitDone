import { Route, Routes } from 'react-router-dom'
import { LoginPage } from './auth/LoginPage'
import { ProtectedRoute } from './auth/ProtectedRoute'
import { InboxPage } from './items/InboxPage'

/**
 * The route tree, extracted from main.tsx so tests can mount the real composition rather
 * than a replica. That matters for the 401 path specifically: the defect only reproduces
 * when ProtectedRoute can actually swap <Outlet /> for <Navigate />, which needs the whole
 * tree — a test rendering CaptureForm alone would pass against broken code.
 */
export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/" element={<InboxPage />} />
      </Route>
    </Routes>
  )
}
