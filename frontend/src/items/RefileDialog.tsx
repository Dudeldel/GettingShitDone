import { useEffect, useRef, useState } from 'react'
import { type Destination, type GtdBucket, type Item, refileItem } from '../api'
import { messageFor } from '../apiMessage'
import { bucketLabel, DESTINATIONS } from './buckets'

/**
 * Pick where an already-clarified item should live instead (FR-010).
 *
 * Deliberately NOT the clarify wizard: the user answered those questions once already, so
 * re-filing is a direct choice. Re-running the tree would be clarifying again, and clarify
 * is Inbox-only by design.
 *
 * The Inbox is absent from the list for the same reason it is absent from the quick-route:
 * items arrive there, they are not filed there. The backend refuses it too.
 */
export function RefileDialog({
  item,
  currentBucket,
  onRefiled,
  onCancel,
}: {
  item: Item
  currentBucket: GtdBucket
  onRefiled: (moved: Item, destination: Destination) => void
  onCancel: () => void
}) {
  const headingRef = useRef<HTMLHeadingElement>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Focus has to enter the panel, or a keyboard user clicks Move and is left on a trigger
  // that just unmounted, tabbing forward blind.
  useEffect(() => {
    headingRef.current?.focus()
  }, [])

  async function send(destination: Destination): Promise<void> {
    setError(null)
    setSubmitting(true)
    try {
      onRefiled(await refileItem(item.id, destination), destination)
    } catch (err) {
      // Through the shared mapper: a raw err.message reads "Failed to fetch".
      setError(messageFor(err))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section
      role="dialog"
      aria-labelledby="refile-heading"
      onKeyDown={(e) => {
        if (e.key === 'Escape' && !submitting) {
          onCancel()
        }
      }}
      className="panel mt-4 p-4"
    >
      <h3 id="refile-heading" ref={headingRef} tabIndex={-1}>
        Move: {item.title}
      </h3>
      <fieldset className="mt-4">
        <legend className="text-sm font-medium text-ink">Where should it go?</legend>
        {DESTINATIONS.filter((destination) => destination !== currentBucket).map((destination) => (
          <button
            key={destination}
            className="btn btn-quiet mt-2 mr-2"
            type="button"
            disabled={submitting}
            onClick={() => void send(destination)}
          >
            {bucketLabel(destination)}
          </button>
        ))}
      </fieldset>

      {/* Always mounted, like every other live region here: adding aria-live at the same
          moment as the text is unreliable across screen readers. Renders zero child nodes
          when empty, which is what toBeEmptyDOMElement() asserts. */}
      <div className="live-line mt-3">
        <p role="alert" aria-label="Move error" className="text-danger">
          {error ?? ''}
        </p>
      </div>

      <button className="btn btn-ghost mt-2" type="button" onClick={onCancel} disabled={submitting}>
        Cancel
      </button>
    </section>
  )
}
