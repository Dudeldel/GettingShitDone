import { useEffect, useMemo, useState, type ReactNode } from 'react'
import * as api from '../api'
import { AuthContext, type AuthContextValue } from './context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<api.User | null>(null)
  // Ready immediately when there's no token to rehydrate; otherwise wait for me().
  const [ready, setReady] = useState(() => api.getToken() === null)

  useEffect(() => {
    // A 401 anywhere drops us back to logged-out.
    api.setUnauthorizedHandler(() => setUser(null))

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
      login: async (email, password) => {
        const result = await api.login(email, password)
        api.setToken(result.token)
        setUser(result.user)
      },
      logout: async () => {
        try {
          await api.logout()
        } finally {
          api.clearToken()
          setUser(null)
        }
      },
    }),
    [user],
  )

  // Avoid a flash of the login screen while we rehydrate the session from storage.
  if (!ready) {
    return null
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
