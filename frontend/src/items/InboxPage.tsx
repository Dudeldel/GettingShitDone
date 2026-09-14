import { useCallback, useEffect, useRef, useState } from 'react'
import { type Item, listItems } from '../api'
import { messageFor } from '../apiMessage'
import { AttributesDialog } from './AttributesDialog'
import { BucketNav } from './BucketNav'
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
  const [items, setItems] = useState<Item[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [clarifying, setClarifying] = useState<Item | null>(null)
  const [editing, setEditing] = useState<Item | null>(null)
  /** The control that opened the editor, so dismissing it can hand focus back. */
  const editTrigger = useRef<HTMLElement | null>(null)

  const openEdit = useCallback((item: Item) => {
    editTrigger.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
    setEditing(item)
  }, [])

  const closeEdit = useCallback(() => {
    setEditing(null)
    const trigger = editTrigger.current
    editTrigger.current = null
    // isConnected: the row can leave the Inbox while the editor is open (it was clarified in
    // another tab, or the list reloaded), and focusing a detached node lands on <body>.
    if (trigger !== null && trigger.isConnected) {
      trigger.focus()
    }
  }, [])

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
    <main className="mx-auto max-w-2xl px-4 pt-6 pb-10">
      {/* Prepend the item the POST returned rather than refetching: confirmation then
          costs one round trip, not two (the ~2s capture NFR). */}
      <CaptureForm onCaptured={(item) => setItems((current) => [item, ...current])} />

      {editing !== null && (
        <AttributesDialog
          item={editing}
          onSaved={(updated) => {
            setItems((current) => current.map((i) => (i.id === updated.id ? updated : i)))
            closeEdit()
          }}
          onCancel={closeEdit}
        />
      )}

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

      <BucketNav current="inbox" />

      {/* The screen's own name. The product name moved into the shared frame, so every
          screen now has exactly one h1 and it says where you are. */}
      <h1 className="mb-2">Inbox</h1>
      {loadError !== null && (
        <p className="text-sm text-danger">Could not load your Inbox: {loadError}</p>
      )}
      {/* A capture that already landed must stay visible even while the load is pending. */}
      {loadError === null && loading && items.length === 0 && (
        <p className="text-sm text-muted">Loading…</p>
      )}
      {/* Two gates, not one. When the load failed, the list still renders anything already
          captured — otherwise "Saved to your Inbox." sits above an Inbox the user cannot
          see. But only when there IS something: rendering an empty list under an error
          would print "Your Inbox is empty.", telling the user their data is gone when the
          truth is we never managed to ask. A list that failed to load and a list that is
          genuinely empty must never look the same. */}
      {loadError === null
        ? (!loading || items.length > 0) && (
            <InboxList items={items} onClarify={setClarifying} onEdit={openEdit} />
          )
        : items.length > 0 && (
            <InboxList items={items} onClarify={setClarifying} onEdit={openEdit} />
          )}
    </main>
  )
}
