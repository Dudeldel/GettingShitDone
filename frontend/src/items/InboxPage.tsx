import { useEffect, useState } from 'react'
import { type Item, listItems } from '../api'
import { messageFor } from '../apiMessage'
import { useAuth } from '../auth/context'
import { CaptureForm } from './CaptureForm'
import { ClarifyDialog } from './ClarifyDialog'
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
  const [clarifying, setClarifying] = useState<Item | null>(null)

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
          // Through the shared mapper, not e.message: the raw value is "Failed to fetch"
          // or the literal "HTTP 500" — distinguishable, but not something to act on.
          setLoadError(messageFor(e))
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

      {clarifying !== null && (
        <ClarifyDialog
          item={clarifying}
          onCancel={() => setClarifying(null)}
          onClarified={(clarified) => {
            // Remove by id, never by index: the list is a union of server truth and local
            // state (see mergeById), so positions are not stable.
            setItems((current) => current.filter((item) => item.id !== clarified.id))
            setClarifying(null)
          }}
        />
      )}

      <h2>Inbox</h2>
      {loadError !== null && (
        <p style={{ color: 'var(--error)' }}>Could not load your Inbox: {loadError}</p>
      )}
      {/* A capture that already landed must stay visible even while the load is pending. */}
      {loadError === null && loading && items.length === 0 && <p>Loading…</p>}
      {/* Two gates, not one. When the load failed, the list still renders anything already
          captured — otherwise "Saved to your Inbox." sits above an Inbox the user cannot
          see. But only when there IS something: rendering an empty list under an error
          would print "Your Inbox is empty.", telling the user their data is gone when the
          truth is we never managed to ask. A list that failed to load and a list that is
          genuinely empty must never look the same. */}
      {loadError === null
        ? (!loading || items.length > 0) && <InboxList items={items} onClarify={setClarifying} />
        : items.length > 0 && <InboxList items={items} onClarify={setClarifying} />}
    </main>
  )
}
