import { useCallback, useEffect, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { emptyTrash, type Item, listItems } from '../api'
import { messageFor } from '../apiMessage'
import { BucketNav } from './BucketNav'
import { bucketLabel, isGtdBucket } from './buckets'
import { InboxList } from './InboxList'

/**
 * One screen for any of the seven non-Inbox buckets, reached by URL.
 *
 * Read-only apart from the Trash purge: you do not clarify from a destination, because
 * re-filing an already-bucketed item is FR-010 and parked for v2. That is why InboxList is
 * rendered WITHOUT onClarify here.
 */
export function BucketPage() {
  const { bucket } = useParams()
  const [items, setItems] = useState<Item[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [purgeError, setPurgeError] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [purging, setPurging] = useState(false)

  const valid = isGtdBucket(bucket)

  useEffect(() => {
    if (!valid) {
      return
    }
    let ignore = false

    listItems(bucket)
      .then((fromServer) => {
        if (!ignore) {
          setItems(fromServer)
        }
      })
      .catch((e: unknown) => {
        if (!ignore) {
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
  }, [bucket, valid])

  const purge = useCallback(async () => {
    setPurgeError(null)
    setPurging(true)
    try {
      await emptyTrash()
      setItems([])
      setConfirming(false)
    } catch (err) {
      // The list stays exactly as it was — a failed purge must not look like a successful one.
      setPurgeError(messageFor(err))
    } finally {
      setPurging(false)
    }
  }, [])

  // A typo in the URL must not render as an empty bucket — that reads as "you have nothing
  // here", which is a different and alarming claim.
  if (!valid) {
    return <Navigate to="/" replace />
  }

  return (
    <main
      style={{
        fontFamily: 'system-ui, sans-serif',
        padding: '2rem',
        maxWidth: 640,
        margin: '0 auto',
      }}
    >
      <h1>{bucketLabel(bucket)}</h1>
      <BucketNav current={bucket} />

      {loadError !== null && (
        <p style={{ color: 'var(--error)' }}>Could not load this bucket: {loadError}</p>
      )}
      {loadError === null && loading && <p>Loading…</p>}
      {loadError === null && !loading && (
        <InboxList items={items} emptyMessage={`Nothing in ${bucketLabel(bucket)}.`} />
      )}

      {/* The purge lives HERE and only here. A delete affordance on every bucket would be a
          generic remove verb, which is exactly what this slice is shaped to avoid. */}
      {bucket === 'trash' && items.length > 0 && (
        <section aria-label="Empty the Trash" style={{ marginTop: '1.5rem' }}>
          {!confirming && (
            <button type="button" onClick={() => setConfirming(true)}>
              Empty the Trash
            </button>
          )}
          {confirming && (
            <>
              <p aria-label="Discard confirmation" role="alert" style={{ color: 'var(--error)' }}>
                Permanently discard {items.length}{' '}
                {items.length === 1 ? 'item' : 'items'}? This cannot be undone.
              </p>
              <button type="button" disabled={purging} onClick={() => void purge()}>
                {purging ? 'Discarding…' : 'Yes, discard them'}
              </button>
              <button type="button" disabled={purging} onClick={() => setConfirming(false)}>
                Keep them
              </button>
            </>
          )}
        </section>
      )}
      {/* Labelled so it stays distinguishable from the confirmation's own alert — two
          unnamed live regions on one screen leave tests matching on message text, which was
          an observation from the S-02 review. */}
      <p aria-label="Trash purge error" role="alert" style={{ color: 'var(--error)' }}>
        {purgeError ?? ''}
      </p>
    </main>
  )
}
