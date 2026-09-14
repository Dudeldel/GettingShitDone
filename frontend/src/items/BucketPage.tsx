import { useCallback, useEffect, useRef, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { completeItem, type Destination, emptyTrash, type Item, listItems } from '../api'
import { messageFor } from '../apiMessage'
import { AttributesDialog } from './AttributesDialog'
import { BucketNav } from './BucketNav'
import { bucketLabel, isActionBucket, isGtdBucket, showsOnCalendar } from './buckets'
import { InboxList } from './InboxList'
import { RefileDialog } from './RefileDialog'

/**
 * One screen for any of the seven non-Inbox buckets, reached by URL.
 *
 * You do not CLARIFY from a destination — clarify is Inbox-only and stays that way, which is
 * why InboxList is rendered without onClarify here. Re-filing and completing are different
 * verbs with their own endpoints, and those this screen does offer.
 */
export function BucketPage() {
  const { bucket } = useParams()
  const [items, setItems] = useState<Item[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [purgeError, setPurgeError] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [purging, setPurging] = useState(false)
  const [discarded, setDiscarded] = useState<number | null>(null)
  const [refiling, setRefiling] = useState<Item | null>(null)
  const [editing, setEditing] = useState<Item | null>(null)
  /** The control that opened the editor, so dismissing it can hand focus back. */
  const editTrigger = useRef<HTMLElement | null>(null)
  const [showCompleted, setShowCompleted] = useState(false)
  const headingRef = useRef<HTMLHeadingElement>(null)
  /** The control that opened the picker, so dismissing it can hand focus back. */
  const refileTrigger = useRef<HTMLElement | null>(null)
  /** Item ids with a completion write in flight — one per row at a time. */
  const [pending, setPending] = useState<ReadonlySet<number>>(new Set())
  const [actionStatus, setActionStatus] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const confirmRef = useRef<HTMLButtonElement>(null)

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

  // Focus the safe button when the confirmation appears: revealing it unmounts the trigger,
  // which otherwise drops focus to <body> and leaves a keyboard user tabbing from the top.
  useEffect(() => {
    if (confirming) {
      confirmRef.current?.focus()
    }
  }, [confirming])

  const handleComplete = useCallback(
    async (item: Item, next: boolean) => {
      // One write per row at a time: this id drives the checkbox's `disabled`, so the second of
      // two rapid toggles never lands. Two overlapping writes would resolve in arrival order,
      // not send order, so the box could settle on the stale answer and disagree with the
      // database until a reload — and the rollback would restore the timestamp the FIRST
      // optimistic write invented rather than the real one.
      setPending((current) => new Set(current).add(item.id))
      setActionError(null)
      const before = item.completedAt
      // Optimistic: a checkbox that waits for a round trip feels broken. The rollback below
      // is what keeps that honest — a failed write must not leave the box ticked.
      setItems((current) =>
        current.map((i) =>
          i.id === item.id ? { ...i, completedAt: next ? new Date().toISOString() : null } : i,
        ),
      )
      try {
        const updated = await completeItem(item.id, next)
        setItems((current) => current.map((i) => (i.id === item.id ? updated : i)))
        // S-03 kept completed items visible because "vanishing is indistinguishable from
        // being lost". They are hidden by default now, so saying where the item went is the
        // compensation for that reversal, not decoration.
        setActionStatus(
          next
            ? `Marked "${item.title}" done.${showCompleted ? '' : ' Turn on Show completed to see it.'}`
            : `"${item.title}" is no longer marked done.`,
        )
        // The row is about to be filtered out from under the checkbox the user is standing on.
        // Same move handleRefiled makes, for the same reason: focus must not fall to <body>.
        if (next && !showCompleted) {
          headingRef.current?.focus()
        }
      } catch (err) {
        setItems((current) =>
          current.map((i) => (i.id === item.id ? { ...i, completedAt: before } : i)),
        )
        setActionStatus(null)
        setActionError(messageFor(err))
      } finally {
        setPending((current) => {
          const rest = new Set(current)
          rest.delete(item.id)

          return rest
        })
      }
    },
    [showCompleted],
  )

  /**
   * Opening and closing the picker have to move focus deliberately, because the picker is a
   * panel the keyboard can otherwise fall out of: dismissing it left focus on <body>, which
   * drops the user at the top of the document and makes them tab back through the whole list
   * to reach the row they started from.
   */
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
    // isConnected, same as cancelRefile: the row can disappear while the editor is open (a
    // completion filtered it out), and focusing a detached node lands silently on <body>.
    if (trigger !== null && trigger.isConnected) {
      trigger.focus()
    } else {
      headingRef.current?.focus()
    }
  }, [])

  const handleSaved = useCallback(
    (updated: Item) => {
      // On Calendar the row's membership is derived, so clearing a date can take it off this
      // screen — the same rule a re-file follows, for the same reason.
      const stays = bucket !== 'calendar' || showsOnCalendar(updated)

      setItems((current) =>
        stays
          ? current.map((i) => (i.id === updated.id ? updated : i))
          : current.filter((i) => i.id !== updated.id),
      )
      setActionError(null)
      setActionStatus(
        stays
          ? `Saved changes to "${updated.title}".`
          : `Saved changes to "${updated.title}". Without a date it is no longer on the calendar.`,
      )
      closeEdit()
    },
    [bucket, closeEdit],
  )

  const openRefile = useCallback((item: Item) => {
    refileTrigger.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
    setRefiling(item)
  }, [])

  const cancelRefile = useCallback(() => {
    setRefiling(null)
    const trigger = refileTrigger.current
    refileTrigger.current = null
    // isConnected, because the row can disappear while the picker is open (a completion
    // filtered it out). Focusing a detached node silently lands on <body> again.
    if (trigger !== null && trigger.isConnected) {
      trigger.focus()
    } else {
      headingRef.current?.focus()
    }
  }, [])

  const handleRefiled = useCallback(
    (moved: Item, destination: Destination) => {
      // On Calendar a row's membership is DERIVED, not the page's bucket: a dated Next Action
      // moved to Projects is still an actionable commitment carrying a date, so it belongs on
      // this screen afterwards. Filtering it out would disagree with the server until a reload.
      // Every other bucket's membership IS the stored column, so a move always removes the row.
      const stays = bucket === 'calendar' && showsOnCalendar(moved)

      setItems((current) =>
        stays
          ? current.map((i) => (i.id === moved.id ? moved : i))
          : current.filter((i) => i.id !== moved.id),
      )
      setRefiling(null)
      const trigger = refileTrigger.current
      refileTrigger.current = null
      setActionError(null)
      setActionStatus(
        stays
          ? `Moved "${moved.title}" to ${bucketLabel(destination)}. It has a date, so it stays here.`
          : `Moved "${moved.title}" to ${bucketLabel(destination)}.`,
      )
      // Same isConnected check cancelRefile makes: when the row stayed its Move button is
      // still mounted and is where focus belongs; when it left, the heading is the nearest
      // thing that still names where the user is.
      if (stays && trigger !== null && trigger.isConnected) {
        trigger.focus()
      } else {
        headingRef.current?.focus()
      }
    },
    [bucket],
  )

  const purge = useCallback(async () => {
    setPurgeError(null)
    setPurging(true)
    try {
      // The server's count, not the client's items.length: the list was fetched on mount and
      // anything that reached the Trash since (a quick-route from another tab, the API direct)
      // makes the local number stale. On an operation with no undo, the number the user is told
      // about afterwards has to be the one that actually happened.
      const { deleted } = await emptyTrash()
      setItems([])
      setDiscarded(deleted)
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

  const canComplete = isActionBucket(bucket)
  // Completed items are out of the default view; the count keeps the empty state honest, so
  // "nothing here" never gets said about a list that only looks empty.
  const hiddenCount = canComplete && !showCompleted
    ? items.filter((item) => item.completedAt !== null).length
    : 0
  const visible = hiddenCount > 0 ? items.filter((item) => item.completedAt === null) : items

  return (
    <main className="mx-auto max-w-2xl px-4 pt-6 pb-10">
      <h1 ref={headingRef} tabIndex={-1} className="mb-2">
        {bucketLabel(bucket)}
      </h1>
      <BucketNav current={bucket} />

      {loadError !== null && (
        <p className="text-sm text-danger">Could not load this bucket: {loadError}</p>
      )}
      {loadError === null && loading && <p className="text-sm text-muted">Loading…</p>}

      {/* Offered only where completing is possible, so the control never implies a state the
          bucket cannot hold. */}
      {loadError === null && !loading && canComplete && (
        <label className="mt-2 flex items-center gap-2 text-sm text-muted">
          <input
            type="checkbox"
            className="size-4 accent-accent"
            checked={showCompleted}
            onChange={(e) => setShowCompleted(e.target.checked)}
          />
          Show completed
        </label>
      )}

      {editing !== null && (
        // key, so switching Edit from one row to another REMOUNTS the panel. Its fields are
        // seeded with useState from the item, which only runs on mount — without this React
        // reuses the instance, the heading updates to the new item while the inputs keep the
        // previous one's values, and saving writes those onto the new item's id.
        <AttributesDialog
          key={editing.id}
          item={editing}
          onSaved={handleSaved}
          onCancel={closeEdit}
        />
      )}

      {refiling !== null && (
        <RefileDialog
          item={refiling}
          // The ITEM's bucket, not the page's. Identical in every stored-membership view, but
          // on Calendar the page's bucket is not where the item lives — passing it would hide
          // Calendar from the destinations (a legal move) and offer the bucket it is already in.
          currentBucket={refiling.bucket}
          onRefiled={handleRefiled}
          onCancel={cancelRefile}
        />
      )}

      {loadError === null && !loading && (
        <InboxList
          items={visible}
          showBucket={bucket === 'calendar'}
          onComplete={canComplete ? handleComplete : undefined}
          // Absent in the Inbox rather than present-and-doomed-to-422, the same rule the
          // checkbox follows: an Inbox item is unclarified, so every destination is refused.
          onRefile={bucket === 'inbox' ? undefined : openRefile}
          // Absent in the Trash: the server refuses an edit there, so offering the control
          // would be present-and-doomed-to-422.
          onEdit={bucket === 'trash' ? undefined : openEdit}
          pendingCompletions={pending}
          emptyMessage={
            hiddenCount > 0
              ? `Nothing left in ${bucketLabel(bucket)} — ${hiddenCount} completed and hidden.`
              : bucket === 'calendar'
                ? 'Nothing on the calendar — items you file here, plus anything actionable with a date.'
                : `Nothing in ${bucketLabel(bucket)}.`
          }
        />
      )}

      {/* Labelled, because this screen already carries two other live regions and an
          unnamed third would leave every lookup matching on message text. */}
      <div className="live-line mt-3">
        <p role="status" aria-label="Item action status" className="text-muted">
          {actionStatus ?? ''}
        </p>
        <p role="alert" aria-label="Item action error" className="text-danger">
          {actionError ?? ''}
        </p>
      </div>

      {/* The purge lives HERE and only here. A delete affordance on every bucket would be a
          generic remove verb, which is exactly what this slice is shaped to avoid. */}
      {bucket === 'trash' && items.length > 0 && (
        <section
          aria-label="Empty the Trash"
          onKeyDown={(e) => {
            if (e.key === 'Escape' && confirming && !purging) {
              setConfirming(false)
              setPurgeError(null)
            }
          }}
          className="mt-6"
        >
          {!confirming && (
            <button type="button" className="btn btn-quiet" onClick={() => setConfirming(true)}>
              Empty the Trash
            </button>
          )}
          {confirming && (
            <div className="card border-danger/40 p-4">
              <p aria-label="Discard confirmation" role="alert" className="text-sm text-danger">
                Permanently discard {items.length}{' '}
                {items.length === 1 ? 'item' : 'items'}? This cannot be undone.
              </p>
              {/* The destructive choice is the one that looks dangerous, and it is NOT the one
                  that takes focus — a keyboard user who hits Enter on reflex must keep their
                  items, not lose them. Filled red rather than red text: with the safe button
                  holding focus, the loud one has to be the one you deliberately reach for. */}
              <button
                type="button"
                className="btn btn-danger mt-3 mr-2"
                disabled={purging}
                onClick={() => void purge()}
              >
                {purging ? 'Discarding…' : 'Yes, discard them'}
              </button>
              <button
                ref={confirmRef}
                type="button"
                className="btn btn-quiet mt-3"
                disabled={purging}
                onClick={() => {
                  setConfirming(false)
                  // F9: a stale failure message must not outlive the attempt it describes.
                  setPurgeError(null)
                }}
              >
                Keep them
              </button>
            </div>
          )}
        </section>
      )}
      {/* Labelled so it stays distinguishable from the confirmation's own alert — two
          unnamed live regions on one screen leave tests matching on message text, which was
          an observation from the S-02 review. The reserved line sits on the wrapper so the
          region itself still renders zero child nodes when empty. */}
      <div className="live-line mt-3">
        <p aria-label="Trash purge error" role="alert" className="text-danger">
          {purgeError ?? ''}
        </p>
        {discarded !== null && (
          <p role="status" className="text-muted">
            Discarded {discarded} {discarded === 1 ? 'item' : 'items'}.
          </p>
        )}
      </div>
    </main>
  )
}
