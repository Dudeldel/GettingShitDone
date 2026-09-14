import { type Item } from '../api'

function capturedAt(iso: string): string {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}

export function InboxList({
  items,
  onClarify,
  emptyMessage = 'Your Inbox is empty. Type an idea above to capture it.',
}: {
  items: Item[]
  /** Omitted by callers that only display items — bucket views (S-05) will not clarify. */
  onClarify?: (item: Item) => void
  /**
   * Overridden by the bucket views. The default names the Inbox because this list started
   * as the Inbox's, and a Trash view announcing "Your Inbox is empty" is simply wrong about
   * which list the user is looking at.
   */
  emptyMessage?: string
}) {
  if (items.length === 0) {
    return <p style={{ color: 'var(--muted)' }}>{emptyMessage}</p>
  }

  return (
    // Server-ordered, newest first — no client-side re-sort, so the list cannot disagree
    // with what the API considers the order.
    <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
      {items.map((item) => (
        <li key={item.id} style={{ borderTop: '1px solid var(--border)', padding: '0.6rem 0' }}>
          <div style={{ color: 'var(--text-h)' }}>
            {item.title}
            {/* FR-006: an item done inside the two-minute timer stays in Next Actions rather
                than disappearing — there is no Done bucket, and vanishing the instant the
                user finishes something is indistinguishable from losing it. Marked in TEXT,
                not by styling alone: a strikethrough is not announced. */}
            {item.completedAt !== null && (
              <span style={{ color: 'var(--muted)', marginLeft: '0.5rem', fontSize: '0.85em' }}>
                ✓ Done
              </span>
            )}
          </div>
          {item.note !== null && item.note !== '' && (
            <div style={{ color: 'var(--text)', fontSize: '0.9em', whiteSpace: 'pre-wrap' }}>
              {item.note}
            </div>
          )}
          {/* FR-007: Delegation IS the who/what note — without it the bucket records that
              something is delegated but not whom to chase. Null for the other seven buckets. */}
          {item.delegatedTo !== null && item.delegatedTo !== '' && (
            <div style={{ color: 'var(--text)', fontSize: '0.9em' }}>
              Waiting on: {item.delegatedTo}
            </div>
          )}
          <time dateTime={item.createdAt} style={{ color: 'var(--muted)', fontSize: '0.8em' }}>
            {capturedAt(item.createdAt)}
          </time>
          {onClarify !== undefined && (
            <button
              type="button"
              onClick={() => onClarify(item)}
              style={{ marginLeft: '0.75rem' }}
            >
              Clarify
            </button>
          )}
        </li>
      ))}
    </ul>
  )
}
