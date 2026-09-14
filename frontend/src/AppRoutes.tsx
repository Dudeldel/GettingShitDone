import { Route, Routes } from 'react-router-dom'
import { LoginPage } from './auth/LoginPage'
import { ProtectedRoute } from './auth/ProtectedRoute'
import { BucketPage } from './items/BucketPage'
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
        {/* Each bucket gets its own URL, so refresh and bookmarking work — and the Trash
            purge's "only from the Trash view" boundary becomes a fact about the route tree
            rather than a render condition someone can loosen later. */}
        <Route path="/bucket/:bucket" element={<BucketPage />} />
      </Route>
    </Routes>
  )
}
