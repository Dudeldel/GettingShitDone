import { useEffect, useState } from 'react'
import './App.css'
import { fetchHealth, type HealthStatus } from './api'
import { useAuth } from './auth/context'

function App() {
  const { user, logout } = useAuth()
  const [health, setHealth] = useState<HealthStatus | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchHealth()
      .then(setHealth)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])

  return (
    <main
      style={{
        fontFamily: 'system-ui, sans-serif',
        padding: '2rem',
        maxWidth: 640,
        margin: '0 auto',
      }}
    >
      <header
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'baseline',
        }}
      >
        <h1>Getting Shit Done</h1>
        <span>
          {user !== null && <>Signed in as {user.email} · </>}
          <button type="button" onClick={() => void logout()}>
            Log out
          </button>
        </span>
      </header>

      <p>Authenticated. API health check below.</p>

      {error !== null && <p style={{ color: 'crimson' }}>API unreachable: {error}</p>}
      {error === null && health === null && <p>Checking API…</p>}
      {health !== null && (
        <dl>
          <dt>Status</dt>
          <dd>{health.status}</dd>
          <dt>App</dt>
          <dd>{health.app}</dd>
          <dt>Environment</dt>
          <dd>{health.environment}</dd>
          <dt>Server time</dt>
          <dd>{health.time}</dd>
        </dl>
      )}
    </main>
  )
}

export default App
