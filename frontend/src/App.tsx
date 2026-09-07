import { useEffect, useState } from 'react'
import './App.css'
import { listItems, type Item } from './api'
import { useAuth } from './auth/context'
import { CaptureForm } from './items/CaptureForm'
import { InboxList } from './items/InboxList'

function App() {
  const { user, logout } = useAuth()
  const [items, setItems] = useState<Item[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    listItems()
      .then(setItems)
      .catch((e: unknown) => setLoadError(e instanceof Error ? e.message : String(e)))
      .finally(() => setLoading(false))
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

      {/* Prepend the item the POST returned rather than refetching: confirmation then
          costs one round trip, not two (the ~2s capture NFR). */}
      <CaptureForm onCaptured={(item) => setItems((current) => [item, ...current])} />

      <h2>Inbox</h2>
      {loadError !== null && (
        <p style={{ color: 'crimson' }}>Could not load your Inbox: {loadError}</p>
      )}
      {loadError === null && loading && <p>Loading…</p>}
      {loadError === null && !loading && <InboxList items={items} />}
    </main>
  )
}

export default App
