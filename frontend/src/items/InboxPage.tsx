import { useEffect, useState } from 'react'
import { type Item, listItems } from '../api'
import { useAuth } from '../auth/context'
import { CaptureForm } from './CaptureForm'
import { InboxList } from './InboxList'

/**
 * The initial GET cannot contain an item captured after it was issued, so replacing the
 * array would erase a capture that landed while the list was still loading — the UI would
 * then claim the Inbox is empty while the row sits in the database. Merge instead: local
 * entries the server has not seen are newer than everything it returned, so they lead.
 */
function mergeById(fromServer: Item[], local: Item[]): Item[] {
  const known = new Set(fromServer.map((item) => item.id))

  return [...local.filter((item) => !known.has(item.id)), ...fromServer]
}

export function InboxPage() {
  const { user, logout } = useAuth()
  const [items, setItems] = useState<Item[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let ignore = false

    listItems()
      .then((fromServer) => {
        if (!ignore) {
          setItems((local) => mergeById(fromServer, local))
        }
      })
      .catch((e: unknown) => {
        if (!ignore) {
          setLoadError(e instanceof Error ? e.message : String(e))
        }
      })
      .finally(() => {
        if (!ignore) {
          setLoading(false)
        }
      })

    return () => {
      ignore = true
    }
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
        <p style={{ color: 'var(--error)' }}>Could not load your Inbox: {loadError}</p>
      )}
      {/* A capture that already landed must stay visible even while the load is pending. */}
      {loadError === null && loading && items.length === 0 && <p>Loading…</p>}
      {loadError === null && (!loading || items.length > 0) && <InboxList items={items} />}
    </main>
  )
}
