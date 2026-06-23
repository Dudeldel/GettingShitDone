import { useEffect, useState } from 'react'
import './App.css'
import { fetchHealth, type HealthStatus } from './api'

function App() {
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
      <h1>Getting Shit Done</h1>
      <p>Walking-skeleton deploy — frontend ↔ API health check.</p>

      {error && <p style={{ color: 'crimson' }}>API unreachable: {error}</p>}
      {!error && !health && <p>Checking API…</p>}
      {health && (
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
