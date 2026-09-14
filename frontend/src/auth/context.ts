import { createContext, useContext } from 'react'
import type { User } from '../api'

export interface AuthContextValue {
  user: User | null
  isAuthenticated: boolean
  /**
   * True when the session ended because a request came back 401, rather than because the
   * user logged out. A 401 unmounts whatever screen the user was on before any error can
   * paint, so the explanation has to survive the bounce and surface at the login screen.
   */
  sessionExpired: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (ctx === undefined) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return ctx
}
