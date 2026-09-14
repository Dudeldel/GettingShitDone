import { type Item } from '../api'

function capturedAt(iso: string): string {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}

export function InboxList({
  items,
  onClarify,
  onComplete,
  onRefile,
  emptyMessage = 'Your Inbox is empty. Type an idea above to capture it.',
}: {
  items: Item[]
  /** Omitted by callers that only display items — the Inbox is the only screen that clarifies. */
  onClarify?: (item: Item) => void
  /**
   * Passed only where completion means something — the four action buckets. Omitted
   * elsewhere, so the checkbox is absent rather than present-and-doomed-to-422.
   */
  onComplete?: (item: Item, next: boolean) => void
  /** Passed by the bucket views; the Inbox clarifies instead of re-filing. */
  onRefile?: (item: Item) => void
  /**
   * Overridden by the bucket views. The default names the Inbox because this list started
   * as the Inbox's, and a Trash view announcing "Your Inbox is empty" is simply wrong about
   * which list the user is looking at.
   */
  emptyMessage?: string
}) {
  if (items.length === 0) {
    return <p className="text-sm text-muted">{emptyMessage}</p>
  }

  return (
    // Server-ordered, newest first — no client-side re-sort, so the list cannot disagree
    // with what the API considers the order.
    //
    // role="list" is explicit because Preflight removes the bullets, and a bulletless list
    // loses its list semantics in Safari + VoiceOver unless the role is restored.
    <ul role="list" className="mt-2 space-y-2">
      {items.map((item) => (
        <li key={item.id} className="card flex items-start gap-3 px-4 py-3">
          {/* A native checkbox rather than a button: done is a STATE, so this gets the
              keyboard contract and the checked/unchecked announcement for free instead of
              an aria-pressed someone has to remember to maintain. */}
          {onComplete !== undefined && (
            <input
              type="checkbox"
              className="mt-1 size-4 shrink-0 accent-accent"
              checked={item.completedAt !== null}
              aria-label={`Mark "${item.title}" done`}
              onChange={(e) => onComplete(item, e.target.checked)}
            />
          )}
          <div className="min-w-0 grow">
            <div className="text-ink">
              {item.title}
              {/* FR-006: an item done inside the two-minute timer stays in Next Actions
                  rather than disappearing — there is no Done bucket, and vanishing the
                  instant the user finishes something is indistinguishable from losing it.
                  Marked in TEXT, not by styling alone: a strikethrough is not announced. */}
              {item.completedAt !== null && (
                <span className="ml-2 text-sm text-muted">✓ Done</span>
              )}
            </div>
            {item.note !== null && item.note !== '' && (
              <div className="mt-0.5 text-sm whitespace-pre-wrap text-body">{item.note}</div>
            )}
            {/* FR-007: Delegation IS the who/what note — without it the bucket records that
                something is delegated but not whom to chase. Null for the other seven. */}
            {item.delegatedTo !== null && item.delegatedTo !== '' && (
              <div className="mt-0.5 text-sm text-body">Waiting on: {item.delegatedTo}</div>
            )}
            <time dateTime={item.createdAt} className="mt-1 block text-xs text-muted">
              {capturedAt(item.createdAt)}
            </time>
          </div>
          {onClarify !== undefined && (
            <button type="button" className="btn btn-quiet shrink-0" onClick={() => onClarify(item)}>
              Clarify
            </button>
          )}
          {onRefile !== undefined && (
            <button
              type="button"
              className="btn btn-quiet shrink-0"
              aria-label={`Move "${item.title}" to another bucket`}
              onClick={() => onRefile(item)}
            >
              Move
            </button>
          )}
        </li>
      ))}
    </ul>
  )
}
