import { useEffect, useMemo, useState, type ReactNode } from 'react'
import * as api from '../api'
import { AuthContext, type AuthContextValue } from './context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<api.User | null>(null)
  const [sessionExpired, setSessionExpired] = useState(false)
  // Ready immediately when there's no token to rehydrate; otherwise wait for me().
  const [ready, setReady] = useState(() => api.getToken() === null)

  useEffect(() => {
    // A 401 anywhere drops us back to logged-out, and records why. The screen the user was
    // on unmounts in the same render, so any message it sets is discarded — the reason has
    // to live above the router to survive the bounce to /login.
    api.setUnauthorizedHandler(() => {
      setUser(null)
      setSessionExpired(true)
    })

    if (api.getToken() !== null) {
      api
        .me()
        .then(setUser)
        // A 401 already clears the token in api.request(); on transient errors
        // (network/5xx) keep it so a later reload can retry the session.
        .catch(() => undefined)
        .finally(() => setReady(true))
    }
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: user !== null,
      sessionExpired,
      login: async (email, password) => {
        const result = await api.login(email, password)
        api.setToken(result.token)
        setUser(result.user)
        // Signing back in answers the notice, so it must not outlive the login.
        setSessionExpired(false)
      },
      logout: async () => {
        try {
          await api.logout()
        } catch {
          // The finally below clears local state regardless, so a failed server-side
          // revoke must not surface as an unhandled rejection at the call site.
        } finally {
          api.clearToken()
          setUser(null)
        }
      },
    }),
    [user, sessionExpired],
  )

  // Avoid a flash of the login screen while we rehydrate the session from storage.
  //
  // Returning null here meant every reload carrying a stored token showed a blank white
  // document for the length of a round trip — the first thing a returning user saw. The
  // message is delayed rather than immediate, so a fast rehydration does not flash it.
  if (!ready) {
    return (
      <main className="flex min-h-svh items-center justify-center px-4">
        <p className="settle text-sm text-muted">Restoring your session…</p>
      </main>
    )
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
